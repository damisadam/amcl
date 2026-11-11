<?php

namespace App\Services\Lending;

use App\Enums\InstalmentStatus;
use App\Enums\LoanAccountStatus;
use App\Enums\LoanPaymentStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentPurpose;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\DomainException;
use App\Models\Instalment;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use App\Models\LoanPayment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedger;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Support\Numbering;
use App\Services\Wallet\WalletLedger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Instalment collection and allocation (DFS §4.2–4.7, LMS §6.7/§8).
 *
 * Allocation rule (deterministic, documented in docs/WORKFLOWS.md): oldest instalment first; within an
 * instalment, components in the product version's allocation order (default late_charge → charges →
 * profit → principal). Any excess beyond the total outstanding is held as a customer advance.
 *
 * Instalment paid amounts are always recomputed from non-reversed allocations, so reversals restore
 * the exact prior position without editing history.
 */
class RepaymentService
{
    public const EXTERNAL_CHANNELS = ['raast', 'qr_iban', 'one_bill', 'easypaisa', 'jazzcash'];

    public const MANUAL_CHANNELS = ['cash', 'bank_transfer', 'cheque', 'salary_deduction'];

    public function __construct(
        private GeneralLedger $gl,
        private PaymentOrchestrator $payments,
        private Notifier $notifier,
    ) {}

    /** Customer-initiated collection through an external rail; settles when the provider confirms. */
    public function initiateCollection(LoanAccount $loan, string $amount, string $channel, string $idempotencyKey, ?User $actor = null, ?string $scenario = null): LoanPayment
    {
        if (! in_array($channel, self::EXTERNAL_CHANNELS, true)) {
            throw new DomainException('Unsupported payment channel.');
        }
        $amount = Money::of($amount);
        $this->assertPayable($loan, $amount);

        $payment = DB::transaction(function () use ($loan, $amount, $channel, $idempotencyKey, $actor) {
            if ($existing = LoanPayment::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $p = new LoanPayment([
                'reference' => Numbering::next('loan_payment'), 'loan_account_id' => $loan->id, 'customer_id' => $loan->customer_id,
                'amount' => $amount, 'channel' => $channel, 'idempotency_key' => $idempotencyKey, 'recorded_by' => $actor?->id,
            ]);
            $p->forceFill(['status' => LoanPaymentStatus::Pending])->save();

            return $p;
        });

        if ($payment->wasRecentlyCreated) {
            $provider = match ($channel) { 'qr_iban' => 'raast', default => $channel };
            $order = $this->payments->initiate(PaymentPurpose::InstalmentCollection, $provider, $amount, $payment, 'po:'.$idempotencyKey,
                ['name' => $loan->customer->full_name, 'account' => $loan->account_no, 'bank' => $channel === 'one_bill' ? '1Bill' : null], $scenario,
                ['channel' => $channel]);
            $payment->forceFill(['payment_order_id' => $order->id])->save();
        }

        return $payment->fresh();
    }

    /** Authorized staff record a payment received outside the platform (cash, cheque, transfer, salary deduction). */
    public function recordManual(LoanAccount $loan, string $amount, string $channel, string $externalRef, User $actor, ?string $receivedOn = null): LoanPayment
    {
        if (! $actor->hasPermission('repayments.record')) {
            throw new DomainException('You are not authorized to record repayments.');
        }
        if (! in_array($channel, self::MANUAL_CHANNELS, true)) {
            throw new DomainException('Manual repayments must be cash, bank transfer, cheque or salary deduction.');
        }
        if (blank($externalRef)) {
            throw new DomainException('A receipt / bank reference is required.');
        }

        return $this->recordSettled($loan, $amount, $channel, 'manual:'.$loan->id.':'.$channel.':'.$externalRef, externalRef: $externalRef, actor: $actor, receivedAt: $receivedOn);
    }

    /** A payment already settled (wallet debit done, or manual receipt): allocate + post immediately. */
    public function recordSettled(LoanAccount $loan, string $amount, string $channel, string $idempotencyKey, ?string $externalRef = null,
        ?WalletTransaction $walletTransaction = null, ?User $actor = null, ?string $receivedAt = null): LoanPayment
    {
        $amount = Money::of($amount);

        return DB::transaction(function () use ($loan, $amount, $channel, $idempotencyKey, $externalRef, $walletTransaction, $actor, $receivedAt) {
            if ($existing = LoanPayment::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $this->assertPayable($loan, $amount);
            $p = new LoanPayment([
                'reference' => Numbering::next('loan_payment'), 'loan_account_id' => $loan->id, 'customer_id' => $loan->customer_id,
                'amount' => $amount, 'channel' => $channel, 'idempotency_key' => $idempotencyKey, 'external_ref' => $externalRef,
                'wallet_transaction_id' => $walletTransaction?->id, 'recorded_by' => $actor?->id ?? auth()->id(),
                'received_at' => $receivedAt ? \Carbon\Carbon::parse($receivedAt) : now(),
            ]);
            $p->forceFill(['status' => LoanPaymentStatus::Pending])->save();
            $this->settle($p);

            return $p->fresh();
        });
    }

    /** Provider confirmed an external collection. */
    public function settlePending(LoanPayment $payment): void
    {
        $payment = LoanPayment::whereKey($payment->id)->lockForUpdate()->first();
        if ($payment->status === LoanPaymentStatus::Succeeded) {
            return;
        }
        $payment->received_at ??= now();
        $this->settle($payment);
    }

    public function failPending(LoanPayment $payment, string $reason): void
    {
        $payment = LoanPayment::whereKey($payment->id)->lockForUpdate()->first();
        if ($payment->status !== LoanPaymentStatus::Pending) {
            return;
        }
        $payment->forceFill(['status' => LoanPaymentStatus::Failed, 'failure_reason' => $reason])->save();
        Auditor::record('repayment.failed', $payment, [], ['amount' => $payment->amount, 'channel' => $payment->channel->value], $reason);
        DB::afterCommit(fn () => $this->notifier->toCustomer($payment->customer, 'payment_failed', [
            'title' => 'Payment failed', 'body' => 'Your payment of '.Money::format($payment->amount).' for '.$payment->loanAccount->account_no.' failed: '.$reason,
        ]));
    }

    private function settle(LoanPayment $payment): void
    {
        $loan = LoanAccount::whereKey($payment->loan_account_id)->lockForUpdate()->first();
        $payment->status->assertCanTransitionTo(LoanPaymentStatus::Succeeded, 'Repayment');
        $order = $loan->terms_snapshot['allocation_order'] ?? ['late_charge', 'charges', 'profit', 'principal'];

        $remaining = Money::of($payment->amount);
        $totals = ['late_charge' => '0.00', 'charges' => '0.00', 'profit' => '0.00', 'principal' => '0.00'];
        $allocations = [];
        $instalments = Instalment::where('loan_account_id', $loan->id)->whereIn('status', ['pending', 'due', 'partially_paid', 'overdue'])
            ->orderBy('number')->lockForUpdate()->get();

        foreach ($instalments as $inst) {
            foreach ($order as $component) {
                if (! Money::isPositive($remaining)) {
                    break 2;
                }
                $due = Money::sub($inst->{$this->dueCol($component)}, $inst->{$this->paidCol($component)});
                if (! Money::isPositive($due)) {
                    continue;
                }
                $take = Money::min($due, $remaining);
                $allocations[] = ['instalment_id' => $inst->id, 'component' => $component, 'amount' => $take];
                $totals[$component] = Money::add($totals[$component], $take);
                $remaining = Money::sub($remaining, $take);
            }
        }

        foreach ($allocations as $a) {
            PaymentAllocation::create($a + ['loan_payment_id' => $payment->id]);
        }
        $payment->forceFill([
            'status' => LoanPaymentStatus::Succeeded,
            'receipt_no' => Numbering::next('receipt'),
            'allocation' => $totals,
            'unallocated_amount' => $remaining,
        ])->save();

        $this->recalculate($loan);

        $event = $payment->channel === PaymentChannel::Wallet ? 'loan_repayment_wallet' : 'loan_repayment_bank';
        $this->gl->postEvent($event, $payment, [
            'amount' => $payment->amount, 'receivable' => Money::add($totals['principal'], $totals['profit'], $totals['charges']),
            'profit' => $totals['profit'], 'charges' => $totals['charges'], 'late_charge' => $totals['late_charge'], 'unallocated' => $remaining,
        ], "Repayment {$payment->reference} on {$loan->account_no} via {$payment->channel->label()}", $payment->received_at,
            ['branch_id' => $loan->branch_id, 'customer_id' => $loan->customer_id, 'fund_id' => $loan->fund_id], module: 'lms');

        Auditor::record('repayment.succeeded', $payment, [], ['amount' => $payment->amount, 'allocation' => $totals, 'unallocated' => $remaining, 'channel' => $payment->channel->value]);
        DB::afterCommit(function () use ($payment, $loan) {
            $loan->refresh();
            $this->notifier->toCustomer($payment->customer, 'payment_received', [
                'title' => 'Payment received', 'body' => 'We received '.Money::format($payment->amount).' for '.$loan->account_no.'. Receipt '.$payment->receipt_no.'. Outstanding: '.Money::format($loan->totalOutstanding()).'.',
                'url' => '/app/financing/'.$loan->id,
            ]);
            if ($loan->status === LoanAccountStatus::Closed) {
                $this->notifier->toCustomer($payment->customer, 'loan_closed', ['title' => 'Financing fully repaid', 'body' => 'Congratulations! '.$loan->account_no.' is fully repaid. Your NOC will be issued shortly.']);
            }
        });
    }

    /**
     * Reverse a successful repayment through compensating records: allocations become void, balances
     * are recomputed, the GL voucher is reversed and wallet-funded payments are credited back.
     */
    public function reverse(LoanPayment $payment, string $reason, ?User $actor, bool $system = false): LoanPayment
    {
        if (! $system) {
            if (! $actor?->hasPermission('repayments.reverse')) {
                throw new DomainException('You are not authorized to reverse repayments.');
            }
            if ((int) $payment->recorded_by === (int) $actor->id) {
                throw new DomainException('Segregation of duties: you cannot reverse a repayment you recorded.');
            }
        }
        if (blank($reason)) {
            throw new DomainException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = LoanPayment::whereKey($payment->id)->lockForUpdate()->first();
            if ($payment->status === LoanPaymentStatus::Reversed) {
                return $payment;
            }
            if ($payment->status === LoanPaymentStatus::Pending) {
                $this->failPending($payment, $reason);

                return $payment->fresh();
            }
            $payment->status->assertCanTransitionTo(LoanPaymentStatus::Reversed, 'Repayment');
            $loan = LoanAccount::whereKey($payment->loan_account_id)->lockForUpdate()->first();
            if ($loan->status === LoanAccountStatus::NocIssued) {
                throw new DomainException('A NOC has already been issued for this financing; reversal requires a manual adjustment.');
            }
            $payment->forceFill(['status' => LoanPaymentStatus::Reversed, 'reversal_reason' => $reason])->save();
            if ($loan->status === LoanAccountStatus::Closed) {
                $loan->forceFill(['status' => LoanAccountStatus::Active, 'closed_at' => null])->save();
                self::lifecycleEvent($loan, 'Payment '.$payment->reference.' reversed – financing reopened.');
            }
            $this->recalculate($loan);

            $jv = JournalVoucher::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)->where('status', 'posted')->first();
            if ($jv) {
                $this->gl->reverse($jv, 'Repayment reversal: '.$reason, $actor);
            }
            if ($payment->wallet_transaction_id) {
                $this->refundToWallet($payment, $reason, $actor);
            }
            Auditor::record('repayment.reversed', $payment, ['status' => 'succeeded'], ['status' => 'reversed'], $reason);

            return $payment;
        });
    }

    private function refundToWallet(LoanPayment $payment, string $reason, ?User $actor): void
    {
        $original = WalletTransaction::find($payment->wallet_transaction_id);
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->lock($original->wallet_id);
        $txn = new WalletTransaction([
            'reference' => Numbering::next('wallet_txn'), 'wallet_id' => $wallet->id, 'type' => WalletTransactionType::Reversal, 'direction' => 'credit',
            'amount' => $original->amount, 'idempotency_key' => 'repay-rev:'.$payment->id, 'related_transaction_id' => $original->id,
            'description' => 'Reversal of instalment payment '.$payment->reference.': '.$reason, 'initiated_by' => $actor?->id,
        ]);
        $txn->forceFill(['status' => WalletTransactionStatus::Pending])->save();
        $ledger->credit($wallet, $txn);
        $txn->forceFill(['status' => WalletTransactionStatus::Succeeded, 'completed_at' => now()])->save();
        $original->forceFill(['status' => WalletTransactionStatus::Reversed, 'related_transaction_id' => $txn->id])->save();
    }

    /** Recompute instalment paid columns/statuses and loan outstanding from successful allocations. */
    public function recalculate(LoanAccount $loan): void
    {
        $sums = PaymentAllocation::query()
            ->join('loan_payments', 'loan_payments.id', '=', 'payment_allocations.loan_payment_id')
            ->where('loan_payments.loan_account_id', $loan->id)
            ->where('loan_payments.status', LoanPaymentStatus::Succeeded->value)
            ->groupBy('payment_allocations.instalment_id', 'payment_allocations.component')
            ->selectRaw('payment_allocations.instalment_id, payment_allocations.component, SUM(payment_allocations.amount) as total')
            ->get()->groupBy('instalment_id');

        $lastPaidAt = LoanPayment::where('loan_account_id', $loan->id)->where('status', 'succeeded')->max('received_at');
        foreach (Instalment::where('loan_account_id', $loan->id)->orderBy('number')->get() as $inst) {
            $c = ($sums[$inst->id] ?? collect())->keyBy('component');
            $inst->principal_paid = Money::of($c['principal']->total ?? 0);
            $inst->profit_paid = Money::of($c['profit']->total ?? 0);
            $inst->charges_paid = Money::of($c['charges']->total ?? 0);
            $inst->late_charge_paid = Money::of($c['late_charge']->total ?? 0);
            $inst->total_paid = Money::add($inst->principal_paid, $inst->profit_paid, $inst->charges_paid, $inst->late_charge_paid);
            $owed = Money::add($inst->total_due, $inst->late_charge_due);
            $inst->status = match (true) {
                Money::gte($inst->total_paid, $owed) => InstalmentStatus::Paid,
                $inst->due_date->lt(today()) => InstalmentStatus::Overdue,
                Money::isPositive($inst->total_paid) => InstalmentStatus::PartiallyPaid,
                $inst->due_date->isSameDay(today()) => InstalmentStatus::Due,
                default => InstalmentStatus::Pending,
            };
            $inst->paid_at = $inst->status === InstalmentStatus::Paid ? ($inst->paid_at ?? ($lastPaidAt ? \Carbon\Carbon::parse($lastPaidAt) : now())) : null;
            $inst->save();
        }

        $agg = Instalment::where('loan_account_id', $loan->id)->selectRaw('
            SUM(principal_due - principal_paid) as p, SUM(profit_due - profit_paid) as pr, SUM(charges_due - charges_paid) as c,
            SUM(late_charge_due - late_charge_paid) as l, SUM(total_paid) as paid')->first();
        $loan->principal_outstanding = Money::of($agg->p);
        $loan->profit_outstanding = Money::of($agg->pr);
        $loan->charges_outstanding = Money::of($agg->c);
        $loan->late_charges_outstanding = Money::of($agg->l);
        $loan->total_paid = Money::of($agg->paid);

        $oldestUnpaid = Instalment::where('loan_account_id', $loan->id)->where('status', '!=', 'paid')->where('due_date', '<', today())->min('due_date');
        $loan->dpd = $oldestUnpaid ? (int) \Carbon\Carbon::parse($oldestUnpaid)->diffInDays(today()) : 0;

        if (Money::isZero($loan->totalOutstanding()) && in_array($loan->status, [LoanAccountStatus::Active, LoanAccountStatus::Overdue], true)) {
            $loan->status = LoanAccountStatus::Closed;
            $loan->closed_at = now();
            Auditor::record('loan.closed', $loan, [], ['account_no' => $loan->account_no]);
            self::lifecycleEvent($loan, 'Financing '.$loan->account_no.' fully repaid – loan closed. NOC to be issued.');
        } elseif (in_array($loan->status, [LoanAccountStatus::Active, LoanAccountStatus::Overdue], true)) {
            $loan->status = $loan->dpd > 0 ? LoanAccountStatus::Overdue : LoanAccountStatus::Active;
        }
        $loan->save();
    }

    private function assertPayable(LoanAccount $loan, string $amount): void
    {
        if (! $loan->isOpen()) {
            throw new DomainException('This financing account is not open for repayment.');
        }
        if (! Money::isPositive($amount)) {
            throw new DomainException('Payment amount must be greater than zero.');
        }
        $next = $loan->nextInstalment();
        if (! ($loan->terms_snapshot['allow_partial_payment'] ?? true) && $next) {
            $nextDue = Money::sub(Money::add($next->total_due, $next->late_charge_due), $next->total_paid);
            if (Money::lt($amount, $nextDue)) {
                throw new DomainException('This product does not accept partial payments. Minimum payment is '.Money::format($nextDue).'.');
            }
        }
    }

    private function dueCol(string $c): string
    {
        return ['late_charge' => 'late_charge_due', 'charges' => 'charges_due', 'profit' => 'profit_due', 'principal' => 'principal_due'][$c];
    }

    private function paidCol(string $c): string
    {
        return ['late_charge' => 'late_charge_paid', 'charges' => 'charges_paid', 'profit' => 'profit_paid', 'principal' => 'principal_paid'][$c];
    }

    /**
     * Loan lifecycle milestones after disbursement (closed, NOC issued, written off, reopened) are shown on the
     * application timeline (QA M7). The application itself stays "disbursed"; the entry carries the milestone.
     */
    public static function lifecycleEvent(LoanAccount $loan, string $comment, bool $visibleToCustomer = true): void
    {
        \App\Models\ApplicationStatusHistory::create([
            'loan_application_id' => $loan->loan_application_id,
            'from_status' => \App\Enums\ApplicationStatus::Disbursed,
            'to_status' => \App\Enums\ApplicationStatus::Disbursed,
            'actor_id' => auth()->id(),
            'comment' => $comment,
            'visible_to_customer' => $visibleToCustomer,
        ]);
    }
}
