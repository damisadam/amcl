<?php

namespace App\Services\Lending;

use App\Enums\ApplicationStatus;
use App\Enums\ApprovalStatus;
use App\Enums\DisbursementStatus;
use App\Enums\LoanAccountStatus;
use App\Enums\PaymentPurpose;
use App\Enums\VendorPaymentStatus;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Events\DisbursementStatusUpdated;
use App\Exceptions\DomainException;
use App\Models\Disbursement;
use App\Models\Fund;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\User;
use App\Models\VendorPayment;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedger;
use App\Services\Audit\Auditor;
use App\Services\Notifications\Notifier;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Products\FinancingCalculator;
use App\Services\Support\Numbering;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Disbursement (DFS §4.1, LMS §6.6–6.7). Disbursement is impossible until every prerequisite holds;
 * a loan account, schedule and GL posting are created ONLY when the provider confirms the payout.
 * A failed payout leaves the case in the disbursement queue with no loan and no accounting entry.
 */
class DisbursementService
{
    public function __construct(
        private ApplicationService $applications,
        private PaymentOrchestrator $payments,
        private FinancingCalculator $calculator,
        private GeneralLedger $gl,
        private Notifier $notifier,
    ) {}

    /** @return list<string> blocking reasons; empty means disbursement may be initiated */
    public function blockers(LoanApplication $app): array
    {
        $b = [];
        if ($app->status === ApplicationStatus::CoolingOff && $app->isCoolingOffElapsed()) {
            app(AgreementService::class)->completeCoolingOff($app);
            $app->refresh();
        }
        if ($app->status !== ApplicationStatus::ReadyForDisbursement) {
            $b[] = 'Application status is "'.$app->status->label().'" – it must be Ready for Disbursement.';
        }
        if ($app->approvalRequest?->status !== ApprovalStatus::Approved) {
            $b[] = 'The configured approval hierarchy has not been fully approved.';
        }
        if (! $app->kfs_acknowledged_at) {
            $b[] = 'Key Fact Statement not acknowledged by the customer.';
        }
        if ($app->signatureRequests()->whereNotIn('status', ['signed', 'wet_ink_received'])->exists() || ! $app->signatureRequests()->exists()) {
            $b[] = 'Agreement documents are not fully signed.';
        }
        if (! $app->isCoolingOffElapsed()) {
            $b[] = 'Cooling-off period ends '.$app->cooling_off_ends_at?->format('d M Y H:i').'.';
        }
        $v = $app->productVersion;
        if ($v->disbursement_mode === 'vendor') {
            if (! $app->vendor?->isActive()) {
                $b[] = 'Vendor is missing or not active.';
            } elseif (! $app->vendor->iban) {
                $b[] = 'Vendor bank account (IBAN) is not on file.';
            }
        } elseif ($v->disbursement_mode === 'customer_bank' && ! $app->customer->linkedBankAccounts()->exists()) {
            $b[] = 'Customer has no linked bank account for disbursement.';
        } elseif ($v->disbursement_mode === 'wallet' && ! $app->customer->wallet?->isActive()) {
            $b[] = 'Customer wallet is not active.';
        }
        if ($app->fund_id && ($fund = Fund::find($app->fund_id))) {
            if (Money::lt($this->fundAvailable($fund), $app->financedAmount())) {
                $b[] = 'Insufficient capacity in funding source '.$fund->name.'.';
            }
        }

        return $b;
    }

    /**
     * Maker step: request a payout. The payout is only sent when a DIFFERENT authorised user releases it
     * (H7 maker-checker). Passing $releaser performs both steps in one call (used by trusted automation/tests),
     * still enforcing requester ≠ releaser.
     */
    public function initiate(LoanApplication $app, User $actor, ?string $scenario = null, ?User $releaser = null): Disbursement
    {
        if (! $actor->hasPermission('disbursements.initiate')) {
            throw new DomainException('You are not authorized to initiate disbursements.');
        }
        if ($blockers = $this->blockers($app)) {
            throw new DomainException('Disbursement blocked: '.implode(' ', $blockers));
        }

        $disbursement = DB::transaction(function () use ($app, $actor, $scenario) {
            $app = LoanApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();
            $active = $app->disbursements()->whereIn('status', [DisbursementStatus::Initiated->value, DisbursementStatus::Processing->value, DisbursementStatus::Succeeded->value])->first();
            if ($active) {
                return $active; // never two live payouts for one application
            }
            $attempt = $app->disbursements()->count() + 1;
            $v = $app->productVersion;
            $q = $this->calculator->quote($v, (string) $app->financedAmount(), (int) $app->tenure);
            $recipient = match ($v->disbursement_mode) {
                'vendor' => ['vendor', $app->vendor_id, $app->vendor->account_title ?: $app->vendor->business_name, $app->vendor->iban, $app->vendor->bank_name],
                'customer_bank' => (function () use ($app) {
                    $b = $app->customer->linkedBankAccounts()->orderByDesc('is_default')->first();

                    return ['customer_bank', $b->id, $b->account_title, $b->iban, $b->bank_name];
                })(),
                'wallet' => ['wallet', $app->customer->wallet->id, $app->customer->full_name, $app->customer->wallet->wallet_no, 'Wallet'],
            };
            $d = new Disbursement([
                'reference' => Numbering::next('disbursement'),
                'loan_application_id' => $app->id,
                'gross_amount' => $q['principal'],
                'deductions' => $q['charges_upfront'],
                'net_amount' => $q['net_disbursed'],
                'recipient_type' => $recipient[0], 'recipient_id' => $recipient[1], 'recipient_name' => $recipient[2], 'recipient_iban' => $recipient[3],
                'provider' => $v->disbursement_mode === 'wallet' ? 'sponsor_bank' : 'raast',
                'scenario' => $scenario,
                'idempotency_key' => 'disb:'.$app->id.':'.$attempt,
                'initiated_by' => $actor->id,
            ]);
            $d->forceFill(['status' => DisbursementStatus::Initiated])->save();
            $this->applications->transition($app, ApplicationStatus::DisbursementInProgress, $actor, 'Disbursement '.$d->reference.' requested to '.$recipient[2].' – awaiting release by a second authorised user.');
            Auditor::record('disbursement.requested', $d, [], ['amount' => $d->net_amount, 'recipient' => $recipient[2], 'scenario' => $scenario]);

            return $d;
        });

        return $releaser && $disbursement->awaitingRelease() ? $this->release($disbursement, $releaser, $scenario) : $disbursement->fresh();
    }

    /** Checker step: release a requested payout to the provider. The requester can never release their own request. */
    public function release(Disbursement $d, User $releaser, ?string $scenario = null): Disbursement
    {
        if (! $releaser->hasPermission('disbursements.initiate')) {
            throw new DomainException('You are not authorized to release disbursements.');
        }
        $d = DB::transaction(function () use ($d, $releaser, $scenario) {
            $d = Disbursement::whereKey($d->id)->lockForUpdate()->firstOrFail();
            if (! $d->awaitingRelease()) {
                throw new DomainException('This disbursement is not awaiting release.');
            }
            if ((int) $d->initiated_by === (int) $releaser->id) {
                throw new DomainException('Segregation of duties: a payout must be released by a different user than the one who requested it.');
            }
            $d->forceFill(['released_by' => $releaser->id, 'released_at' => now(), 'scenario' => $scenario ?? $d->scenario])->save();
            Auditor::record('disbursement.released', $d, [], ['amount' => $d->net_amount, 'requested_by' => $d->initiated_by]);

            return $d;
        });

        $bank = match ($d->recipient_type) { 'vendor' => $d->application->vendor?->bank_name, 'wallet' => 'Wallet', default => null };
        $order = $this->payments->initiate(PaymentPurpose::LoanDisbursement, $d->provider, (string) $d->net_amount, $d, 'po:'.$d->idempotency_key,
            ['name' => $d->recipient_name, 'account' => $d->recipient_iban, 'bank' => $bank], $d->scenario);
        $d->refresh();
        if (! $d->payment_order_id) {
            $d->forceFill(['payment_order_id' => $order->id, 'bank_account_id' => $order->bank_account_id])->save();
        }

        return $d->fresh();
    }

    /** Checker declines a requested payout; the case returns to the disbursement queue. */
    public function declineRequest(Disbursement $d, User $checker, string $reason): void
    {
        if (! $checker->hasPermission('disbursements.initiate')) {
            throw new DomainException('You are not authorized to decide disbursements.');
        }
        if (blank($reason)) {
            throw new DomainException('A reason is required to decline a payout request.');
        }
        DB::transaction(function () use ($d, $checker, $reason) {
            $d = Disbursement::whereKey($d->id)->lockForUpdate()->firstOrFail();
            if (! $d->awaitingRelease()) {
                throw new DomainException('This disbursement is not awaiting release.');
            }
            if ((int) $d->initiated_by === (int) $checker->id) {
                throw new DomainException('Segregation of duties: the requester cannot decide their own payout request.');
            }
            $this->fail($d, 'Release declined by '.$checker->name.': '.$reason);
        });
    }

    /** Provider confirmed the payout: create loan account, schedule, GL, vendor record. Idempotent. */
    public function confirm(Disbursement $d): LoanAccount
    {
        $d = Disbursement::whereKey($d->id)->lockForUpdate()->firstOrFail();
        if ($d->status === DisbursementStatus::Succeeded && $d->loanAccount) {
            return $d->loanAccount;
        }
        $d->status->assertCanTransitionTo(DisbursementStatus::Succeeded, 'Disbursement');
        $app = $d->application;
        $loan = $this->createLoanAccount($app, $d);
        $d->forceFill(['status' => DisbursementStatus::Succeeded, 'loan_account_id' => $loan->id, 'completed_at' => now(), 'payment_order_id' => $d->payment_order_id ?? $d->paymentOrder?->id])->save();

        $dims = ['branch_id' => $app->branch_id, 'customer_id' => $app->customer_id, 'fund_id' => $app->fund_id, 'vendor_id' => $app->vendor_id];
        // Murabaha sequence: purchase of the asset, sale at contract value, profit and financed charges deferred (ACCOUNTING_RULES.md).
        $amounts = ['asset_cost' => $d->gross_amount, 'net' => $d->net_amount, 'deductions' => $d->deductions,
            'contract' => Money::add($loan->principal, $loan->profit_total, $loan->charges_total), 'profit' => $loan->profit_total, 'charges' => $loan->charges_total];
        if ($d->recipient_type === 'wallet') {
            $this->creditWallet($d, $app);
            $this->gl->postEvent('loan_disbursement_wallet', $d, $amounts,
                "Financing disbursed to wallet {$d->reference} / {$loan->account_no}", $d->completed_at, $dims, module: 'lms');
        } else {
            $this->gl->postEvent('loan_disbursement', $d, $amounts,
                "Asset purchased & sold on Murabaha {$d->reference} / {$loan->account_no}; paid to {$d->recipient_name}", $d->completed_at, $dims, module: 'lms');
        }
        if ($d->recipient_type === 'vendor') {
            $vp = new VendorPayment([
                'payment_no' => Numbering::next('vendor_payment'), 'vendor_id' => $d->recipient_id, 'disbursement_id' => $d->id,
                'amount' => $d->net_amount, 'net_amount' => $d->net_amount, 'method' => 'raast', 'bank_account_id' => $d->bank_account_id,
                'payment_order_id' => $d->payment_order_id, 'idempotency_key' => 'vp:'.$d->idempotency_key,
                'purpose' => 'Asset financing disbursement for '.$app->application_no.' ('.$app->asset_description.')',
                'paid_at' => now(), 'created_by' => $d->initiated_by,
            ]);
            $vp->forceFill(['status' => VendorPaymentStatus::Paid])->save();
        }
        $this->applications->transition($app, ApplicationStatus::Disbursed, null, 'Funds disbursed ('.$d->reference.'). Financing account '.$loan->account_no.' is active.', ['disbursed_at' => now()]);
        Auditor::record('disbursement.succeeded', $d, [], ['loan_account' => $loan->account_no, 'amount' => $d->net_amount]);
        DB::afterCommit(function () use ($d, $app, $loan) {
            Realtime::emit(new DisbursementStatusUpdated($d->tenant_id, ['reference' => $d->reference, 'status' => 'succeeded']));
            $this->notifier->toCustomer($app->customer, 'disbursement', [
                'title' => 'Financing disbursed', 'body' => 'Your financing '.$loan->account_no.' is active. First instalment of '.Money::format($loan->instalments()->first()->total_due).' is due on '.$loan->first_due_date->format('d M Y').'.',
                'url' => '/app/financing/'.$loan->id,
            ], ['in_app', 'sms', 'email']);
        });

        return $loan;
    }

    public function fail(Disbursement $d, string $reason): void
    {
        $d = Disbursement::whereKey($d->id)->lockForUpdate()->firstOrFail();
        if ($d->status === DisbursementStatus::Failed) {
            return;
        }
        $d->status->assertCanTransitionTo(DisbursementStatus::Failed, 'Disbursement');
        $d->forceFill(['status' => DisbursementStatus::Failed, 'failure_reason' => $reason, 'completed_at' => now()])->save();
        $app = $d->application;
        if ($app->status === ApplicationStatus::DisbursementInProgress) {
            $this->applications->transition($app, ApplicationStatus::ReadyForDisbursement, null, 'Disbursement '.$d->reference.' failed: '.$reason.'. No loan was created; the case is back in the disbursement queue.', [], false);
        }
        Auditor::record('disbursement.failed', $d, [], [], $reason);
        DB::afterCommit(fn () => Realtime::emit(new DisbursementStatusUpdated($d->tenant_id, ['reference' => $d->reference, 'status' => 'failed'])));
    }

    /** Payout returned after success (e.g. vendor account closed): reverse GL; loan is flagged for manual handling. */
    public function providerReturned(Disbursement $d, string $reason): void
    {
        $d = Disbursement::whereKey($d->id)->lockForUpdate()->firstOrFail();
        if ($d->status !== DisbursementStatus::Succeeded) {
            $this->fail($d, $reason);

            return;
        }
        $d->forceFill(['status' => DisbursementStatus::Reversed, 'failure_reason' => $reason])->save();
        Auditor::record('disbursement.returned', $d, [], [], $reason.' – requires operations follow-up (loan account remains for investigation).');
    }

    public function createLoanAccount(LoanApplication $app, Disbursement $d): LoanAccount
    {
        $v = $app->productVersion;
        $q = $this->calculator->quote($v, (string) $app->financedAmount(), (int) $app->tenure, now());
        $loan = LoanAccount::create([
            'account_no' => Numbering::next('loan_account'),
            'loan_application_id' => $app->id, 'customer_id' => $app->customer_id, 'product_id' => $app->product_id, 'product_version_id' => $v->id,
            'branch_id' => $app->branch_id, 'fund_id' => $app->fund_id, 'vendor_id' => $app->vendor_id,
            'shariah_structure' => $app->product->shariah_structure, 'profit_rate' => $v->profit_rate, 'frequency' => $v->frequency, 'tenure' => $app->tenure,
            'principal' => $q['principal'], 'profit_total' => $q['profit_total'], 'charges_total' => $q['charges_spread'],
            'total_receivable' => $q['total_payable'], 'principal_outstanding' => $q['principal'], 'profit_outstanding' => $q['profit_total'],
            'charges_outstanding' => $q['charges_spread'], 'status' => LoanAccountStatus::Active,
            'terms_snapshot' => collect($q)->except('schedule')->all() + ['allocation_order' => $v->allocationOrder(), 'late_charge_per_day' => $v->late_charge_per_day,
                'grace_days' => $v->grace_days, 'late_payment_treatment' => $v->late_payment_treatment, 'allow_partial_payment' => $v->allow_partial_payment, 'product_version' => $v->version],
            'first_due_date' => $q['first_due_date'], 'maturity_date' => $q['maturity_date'], 'disbursed_at' => now(),
            'loan_officer_id' => $app->loan_officer_id,
        ]);
        foreach ($q['schedule'] as $row) {
            Instalment::create($row + ['loan_account_id' => $loan->id, 'status' => 'pending']);
        }
        Auditor::record('loan.account_created', $loan, [], ['account_no' => $loan->account_no, 'principal' => $loan->principal, 'instalments' => count($q['schedule'])]);

        return $loan;
    }

    private function creditWallet(Disbursement $d, LoanApplication $app): void
    {
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->lock($d->recipient_id);
        $txn = new WalletTransaction([
            'reference' => Numbering::next('wallet_txn'), 'wallet_id' => $wallet->id, 'type' => WalletTransactionType::DisbursementCredit,
            'direction' => 'credit', 'amount' => $d->net_amount, 'idempotency_key' => 'disb-credit:'.$d->id, 'is_external' => false,
            'description' => 'Financing disbursement '.$d->reference, 'subject_type' => $d->getMorphClass(), 'subject_id' => $d->id,
        ]);
        $txn->forceFill(['status' => WalletTransactionStatus::Pending])->save();
        $ledger->credit($wallet, $txn);
        $txn->forceFill(['status' => WalletTransactionStatus::Succeeded, 'completed_at' => now()])->save();
    }

    public function fundAvailable(Fund $fund): string
    {
        $used = LoanAccount::where('fund_id', $fund->id)->whereIn('status', ['active', 'overdue'])->sum('principal_outstanding');

        return Money::sub($fund->committed_amount, Money::of($used));
    }
}
