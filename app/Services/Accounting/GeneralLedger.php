<?php

namespace App\Services\Accounting;

use App\Enums\JournalStatus;
use App\Exceptions\DomainException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalLine;
use App\Models\JournalVoucher;
use App\Models\PostingRule;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Support\Numbering;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way journal vouchers are created and posted.
 *
 * - System postings come from business events via tenant posting rules and are auto-posted, idempotently.
 * - Manual vouchers follow draft → pending_approval → posted (maker-checker; see JournalApprovalHandler).
 * - Posted vouchers are immutable; reversal creates a linked, mirror-image voucher.
 * - Nothing may be posted into a closed accounting period.
 */
class GeneralLedger
{
    /**
     * Post a business event using the tenant's posting rules.
     *
     * @param  array<string,string|int|float>  $amounts  amount key => value (zero/absent keys are skipped)
     * @param  array<string,int>  $accountOverrides  "line:N" or amount key => account id (e.g. a bill's expense account)
     * @param  array  $dimensions  branch_id, cost_center_id, customer_id, vendor_id, fund_id applied to all lines
     */
    public function postEvent(
        string $event,
        Model $source,
        array $amounts,
        string $narration,
        ?CarbonInterface $date = null,
        array $dimensions = [],
        array $accountOverrides = [],
        ?string $idempotencyKey = null,
        string $module = 'fms',
    ): JournalVoucher {
        $tenantId = TenantContext::id() ?? throw new \RuntimeException('Ledger posting requires tenant context.');
        $idempotencyKey ??= $event.':'.$source->getMorphClass().':'.$source->getKey();

        if ($existing = JournalVoucher::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing; // duplicate event (retry / duplicate callback): never post twice
        }

        $rules = PostingRule::with('account')->where('event_type', $event)->where('is_active', true)->orderBy('line_no')->get();
        if ($rules->isEmpty()) {
            throw new DomainException("No posting rules are configured for event [{$event}]. Configure them under Finance › Posting Rules.");
        }

        $lines = [];
        foreach ($rules as $rule) {
            $amount = Money::of($amounts[$rule->amount_key] ?? 0);
            if (Money::isZero($amount)) {
                continue;
            }
            if (Money::lt($amount, 0)) {
                throw new DomainException("Negative amount for key [{$rule->amount_key}] in event [{$event}].");
            }
            $lines[] = array_merge($dimensions, [
                'account_id' => $accountOverrides['line:'.$rule->line_no] ?? $accountOverrides[$rule->amount_key] ?? $rule->account_id,
                'debit' => $rule->side === 'debit' ? $amount : '0.00',
                'credit' => $rule->side === 'credit' ? $amount : '0.00',
                'description' => $rule->description,
            ]);
        }

        $date ??= now();

        return DB::transaction(fn () => $this->persist([
            'voucher_date' => $date->toDateString(),
            'origin' => 'system',
            'source_module' => $module,
            'event_type' => $event,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'narration' => $narration,
            'branch_id' => $dimensions['branch_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_by' => auth()->id(),
        ], $lines, post: true, tenantId: $tenantId));
    }

    /** Create a manual draft voucher (maker). */
    public function createManual(array $header, array $lines, User $maker): JournalVoucher
    {
        $lines = $this->normaliseLines($lines);
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($header, $lines, $maker) {
            $jv = $this->persist(array_merge($header, [
                'origin' => 'manual',
                'source_module' => 'fms',
                'created_by' => $maker->id,
            ]), $lines, post: false, tenantId: TenantContext::id());
            Auditor::record('journal.created', $jv, [], ['voucher_no' => $jv->voucher_no, 'total' => $jv->total_debit]);

            return $jv;
        });
    }

    /** Replace lines of a draft voucher. */
    public function updateDraft(JournalVoucher $jv, array $header, array $lines): JournalVoucher
    {
        if (! in_array($jv->status, [JournalStatus::Draft, JournalStatus::Rejected], true)) {
            throw new DomainException('Only draft or rejected vouchers can be edited.');
        }
        $lines = $this->normaliseLines($lines);
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($jv, $header, $lines) {
            $jv->fill($header);
            $jv->lines()->delete();
            foreach ($lines as $line) {
                $jv->lines()->create($line + ['tenant_id' => $jv->tenant_id]);
            }
            $jv->total_debit = Money::add(...array_column($lines, 'debit'));
            $jv->total_credit = Money::add(...array_column($lines, 'credit'));
            $jv->forceFill(['status' => JournalStatus::Draft])->save();

            return $jv;
        });
    }

    /** Post a voucher (checker step for manual vouchers). */
    public function post(JournalVoucher $jv, ?User $poster = null): JournalVoucher
    {
        return DB::transaction(function () use ($jv, $poster) {
            $jv = JournalVoucher::whereKey($jv->id)->lockForUpdate()->firstOrFail();
            if ($jv->status === JournalStatus::Posted) {
                return $jv;
            }
            $jv->status->assertCanTransitionTo(JournalStatus::Posted, 'Journal voucher');
            if ($jv->origin === 'manual' && $poster && (int) $jv->created_by === (int) $poster->id) {
                throw new DomainException('Segregation of duties: you cannot post a voucher you created.');
            }
            $this->assertBalanced($jv->lines->map(fn ($l) => ['debit' => $l->debit, 'credit' => $l->credit])->all());
            $period = $this->openPeriodFor($jv->voucher_date);
            $jv->forceFill([
                'status' => JournalStatus::Posted,
                'accounting_period_id' => $period->id,
                'posted_by' => $poster?->id,
                'posted_at' => now(),
            ])->save();
            Auditor::record('journal.posted', $jv, [], ['voucher_no' => $jv->voucher_no, 'total' => $jv->total_debit]);

            return $jv;
        });
    }

    /** Reverse a posted voucher with a linked mirror-image voucher dated $date (default today). */
    public function reverse(JournalVoucher $jv, string $reason, ?User $actor = null, ?CarbonInterface $date = null): JournalVoucher
    {
        return DB::transaction(function () use ($jv, $reason, $actor, $date) {
            $jv = JournalVoucher::whereKey($jv->id)->lockForUpdate()->firstOrFail();
            if ($jv->status === JournalStatus::Reversed) {
                return $jv->reversedBy()->firstOrFail();
            }
            if ($jv->status !== JournalStatus::Posted) {
                throw new DomainException('Only posted vouchers can be reversed.');
            }
            $lines = $jv->lines->map(fn (JournalLine $l) => [
                'account_id' => $l->account_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'description' => 'Reversal: '.($l->description ?? ''),
                'cost_center_id' => $l->cost_center_id,
                'branch_id' => $l->branch_id,
                'customer_id' => $l->customer_id,
                'vendor_id' => $l->vendor_id,
                'fund_id' => $l->fund_id,
            ])->all();

            $reversal = $this->persist([
                'voucher_date' => ($date ?? now())->toDateString(),
                'origin' => $jv->origin,
                'source_module' => $jv->source_module,
                'event_type' => $jv->event_type ? $jv->event_type.'_reversal' : null,
                'source_type' => $jv->source_type,
                'source_id' => $jv->source_id,
                'narration' => 'Reversal of '.$jv->voucher_no.': '.$reason,
                'branch_id' => $jv->branch_id,
                'reversal_of_id' => $jv->id,
                'idempotency_key' => 'reversal:'.$jv->id,
                'created_by' => $actor?->id ?? auth()->id(),
            ], $lines, post: true, tenantId: $jv->tenant_id);

            $jv->forceFill(['status' => JournalStatus::Reversed])->save();
            Auditor::record('journal.reversed', $jv, [], ['reversal_voucher' => $reversal->voucher_no], $reason);

            return $reversal;
        });
    }

    public function openPeriodFor(CarbonInterface|string $date): AccountingPeriod
    {
        $date = is_string($date) ? $date : $date->toDateString();
        $period = AccountingPeriod::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
        if (! $period) {
            throw new DomainException("No accounting period is defined for {$date}. Create the fiscal year first.");
        }
        if ($period->status !== 'open') {
            throw new DomainException("Accounting period {$period->name} is closed. Posting into a closed period is not permitted.");
        }

        return $period;
    }

    public function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new DomainException('A journal voucher needs at least two lines.');
        }
        $dr = Money::add(...array_map(fn ($l) => $l['debit'] ?? 0, $lines));
        $cr = Money::add(...array_map(fn ($l) => $l['credit'] ?? 0, $lines));
        if (! Money::eq($dr, $cr)) {
            throw new DomainException('Journal is not balanced: debits '.Money::format($dr).' ≠ credits '.Money::format($cr).'.');
        }
        if (Money::isZero($dr)) {
            throw new DomainException('Journal total cannot be zero.');
        }
        foreach ($lines as $l) {
            if (Money::isPositive($l['debit'] ?? 0) && Money::isPositive($l['credit'] ?? 0)) {
                throw new DomainException('A line cannot carry both a debit and a credit.');
            }
        }
    }

    private function normaliseLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $debit = Money::of($l['debit'] ?? 0);
            $credit = Money::of($l['credit'] ?? 0);
            if (Money::isZero($debit) && Money::isZero($credit)) {
                continue;
            }
            if (Money::lt($debit, 0) || Money::lt($credit, 0)) {
                throw new DomainException('Journal line amounts cannot be negative.');
            }
            $account = Account::find($l['account_id'] ?? 0); // tenant-scoped lookup
            if (! $account || ! $account->is_postable || ! $account->is_active) {
                throw new DomainException('Each line needs an active, postable account from this tenant\'s chart.');
            }
            $out[] = [
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $l['description'] ?? null,
                'cost_center_id' => $l['cost_center_id'] ?? null,
                'branch_id' => $l['branch_id'] ?? null,
                'customer_id' => $l['customer_id'] ?? null,
                'vendor_id' => $l['vendor_id'] ?? null,
                'fund_id' => $l['fund_id'] ?? null,
            ];
        }

        return $out;
    }

    private function persist(array $header, array $lines, bool $post, ?int $tenantId): JournalVoucher
    {
        $this->assertBalanced($lines);
        $period = $post ? $this->openPeriodFor($header['voucher_date']) : null;

        $jv = new JournalVoucher(array_merge($header, [
            'voucher_no' => Numbering::next('journal', $tenantId),
            'total_debit' => Money::add(...array_column($lines, 'debit')),
            'total_credit' => Money::add(...array_column($lines, 'credit')),
            'accounting_period_id' => $period?->id,
        ]));
        $jv->forceFill([
            'status' => $post ? JournalStatus::Posted : JournalStatus::Draft,
            'posted_at' => $post ? now() : null,
            'posted_by' => $post ? ($header['created_by'] ?? null) : null,
        ]);
        $jv->save();

        foreach ($lines as $line) {
            $jv->lines()->create(array_intersect_key($line, array_flip([
                'account_id', 'debit', 'credit', 'description', 'cost_center_id', 'branch_id', 'customer_id', 'vendor_id', 'fund_id',
            ])));
        }

        return $jv;
    }
}
