<?php

namespace App\Services\Accounting;

use App\Enums\ApprovalType;
use App\Enums\PaymentPurpose;
use App\Enums\VendorPaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Audit\Auditor;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Accounts payable (FMS §3): vendor bills, payment requests with finance approval hierarchy,
 * simulated payout and GL posting. A vendor is only marked paid once the provider confirms.
 */
class VendorPaymentService
{
    public function __construct(private GeneralLedger $gl, private ApprovalEngine $approvals, private PaymentOrchestrator $payments) {}

    public function createBill(Vendor $vendor, array $data, User $maker): VendorBill
    {
        if (! $vendor->isActive()) {
            throw new DomainException('Bills can only be recorded for active vendors.');
        }
        $amount = Money::of($data['amount'] ?? 0);
        if (! Money::isPositive($amount)) {
            throw new DomainException('Bill amount must be greater than zero.');
        }
        $whtRate = (string) ($data['wht_rate'] ?? TenantSettings::get('finance', $vendor->kind === 'asset' ? 'wht_rate_vendor_goods' : 'wht_rate_services'));
        $wht = Money::pct($amount, $whtRate);
        $expense = isset($data['expense_account_id']) ? Account::where('type', 'expense')->findOrFail($data['expense_account_id']) : null;

        return DB::transaction(function () use ($vendor, $data, $maker, $amount, $wht, $expense) {
            $bill = VendorBill::create([
                'bill_no' => Numbering::next('vendor_bill'), 'vendor_id' => $vendor->id, 'kind' => $data['kind'] ?? 'operational',
                'vendor_invoice_ref' => $data['vendor_invoice_ref'] ?? null, 'bill_date' => $data['bill_date'] ?? today(), 'due_date' => $data['due_date'] ?? today()->addDays(30),
                'amount' => $amount, 'wht_amount' => $wht, 'expense_account_id' => $expense?->id, 'cost_center_id' => $data['cost_center_id'] ?? null,
                'description' => $data['description'] ?? null, 'status' => 'open', 'created_by' => $maker->id,
            ]);
            $jv = $this->gl->postEvent('vendor_bill', $bill, ['gross' => $amount, 'payable' => Money::sub($amount, $wht), 'wht' => $wht],
                "Vendor bill {$bill->bill_no} – {$vendor->business_name}", $bill->bill_date, ['vendor_id' => $vendor->id, 'cost_center_id' => $bill->cost_center_id],
                $expense ? ['gross' => $expense->id] : [], module: 'ap');
            $bill->update(['journal_voucher_id' => $jv->id]);
            Auditor::record('ap.bill_created', $bill, [], ['amount' => $amount, 'wht' => $wht]);

            return $bill;
        });
    }

    /** Maker raises a payment request; it is submitted to the finance approval hierarchy. */
    public function request(Vendor $vendor, array $data, User $maker): VendorPayment
    {
        if (! $vendor->isActive()) {
            throw new DomainException('Payments can only be made to active vendors.');
        }
        if (! $vendor->iban && ($data['method'] ?? 'raast') !== 'cheque') {
            throw new DomainException('Vendor has no bank account (IBAN) on file.');
        }
        $bill = isset($data['vendor_bill_id']) ? VendorBill::where('vendor_id', $vendor->id)->findOrFail($data['vendor_bill_id']) : null;
        $isAdvance = (bool) ($data['is_advance'] ?? false);
        if (! $bill && ! $isAdvance) {
            throw new DomainException('Select an open bill to pay, or mark the payment as an advance.');
        }
        $amount = Money::of($data['amount'] ?? ($bill ? Money::sub(Money::sub($bill->amount, $bill->wht_amount), $bill->paid_amount) : 0));
        if (! Money::isPositive($amount)) {
            throw new DomainException('Payment amount must be greater than zero.');
        }
        if ($bill) {
            $open = Money::sub(Money::sub($bill->amount, $bill->wht_amount), $this->committedOnBill($bill));
            if (Money::gt($amount, $open)) {
                throw new DomainException('Amount exceeds the unpaid balance of the bill ('.Money::format($open).').');
            }
        }

        return DB::transaction(function () use ($vendor, $data, $maker, $bill, $isAdvance, $amount) {
            $p = new VendorPayment([
                'payment_no' => Numbering::next('vendor_payment'), 'vendor_id' => $vendor->id, 'vendor_bill_id' => $bill?->id, 'is_advance' => $isAdvance,
                'amount' => $amount, 'wht_amount' => '0.00', 'net_amount' => $amount, 'method' => $data['method'] ?? $vendor->preferred_payment_method,
                'bank_account_id' => $data['bank_account_id'] ?? BankAccount::where('purpose', 'operating')->value('id'),
                'scenario' => $data['scenario'] ?? null, 'idempotency_key' => 'vpr:'.($data['idempotency_key'] ?? uniqid('', true)),
                'purpose' => $data['purpose'] ?? ($bill ? 'Payment of '.$bill->bill_no : 'Advance to vendor'), 'created_by' => $maker->id,
            ]);
            $p->forceFill(['status' => VendorPaymentStatus::Draft])->save();
            Auditor::record('ap.payment_requested', $p, [], ['amount' => $amount, 'vendor' => $vendor->business_name]);
            if (TenantSettings::get('finance', 'vendor_payment_requires_approval', true)) {
                $p->forceFill(['status' => VendorPaymentStatus::PendingApproval])->save();
                $this->approvals->submit($p, ApprovalType::VendorPayment, (string) $amount, $maker);
            } else {
                $p->forceFill(['status' => VendorPaymentStatus::Approved])->save();
            }

            return $p;
        });
    }

    /** Approved → send to provider. Executor must differ from the maker (SoD). */
    public function execute(VendorPayment $p, User $actor, ?string $scenario = null): VendorPayment
    {
        if (! $actor->hasPermission('fms.payables.execute')) {
            throw new DomainException('You are not authorized to release vendor payments.');
        }
        if ((int) $p->created_by === (int) $actor->id) {
            throw new DomainException('Segregation of duties: you cannot release a payment you requested.');
        }

        $p = DB::transaction(function () use ($p, $scenario) {
            $p = VendorPayment::whereKey($p->id)->lockForUpdate()->first();
            if ($p->status !== VendorPaymentStatus::Approved && $p->status !== VendorPaymentStatus::Failed) {
                throw new DomainException('Only approved (or previously failed) payments can be released.');
            }
            if ($p->status === VendorPaymentStatus::Failed) {
                $p->status->assertCanTransitionTo(VendorPaymentStatus::Approved);
                $p->idempotency_key = $p->idempotency_key.':retry'.$p->updated_at->timestamp;
            }
            $p->scenario = $scenario ?? $p->scenario;
            $p->forceFill(['status' => VendorPaymentStatus::Processing, 'failure_reason' => null])->save();

            return $p;
        });

        $provider = match ($p->method) { 'cheque' => 'sponsor_bank', 'bank_transfer' => 'sponsor_bank', default => 'raast' };
        $order = $this->payments->initiate(PaymentPurpose::VendorPayment, $provider, (string) $p->net_amount, $p, 'po:'.$p->idempotency_key,
            ['name' => $p->vendor->account_title ?? $p->vendor->business_name, 'account' => $p->vendor->iban, 'bank' => $p->vendor->bank_name], $p->scenario);
        $p->refresh();
        if (! $p->payment_order_id) {
            $p->forceFill(['payment_order_id' => $order->id])->save();
        }

        return $p->fresh();
    }

    public function markPaid(VendorPayment $p): void
    {
        $p = VendorPayment::whereKey($p->id)->lockForUpdate()->first();
        if ($p->status === VendorPaymentStatus::Paid) {
            return;
        }
        $p->status->assertCanTransitionTo(VendorPaymentStatus::Paid, 'Vendor payment');
        $event = $p->is_advance ? 'vendor_advance' : 'vendor_payment';
        $jv = $this->gl->postEvent($event, $p, ['amount' => $p->net_amount], "Vendor payment {$p->payment_no} – {$p->vendor->business_name}",
            dimensions: ['vendor_id' => $p->vendor_id], module: 'ap');
        $p->forceFill(['status' => VendorPaymentStatus::Paid, 'paid_at' => now(), 'journal_voucher_id' => $jv->id])->save();
        if ($p->bill) {
            $bill = $p->bill;
            $bill->paid_amount = Money::add($bill->paid_amount, $p->net_amount);
            $bill->status = Money::gte($bill->paid_amount, Money::sub($bill->amount, $bill->wht_amount)) ? 'paid' : 'partially_paid';
            $bill->save();
        }
        Auditor::record('ap.payment_paid', $p, [], ['amount' => $p->net_amount, 'voucher' => $jv->voucher_no]);
    }

    public function markFailed(VendorPayment $p, string $reason): void
    {
        $p = VendorPayment::whereKey($p->id)->lockForUpdate()->first();
        if ($p->status !== VendorPaymentStatus::Processing) {
            return;
        }
        $p->forceFill(['status' => VendorPaymentStatus::Failed, 'failure_reason' => $reason])->save();
        Auditor::record('ap.payment_failed', $p, [], [], $reason);
    }

    public function markReversed(VendorPayment $p, string $reason): void
    {
        $p = VendorPayment::whereKey($p->id)->lockForUpdate()->first();
        if ($p->status !== VendorPaymentStatus::Paid) {
            $this->markFailed($p, $reason);

            return;
        }
        $p->forceFill(['status' => VendorPaymentStatus::Reversed, 'failure_reason' => $reason])->save();
        if ($jv = JournalVoucher::find($p->journal_voucher_id)) {
            $this->gl->reverse($jv, $reason);
        }
        if ($p->bill) {
            $p->bill->update(['paid_amount' => Money::sub($p->bill->paid_amount, $p->net_amount), 'status' => 'open']);
        }
        Auditor::record('ap.payment_reversed', $p, [], [], $reason);
    }

    private function committedOnBill(VendorBill $bill): string
    {
        return Money::of(VendorPayment::where('vendor_bill_id', $bill->id)
            ->whereIn('status', ['pending_approval', 'approved', 'processing', 'paid'])->sum('net_amount'));
    }

    /** AP aging buckets per vendor (FMS §3.3). */
    public function aging(): array
    {
        $rows = [];
        foreach (VendorBill::with('vendor')->whereIn('status', ['open', 'partially_paid'])->get() as $b) {
            $open = Money::sub(Money::sub($b->amount, $b->wht_amount), $b->paid_amount);
            $days = $b->due_date->isPast() ? (int) $b->due_date->diffInDays(today()) : 0;
            $bucket = $days === 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
            $key = $b->vendor_id;
            $rows[$key] ??= ['vendor' => $b->vendor->business_name, 'current' => '0.00', '1_30' => '0.00', '31_60' => '0.00', '61_90' => '0.00', 'over_90' => '0.00', 'total' => '0.00'];
            $rows[$key][$bucket] = Money::add($rows[$key][$bucket], $open);
            $rows[$key]['total'] = Money::add($rows[$key]['total'], $open);
        }

        return array_values($rows);
    }
}
