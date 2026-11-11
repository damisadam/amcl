<?php

namespace App\Services\Accounting;

use App\Enums\ApprovalType;
use App\Enums\ReconciliationStatus;
use App\Exceptions\DomainException;
use App\Models\BankAccount;
use App\Models\PaymentOrder;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Models\SettlementRecord;
use App\Models\SuspenseItem;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Bank / Raast settlement reconciliation (FMS §2.4, §5.2). Internal records are successful or reversed
 * payment orders on the bank account; external records are (simulated) settlement statement lines.
 * Auto-match on provider reference + direction + amount; everything else becomes an exception item.
 */
class ReconciliationService
{
    public function __construct(private ApprovalEngine $approvals, private GeneralLedger $gl) {}

    public function run(BankAccount $bank, string $from, string $to, User $actor): ReconciliationBatch
    {
        return DB::transaction(function () use ($bank, $from, $to, $actor) {
            $batch = new ReconciliationBatch(['reference' => Numbering::next('reconciliation'), 'bank_account_id' => $bank->id,
                'period_start' => $from, 'period_end' => $to, 'created_by' => $actor->id]);
            $batch->forceFill(['status' => ReconciliationStatus::Draft])->save();

            $orders = PaymentOrder::where('bank_account_id', $bank->id)->whereIn('status', ['succeeded', 'reversed'])
                ->where('reconciliation_status', '!=', 'matched')->whereBetween(DB::raw('DATE(COALESCE(settled_at, updated_at))'), [$from, $to])->get();
            $lines = SettlementRecord::where('bank_account_id', $bank->id)->where('status', 'unmatched')->whereBetween('value_date', [$from, $to])->get();

            $byRef = $lines->groupBy('provider_ref');
            $usedLines = [];
            $internal = $external = '0.00';
            foreach ($orders as $o) {
                $expectDir = $o->direction === 'inbound' ? 'credit' : 'debit';
                $candidates = ($byRef[$o->provider_ref] ?? collect())->where('direction', $expectDir)->reject(fn ($l) => in_array($l->id, $usedLines));
                $internal = Money::add($internal, $o->amount);
                if ($candidates->count() > 1) {
                    foreach ($candidates as $c) {
                        $usedLines[] = $c->id;
                        $this->item($batch, $o, $c, 'duplicate_reference');
                        $external = Money::add($external, $c->amount);
                    }

                    continue;
                }
                $line = $candidates->first();
                if (! $line) {
                    $this->item($batch, $o, null, 'missing_external');

                    continue;
                }
                $usedLines[] = $line->id;
                $external = Money::add($external, $line->amount);
                if (Money::eq($line->amount, $o->amount)) {
                    $this->item($batch, $o, $line, 'matched');
                } else {
                    $this->item($batch, $o, $line, 'amount_mismatch');
                }
            }
            foreach ($lines->reject(fn ($l) => in_array($l->id, $usedLines)) as $l) {
                $external = Money::add($external, $l->amount);
                $this->item($batch, null, $l, 'missing_internal');
            }
            $this->refreshTotals($batch, $internal, $external);
            $batch->forceFill(['status' => ReconciliationStatus::InReview])->save();
            Auditor::record('recon.batch_run', $batch, [], ['matched' => $batch->matched_count, 'exceptions' => $batch->exception_count, 'difference' => $batch->difference]);

            return $batch;
        });
    }

    private function item(ReconciliationBatch $batch, ?PaymentOrder $o, ?SettlementRecord $l, string $result): ReconciliationItem
    {
        $item = $batch->items()->create([
            'payment_order_id' => $o?->id, 'settlement_record_id' => $l?->id, 'internal_amount' => $o?->amount, 'external_amount' => $l?->amount, 'result' => $result,
        ]);
        if ($result === 'matched') {
            $o->forceFill(['reconciliation_status' => 'matched'])->save();
            $l->update(['status' => 'matched']);
        } elseif ($o) {
            $o->forceFill(['reconciliation_status' => 'exception'])->save();
        }

        return $item;
    }

    private function refreshTotals(ReconciliationBatch $batch, ?string $internal = null, ?string $external = null): void
    {
        $items = $batch->items()->get();
        $batch->matched_count = $items->whereIn('result', ['matched', 'manual_match'])->count();
        $batch->exception_count = $items->filter(fn ($i) => ! in_array($i->result, ['matched', 'manual_match'], true) && ! $i->resolution)->count();
        if ($internal !== null) {
            $batch->internal_total = $internal;
            $batch->external_total = $external;
        }
        $batch->difference = Money::sub($batch->internal_total, $batch->external_total);
        $batch->save();
    }

    /** Manually pair an unmatched internal item with an unmatched external item. */
    public function manualMatch(ReconciliationItem $internal, ReconciliationItem $external, User $actor, string $notes): void
    {
        if (! $actor->hasPermission('fms.reconciliation.perform')) {
            throw new DomainException('Not authorized.');
        }
        if ($internal->reconciliation_batch_id !== $external->reconciliation_batch_id || ! $internal->payment_order_id || ! $external->settlement_record_id
            || $internal->result !== 'missing_external' || $external->result !== 'missing_internal') {
            throw new DomainException('Select one unmatched internal record and one unmatched statement line from the same batch.');
        }
        if (! Money::eq($internal->internal_amount, $external->external_amount)) {
            throw new DomainException('Amounts differ; use the amount-mismatch resolution instead.');
        }
        DB::transaction(function () use ($internal, $external, $actor, $notes) {
            $internal->update(['settlement_record_id' => $external->settlement_record_id, 'external_amount' => $external->external_amount,
                'result' => 'manual_match', 'resolution' => 'manual_match', 'resolved_by' => $actor->id, 'notes' => $notes]);
            $external->update(['resolution' => 'manual_match', 'resolved_by' => $actor->id, 'notes' => 'Paired with item #'.$internal->id]);
            $internal->paymentOrder->forceFill(['reconciliation_status' => 'matched'])->save();
            $external->settlementRecord->update(['status' => 'matched']);
            $this->refreshTotals($internal->batch);
            Auditor::record('recon.manual_match', $internal->batch, [], ['internal' => $internal->id, 'external' => $external->id], $notes);
        });
    }

    /** Unidentified receipt on the statement → suspense account (FMS §2.5). */
    public function moveToSuspense(ReconciliationItem $item, User $actor, string $notes): SuspenseItem
    {
        if ($item->result !== 'missing_internal' || $item->resolution) {
            throw new DomainException('Only unresolved statement lines without an internal record can be moved to suspense.');
        }
        $line = $item->settlementRecord;
        if ($line->direction !== 'credit') {
            throw new DomainException('Only unidentified receipts (credits) are parked in suspense; investigate unexplained debits with the bank.');
        }

        return DB::transaction(function () use ($item, $line, $actor, $notes) {
            $s = SuspenseItem::create(['reference' => Numbering::next('suspense'), 'settlement_record_id' => $line->id, 'amount' => $line->amount,
                'reason' => $notes ?: 'Unidentified receipt '.$line->provider_ref]);
            $bankAccountId = $item->batch->bankAccount->account_id;
            $jv = $this->gl->postEvent('suspense_receipt', $s, ['amount' => $line->amount], "Unidentified receipt {$line->provider_ref} parked in suspense",
                $line->value_date, accountOverrides: ['line:1' => $bankAccountId], module: 'treasury');
            $s->update(['journal_voucher_id' => $jv->id]);
            $line->update(['status' => 'suspense']);
            $item->update(['resolution' => 'suspense', 'resolved_by' => $actor->id, 'notes' => $notes]);
            $this->refreshTotals($item->batch);
            Auditor::record('recon.moved_to_suspense', $item->batch, [], ['suspense' => $s->reference, 'amount' => $s->amount], $notes);

            return $s;
        });
    }

    /** Record an investigated explanation for an exception (timing difference, bank error raised, etc.). */
    public function annotate(ReconciliationItem $item, User $actor, string $resolution, string $notes): void
    {
        if (! in_array($resolution, ['timing_difference', 'bank_query_raised', 'written_back', 'ignored'], true) || blank($notes)) {
            throw new DomainException('Choose a resolution and add notes.');
        }
        $item->update(['resolution' => $resolution, 'resolved_by' => $actor->id, 'notes' => $notes]);
        $this->refreshTotals($item->batch);
        Auditor::record('recon.exception_annotated', $item->batch, [], ['item' => $item->id, 'resolution' => $resolution], $notes);
    }

    public function submit(ReconciliationBatch $batch, User $actor): ReconciliationBatch
    {
        if ($batch->status !== ReconciliationStatus::InReview) {
            throw new DomainException('Only batches in review can be submitted.');
        }
        if ($batch->exception_count > 0) {
            throw new DomainException("Resolve or annotate all {$batch->exception_count} exception(s) before submitting.");
        }
        if (TenantSettings::get('finance', 'reconciliation_requires_approval', true)) {
            $batch->forceFill(['status' => ReconciliationStatus::PendingApproval])->save();
            $this->approvals->submit($batch, ApprovalType::Reconciliation, (string) $batch->internal_total, $actor);
        } else {
            $this->approve($batch, $actor);
        }

        return $batch;
    }

    public function approve(ReconciliationBatch $batch, User $actor): void
    {
        $batch->forceFill(['status' => ReconciliationStatus::Approved, 'approved_by' => $actor->id, 'approved_at' => now()])->save();
        Auditor::record('recon.approved', $batch);
    }
}
