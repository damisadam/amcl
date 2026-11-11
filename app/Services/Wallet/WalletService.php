<?php

namespace App\Services\Wallet;

use App\Enums\AmlStatus;
use App\Enums\KycStatus;
use App\Enums\PaymentPurpose;
use App\Enums\WalletStatus;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Events\WalletTransactionCompleted;
use App\Exceptions\DomainException;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\LinkedBankAccount;
use App\Models\LoanAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Accounting\GeneralLedger;
use App\Services\Audit\Auditor;
use App\Services\Integrations\Deterministic;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderResponse;
use App\Services\Lending\RepaymentService;
use App\Services\Notifications\Notifier;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Digital wallet operations. Internal transactions (wallet↔wallet, internal merchant, loan repayment)
 * move only the wallet ledger; external ones (top-up, withdrawal, bill, external merchant) go through
 * a payment order and touch the safeguarded bank account (Wallet doc "Key Principle").
 */
class WalletService
{
    public function __construct(
        private WalletLedger $ledger,
        private PaymentOrchestrator $payments,
        private GeneralLedger $gl,
        private Notifier $notifier,
    ) {}

    // ---------------------------------------------------------------- onboarding & status

    public function open(Customer|Vendor $owner): Wallet
    {
        if ($existing = $owner->wallet()->first()) {
            return $existing;
        }
        $wallet = new Wallet([
            'wallet_no' => Numbering::next('wallet', $owner->tenant_id, false, 8),
            'title' => $owner instanceof Customer ? $owner->full_name : $owner->business_name,
            'mobile' => $owner instanceof Customer ? $owner->mobile : $owner->phone,
            'daily_limit' => TenantSettings::get('wallet', 'default_daily_limit'),
            'per_transaction_limit' => TenantSettings::get('wallet', 'default_per_transaction_limit'),
            'monthly_limit' => TenantSettings::get('wallet', 'default_monthly_limit'),
            'max_balance' => $owner instanceof Vendor ? '10000000.00' : TenantSettings::get('wallet', 'default_max_balance'),
        ]);
        $wallet->owner()->associate($owner);
        $wallet->forceFill(['status' => WalletStatus::Pending])->save();
        Auditor::record('wallet.opened', $wallet, [], ['wallet_no' => $wallet->wallet_no]);

        return $wallet;
    }

    /** Wallet onboarding prerequisites (Wallet doc §1.2). Returns unmet prerequisites. */
    public function unmetPrerequisites(Wallet $wallet): array
    {
        $owner = $wallet->owner;
        if (! $owner instanceof Customer) {
            return [];
        }
        $missing = [];
        if (! $owner->user?->mobile_verified_at) {
            $missing[] = 'Mobile number verification (OTP)';
        }
        if ($owner->kyc_status !== KycStatus::Passed) {
            $missing[] = 'KYC verification';
        }
        if (in_array($owner->aml_status, [AmlStatus::NotScreened, AmlStatus::PotentialMatch, AmlStatus::ConfirmedMatch], true)) {
            $missing[] = 'AML/CFT screening clearance';
        }
        if ($owner->is_blacklisted) {
            $missing[] = 'Customer is blacklisted';
        }

        return $missing;
    }

    public function activate(Wallet $wallet, ?User $actor = null): Wallet
    {
        if ($missing = $this->unmetPrerequisites($wallet)) {
            throw new DomainException('Wallet cannot be activated yet: '.implode(', ', $missing).'.');
        }

        return $this->changeStatus($wallet, WalletStatus::Active, 'Prerequisites satisfied', $actor);
    }

    public function changeStatus(Wallet $wallet, WalletStatus $to, string $reason, ?User $actor = null): Wallet
    {
        return DB::transaction(function () use ($wallet, $to, $reason) {
            $wallet = $this->ledger->lock($wallet->id);
            if ($wallet->status === $to) {
                return $wallet;
            }
            $wallet->status->assertCanTransitionTo($to, 'Wallet');
            if ($to === WalletStatus::Closed && (! Money::isZero($wallet->balance) || ! Money::isZero($wallet->held_balance))) {
                throw new DomainException('A wallet can only be closed with a zero balance and no pending transactions.');
            }
            $from = $wallet->status;
            $wallet->forceFill([
                'status' => $to,
                'status_reason' => $reason,
                'activated_at' => $to === WalletStatus::Active ? ($wallet->activated_at ?? now()) : $wallet->activated_at,
                'closed_at' => $to === WalletStatus::Closed ? now() : null,
            ])->save();
            Auditor::record('wallet.status_changed', $wallet, ['status' => $from->value], ['status' => $to->value], $reason);

            return $wallet;
        });
    }

    public function updateLimits(Wallet $wallet, array $limits, string $reason): Wallet
    {
        foreach (['daily_limit', 'per_transaction_limit', 'monthly_limit', 'max_balance'] as $k) {
            if (isset($limits[$k]) && ! Money::isPositive($limits[$k])) {
                throw new DomainException('Limits must be positive amounts.');
            }
        }
        $wallet->fill(array_intersect_key($limits, array_flip(['daily_limit', 'per_transaction_limit', 'monthly_limit', 'max_balance'])));
        Auditor::changes('wallet.limits_changed', $wallet, $reason);
        $wallet->save();

        return $wallet;
    }

    public function setPin(Wallet $wallet, string $pin): void
    {
        if (! preg_match('/^\d{4,6}$/', $pin) || in_array($pin, ['0000', '1234', '1111', '123456'], true)) {
            throw new DomainException('PIN must be 4–6 digits and not a trivial sequence.');
        }
        $wallet->forceFill(['pin_hash' => Hash::make($pin), 'pin_attempts' => 0])->save();
        Auditor::record('wallet.pin_changed', $wallet);
    }

    public function verifyPin(Wallet $wallet, ?string $pin): void
    {
        if (! TenantSettings::get('wallet', 'require_pin_for_debits', true)) {
            return;
        }
        if (! $wallet->pin_hash) {
            throw new DomainException('Set your wallet PIN in Wallet Settings before making payments.');
        }
        if ($wallet->pin_attempts >= 5) {
            throw new DomainException('Wallet PIN is locked after too many attempts. Contact support.');
        }
        if (! $pin || ! Hash::check($pin, $wallet->pin_hash)) {
            $wallet->increment('pin_attempts');
            $left = 5 - $wallet->pin_attempts;
            throw new DomainException($left > 0
                ? __('Incorrect wallet PIN. :n attempt(s) left before your PIN is locked.', ['n' => $left])
                : __('Incorrect wallet PIN. Your PIN is now locked – contact support to unlock it.'));
        }
        if ($wallet->pin_attempts) {
            $wallet->forceFill(['pin_attempts' => 0])->save();
        }
    }

    // ---------------------------------------------------------------- external: top-up

    public function topUp(Wallet $wallet, string $amount, string $provider, string $idempotencyKey, ?string $scenario = null, ?User $actor = null, ?string $sourceBank = null): WalletTransaction
    {
        $amount = Money::of($amount);
        $this->assertUsable($wallet);
        $this->assertWithin($amount, $wallet->per_transaction_limit, 'per-transaction limit');
        if (Money::gt(Money::add($wallet->balance, $amount), $wallet->max_balance)) {
            throw new DomainException('This top-up would exceed the maximum wallet balance of '.Money::format($wallet->max_balance).'.');
        }
        if (! in_array($provider, ['raast', 'easypaisa', 'jazzcash', 'sponsor_bank'], true)) {
            throw new DomainException('Unsupported top-up source.');
        }

        $txn = $this->existingOr($idempotencyKey, fn () => $this->newTxn($wallet, WalletTransactionType::Topup, 'credit', $amount, $idempotencyKey, [
            'is_external' => true, 'provider' => $provider,
            'description' => 'Top-up via '.config("integrations.providers.$provider.name").($sourceBank ? " from {$sourceBank}" : ''),
            'meta' => ['source_bank' => $sourceBank, 'simulated' => true],
        ], $actor));

        if ($txn->wasRecentlyCreated) {
            $order = $this->payments->initiate(PaymentPurpose::WalletTopup, $provider, $amount, $txn, 'po:'.$idempotencyKey,
                ['name' => $wallet->title, 'account' => $wallet->wallet_no, 'bank' => $sourceBank], $scenario);
            WalletTransaction::whereKey($txn->id)->whereNull('payment_order_id')->update(['payment_order_id' => $order->id]);
        }

        return $txn->fresh();
    }

    /** PaymentOrchestrator → WalletTopupHandler */
    public function settleTopUp(WalletTransaction $txn): void
    {
        $txn = WalletTransaction::whereKey($txn->id)->lockForUpdate()->first();
        if ($txn->status === WalletTransactionStatus::Succeeded) {
            return;
        }
        $wallet = $this->ledger->lock($txn->wallet_id);
        $this->ledger->credit($wallet, $txn);
        $this->complete($txn, WalletTransactionStatus::Succeeded);
        $this->gl->postEvent('wallet_topup', $txn, ['amount' => $txn->amount], "Wallet top-up {$txn->reference} ({$wallet->wallet_no})",
            dimensions: $this->dims($wallet), module: 'wallet');
        $this->afterCommitNotify($txn, 'wallet_topup', 'Wallet top-up successful', 'PKR '.Money::format($txn->amount, false).' added to your wallet. Ref '.$txn->reference.'.');
    }

    // ---------------------------------------------------------------- external: withdrawal / bill / external merchant (hold → capture)

    public function withdraw(Wallet $wallet, string $amount, LinkedBankAccount $bank, string $idempotencyKey, ?string $pin, ?string $scenario = null, ?User $actor = null): WalletTransaction
    {
        $amount = Money::of($amount);
        if ((int) $bank->customer_id !== (int) $wallet->owner_id || $wallet->owner_type !== (new Customer)->getMorphClass()) {
            throw new DomainException('You can only withdraw to your own linked bank account.');
        }

        return $this->outbound($wallet, WalletTransactionType::Withdrawal, PaymentPurpose::WalletWithdrawal, 'raast', $amount, $idempotencyKey, $pin, $scenario, $actor,
            ['name' => $bank->account_title, 'account' => $bank->iban, 'bank' => $bank->bank_name],
            'Withdrawal to '.$bank->bank_name.' ****'.substr($bank->iban, -4), ['linked_bank_account_id' => $bank->id]);
    }

    public function inquireBill(string $billerCode, string $consumerRef): ProviderResponse
    {
        $billers = self::billers();
        if (! isset($billers[$billerCode])) {
            throw new DomainException('Unknown biller.');
        }

        return app(IntegrationManager::class)->call('one_bill', 'bill_inquiry', ['biller' => $billerCode, 'consumer_ref' => $consumerRef], function () use ($billerCode, $consumerRef, $billers) {
            if (! preg_match('/^\d{8,14}$/', $consumerRef)) {
                return new ProviderResponse('failure', [], 'INVALID_CONSUMER', 'Consumer reference not found (simulated).');
            }
            if (str_ends_with($consumerRef, '00')) {
                return new ProviderResponse('success', ['status' => 'paid', 'consumer_name' => 'SIMULATED CONSUMER', 'amount_due' => '0.00',
                    'biller' => $billers[$billerCode]['name']], providerRef: Deterministic::ref('1B', $consumerRef));
            }

            return new ProviderResponse('success', [
                'status' => 'unpaid',
                'consumer_name' => 'CONSUMER '.substr($consumerRef, -4).' (SIMULATED)',
                'amount_due' => number_format(Deterministic::number($billerCode.$consumerRef, 800, 18000), 2, '.', ''),
                'due_date' => now()->addDays(Deterministic::number($consumerRef, 2, 15))->toDateString(),
                'biller' => $billers[$billerCode]['name'],
            ], providerRef: Deterministic::ref('1B', $consumerRef));
        });
    }

    public function payBill(Wallet $wallet, string $billerCode, string $consumerRef, string $idempotencyKey, ?string $pin, ?string $scenario = null, ?User $actor = null): BillPayment
    {
        $inquiry = $this->inquireBill($billerCode, $consumerRef);
        if (! $inquiry->successful()) {
            throw new DomainException($inquiry->message ?? 'Bill inquiry failed.');
        }
        if ($inquiry->get('status') === 'paid') {
            throw new DomainException('This bill is already paid.');
        }
        $amount = Money::of($inquiry->get('amount_due'));
        $biller = self::billers()[$billerCode];

        return DB::transaction(function () use ($wallet, $billerCode, $consumerRef, $idempotencyKey, $pin, $scenario, $actor, $inquiry, $amount, $biller) {
            $bill = BillPayment::where('reference', 'BP:'.$idempotencyKey)->first();
            if ($bill) {
                return $bill;
            }
            $owner = $wallet->owner;
            $bill = BillPayment::create([
                'reference' => Numbering::next('bill_payment'),
                'customer_id' => $owner->id,
                'biller_code' => $billerCode, 'biller_name' => $biller['name'], 'bill_type' => $biller['type'],
                'consumer_ref' => $consumerRef, 'consumer_name' => $inquiry->get('consumer_name'),
                'amount' => $amount, 'due_date' => $inquiry->get('due_date'), 'funding_source' => 'wallet', 'status' => 'pending',
            ]);
            $txn = $this->outbound($wallet, WalletTransactionType::BillPayment, PaymentPurpose::BillPayment, 'one_bill', $amount, $idempotencyKey, $pin, $scenario, $actor,
                ['name' => $biller['name'], 'account' => $consumerRef, 'bank' => '1Bill'], $biller['name'].' bill '.$consumerRef, ['bill_payment_id' => $bill->id], $bill);
            $bill->update(['wallet_transaction_id' => $txn->id, 'payment_order_id' => $txn->payment_order_id, 'status' => $this->billStatus($txn)]);

            return $bill;
        });
    }

    /** Merchant QR payment: internal merchant (has wallet) = ledger only; external merchant = Raast payout. */
    public function payMerchant(Wallet $wallet, string $merchantCode, string $amount, string $idempotencyKey, ?string $pin, ?string $scenario = null, ?User $actor = null): WalletTransaction
    {
        $amount = Money::of($amount);
        $merchant = Vendor::where('merchant_code', $merchantCode)->where('is_merchant', true)->first();
        if (! $merchant || ! $merchant->isActive()) {
            throw new DomainException('Merchant not found or inactive for this institution.');
        }
        $merchantWallet = $merchant->wallet()->first();

        if ($merchantWallet && $merchantWallet->isActive()) {
            return $this->internalMove($wallet, $merchantWallet, $amount, $idempotencyKey, $pin, $actor,
                WalletTransactionType::MerchantPayment, WalletTransactionType::MerchantReceipt, 'Payment to '.$merchant->business_name, 'Payment from '.$wallet->title, $merchant);
        }

        return $this->outbound($wallet, WalletTransactionType::MerchantPayment, PaymentPurpose::MerchantPayment, 'raast', $amount, $idempotencyKey, $pin, $scenario, $actor,
            ['name' => $merchant->account_title ?? $merchant->business_name, 'account' => $merchant->iban, 'bank' => $merchant->bank_name],
            'Payment to '.$merchant->business_name.' (external)', ['merchant_id' => $merchant->id], $merchant);
    }

    /** PaymentOrchestrator → WalletOutboundHandler (success) */
    public function captureOutbound(WalletTransaction $txn): void
    {
        $txn = WalletTransaction::whereKey($txn->id)->lockForUpdate()->first();
        if ($txn->status === WalletTransactionStatus::Succeeded) {
            return;
        }
        $wallet = $this->ledger->lock($txn->wallet_id);
        $this->ledger->capture($wallet, $txn);
        $this->complete($txn, WalletTransactionStatus::Succeeded);
        $event = match ($txn->type) {
            WalletTransactionType::Withdrawal => 'wallet_withdrawal',
            WalletTransactionType::BillPayment => 'wallet_bill_payment',
            default => 'wallet_merchant_external',
        };
        $this->gl->postEvent($event, $txn, ['amount' => Money::add($txn->amount, $txn->fee)], "{$txn->type->label()} {$txn->reference}", dimensions: $this->dims($wallet), module: 'wallet');
        if ($bill = BillPayment::where('wallet_transaction_id', $txn->id)->first()) {
            $bill->update(['status' => 'succeeded', 'provider_ref' => $txn->paymentOrder?->provider_ref]);
        }
        $this->afterCommitNotify($txn, 'wallet_debit', $txn->type->label().' completed', 'PKR '.Money::format($txn->amount, false).' – '.$txn->description.'. Ref '.$txn->reference.'.');
    }

    /** PaymentOrchestrator → WalletOutboundHandler (failure): release the hold. */
    public function failOutbound(WalletTransaction $txn, string $reason): void
    {
        $txn = WalletTransaction::whereKey($txn->id)->lockForUpdate()->first();
        if ($txn->status !== WalletTransactionStatus::Pending && $txn->status !== WalletTransactionStatus::Processing) {
            return;
        }
        $wallet = $this->ledger->lock($txn->wallet_id);
        $this->ledger->release($wallet, $txn);
        $txn->failure_reason = $reason;
        $this->complete($txn, WalletTransactionStatus::Failed);
        BillPayment::where('wallet_transaction_id', $txn->id)->update(['status' => 'failed']);
        $this->afterCommitNotify($txn, 'wallet_failed', 'Transaction failed', $txn->type->label().' of PKR '.Money::format($txn->amount, false).' failed: '.$reason.' Funds were released.');
    }

    /** Topup failed at provider. */
    public function failTopUp(WalletTransaction $txn, string $reason): void
    {
        $txn = WalletTransaction::whereKey($txn->id)->lockForUpdate()->first();
        if (! in_array($txn->status, [WalletTransactionStatus::Pending, WalletTransactionStatus::Processing], true)) {
            return;
        }
        $txn->failure_reason = $reason;
        $this->complete($txn, WalletTransactionStatus::Failed);
        $this->afterCommitNotify($txn, 'wallet_failed', 'Top-up failed', 'Your top-up of PKR '.Money::format($txn->amount, false).' failed: '.$reason);
    }

    /**
     * Provider reversed a previously successful external transaction. A compensating ledger entry is
     * posted (never editing history) and the GL voucher is reversed. If the customer already spent
     * a reversed top-up, the wallet may go negative and is restricted pending recovery (D-007).
     */
    public function providerReversal(WalletTransaction $original, string $reason): WalletTransaction
    {
        $original = WalletTransaction::whereKey($original->id)->lockForUpdate()->first();
        if ($original->status === WalletTransactionStatus::Reversed) {
            return $original->related ?? $original;
        }
        if ($original->status !== WalletTransactionStatus::Succeeded) {
            // Provider reversed something we never settled: just mark failed.
            if ($original->isCredit()) {
                $this->failTopUp($original, $reason);
            } else {
                $this->failOutbound($original, $reason);
            }

            return $original;
        }
        $wallet = $this->ledger->lock($original->wallet_id);
        $comp = $this->newTxn($wallet, WalletTransactionType::Reversal, $original->isCredit() ? 'debit' : 'credit', Money::add($original->amount, $original->fee),
            'reversal:'.$original->id, ['is_external' => true, 'provider' => $original->provider, 'related_transaction_id' => $original->id,
                'description' => 'Provider reversal of '.$original->reference]);
        if ($original->isCredit()) {
            $this->ledger->debit($wallet, $comp, allowNegative: true);
            if (Money::lt($wallet->fresh()->balance, 0)) {
                $this->changeStatus($wallet, WalletStatus::Restricted, 'Negative balance after provider reversal of '.$original->reference);
            }
        } else {
            $this->ledger->credit($wallet, $comp);
        }
        $this->complete($comp, WalletTransactionStatus::Succeeded);
        $original->related_transaction_id = $comp->id;
        $this->complete($original, WalletTransactionStatus::Reversed);
        $this->reverseVoucherFor($original, $reason);
        $this->afterCommitNotify($comp, 'wallet_reversal', 'Transaction reversed', $original->reference.' was reversed by the provider: '.$reason);

        return $comp;
    }

    // ---------------------------------------------------------------- internal transfers

    public function transfer(Wallet $from, string $toMobile, string $amount, string $idempotencyKey, ?string $pin, ?User $actor = null, ?string $note = null): WalletTransaction
    {
        $to = Wallet::where('mobile', $toMobile)->where('owner_type', (new Customer)->getMorphClass())->first(); // tenant-scoped: cross-tenant wallets are invisible
        if (! $to) {
            throw new DomainException('No active wallet is registered to that mobile number at this institution.');
        }
        if ($to->id === $from->id) {
            throw new DomainException('You cannot transfer to your own wallet.');
        }

        return $this->internalMove($from, $to, Money::of($amount), $idempotencyKey, $pin, $actor, WalletTransactionType::TransferOut, WalletTransactionType::TransferIn,
            'Transfer to '.self::maskName($to->title).($note ? " – {$note}" : ''), 'Transfer from '.self::maskName($from->title).($note ? " – {$note}" : ''));
    }

    private function internalMove(Wallet $from, Wallet $to, string $amount, string $key, ?string $pin, ?User $actor,
        WalletTransactionType $outType, WalletTransactionType $inType, string $outDesc, string $inDesc, $subject = null): WalletTransaction
    {
        if ((int) $from->tenant_id !== (int) $to->tenant_id && ! TenantSettings::get('wallet', 'allow_cross_tenant_transfers', false)) {
            throw new DomainException('Transfers between wallets of different institutions are not permitted.');
        }
        if ($existing = WalletTransaction::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $this->verifyPin($from, $pin);

        $out = DB::transaction(function () use ($from, $to, $amount, $key, $actor, $outType, $inType, $outDesc, $inDesc, $subject) {
            [$fromLocked, $toLocked] = $this->ledger->lockMany([$from->id, $to->id]);
            $this->assertUsable($fromLocked);
            if (! $toLocked->isActive()) {
                throw new DomainException('The receiving wallet is not active.');
            }
            $this->assertDebitLimits($fromLocked, $amount);
            if (Money::gt(Money::add($toLocked->balance, $amount), $toLocked->max_balance)) {
                throw new DomainException('The receiving wallet would exceed its maximum balance.');
            }
            $extra = $subject ? ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()] : [];
            $out = $this->newTxn($fromLocked, $outType, 'debit', $amount, $key, $extra + ['counterparty_wallet_id' => $toLocked->id, 'description' => $outDesc], $actor);
            $in = $this->newTxn($toLocked, $inType, 'credit', $amount, $key.':in', $extra + ['counterparty_wallet_id' => $fromLocked->id, 'related_transaction_id' => $out->id, 'description' => $inDesc], $actor);
            $out->related_transaction_id = $in->id;
            $this->ledger->debit($fromLocked, $out);
            $this->ledger->credit($toLocked, $in);
            $this->complete($out, WalletTransactionStatus::Succeeded);
            $this->complete($in, WalletTransactionStatus::Succeeded);
            Auditor::record('wallet.internal_transfer', $out, [], ['amount' => $amount, 'to_wallet' => $toLocked->wallet_no]);
            $this->afterCommitNotify($out, 'wallet_sent', 'Money sent', 'PKR '.Money::format($amount, false).' – '.$outDesc.'. Ref '.$out->reference.'.');
            $this->afterCommitNotify($in, 'wallet_received', 'Money received', 'PKR '.Money::format($amount, false).' – '.$inDesc.'. Ref '.$in->reference.'.');

            return $out;
        });

        return $out->fresh();
    }

    // ---------------------------------------------------------------- instalment repayment from wallet

    public function payInstalment(Wallet $wallet, LoanAccount $loan, string $amount, string $idempotencyKey, ?string $pin, ?User $actor = null): WalletTransaction
    {
        $amount = Money::of($amount);
        if ((int) $loan->customer_id !== (int) $wallet->owner_id || $wallet->owner_type !== (new Customer)->getMorphClass()) {
            throw new DomainException('You can only repay your own financing from your wallet.');
        }
        if ($existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        $this->verifyPin($wallet, $pin);

        return DB::transaction(function () use ($wallet, $loan, $amount, $idempotencyKey, $actor) {
            $locked = $this->ledger->lock($wallet->id);
            $this->assertUsable($locked);
            $this->assertDebitLimits($locked, $amount, checkDaily: false);
            $txn = $this->newTxn($locked, WalletTransactionType::InstalmentPayment, 'debit', $amount, $idempotencyKey, [
                'subject_type' => $loan->getMorphClass(), 'subject_id' => $loan->id, 'description' => 'Instalment payment – '.$loan->account_no,
            ], $actor);
            $this->ledger->debit($locked, $txn);
            $this->complete($txn, WalletTransactionStatus::Succeeded);
            app(RepaymentService::class)->recordSettled($loan, $amount, 'wallet', 'wallet:'.$idempotencyKey, walletTransaction: $txn, actor: $actor);
            $this->afterCommitNotify($txn, 'instalment_paid', 'Instalment payment received', 'PKR '.Money::format($amount, false).' paid towards '.$loan->account_no.' from your wallet.');

            return $txn;
        });
    }

    // ---------------------------------------------------------------- operator reversal / refund of internal transactions

    public function reverseInternal(WalletTransaction $txn, string $reason, User $actor): WalletTransaction
    {
        if (! $actor->hasPermission('wallet.reverse')) {
            throw new DomainException('You are not authorized to reverse wallet transactions.');
        }
        if ((int) $txn->initiated_by === (int) $actor->id) {
            throw new DomainException('Segregation of duties: you cannot reverse a transaction you initiated.');
        }
        if (! in_array($txn->type, [WalletTransactionType::TransferOut, WalletTransactionType::MerchantPayment], true) || $txn->is_external) {
            throw new DomainException('Only internal wallet-to-wallet and internal merchant payments can be reversed here. Instalment payments are reversed from Repayments.');
        }
        if (blank($reason)) {
            throw new DomainException('A reason is required.');
        }

        return DB::transaction(function () use ($txn, $reason, $actor) {
            $out = WalletTransaction::whereKey($txn->id)->lockForUpdate()->first();
            if ($out->status !== WalletTransactionStatus::Succeeded) {
                throw new DomainException('Only successful transactions can be reversed.');
            }
            $in = WalletTransaction::whereKey($out->related_transaction_id)->lockForUpdate()->firstOrFail();
            [$payer, $payee] = $this->ledger->lockMany([$out->wallet_id, $in->wallet_id]);
            $type = $out->type === WalletTransactionType::MerchantPayment ? WalletTransactionType::Refund : WalletTransactionType::Reversal;
            $debitPayee = $this->newTxn($payee, $type, 'debit', $in->amount, 'rev-out:'.$out->id, ['related_transaction_id' => $in->id, 'counterparty_wallet_id' => $payer->id, 'description' => $type->label().' of '.$out->reference], $actor);
            $creditPayer = $this->newTxn($payer, $type, 'credit', $out->amount, 'rev-in:'.$out->id, ['related_transaction_id' => $out->id, 'counterparty_wallet_id' => $payee->id, 'description' => $type->label().' of '.$out->reference], $actor);
            $this->ledger->debit($payee, $debitPayee);
            $this->ledger->credit($payer, $creditPayer);
            foreach ([$debitPayee, $creditPayer] as $t) {
                $this->complete($t, WalletTransactionStatus::Succeeded);
            }
            $status = $type === WalletTransactionType::Refund ? WalletTransactionStatus::Refunded : WalletTransactionStatus::Reversed;
            $this->complete($out, $status);
            $this->complete($in, $status);
            Auditor::record('wallet.reversed', $out, [], ['compensating' => [$debitPayee->reference, $creditPayer->reference]], $reason);
            $this->afterCommitNotify($creditPayer, 'wallet_refund', $type->label().' credited', 'PKR '.Money::format($out->amount, false).' returned to your wallet ('.$out->reference.').');

            return $creditPayer;
        });
    }

    // ---------------------------------------------------------------- helpers

    private function outbound(Wallet $wallet, WalletTransactionType $type, PaymentPurpose $purpose, string $provider, string $amount, string $key, ?string $pin,
        ?string $scenario, ?User $actor, array $counterparty, string $description, array $meta = [], $subject = null): WalletTransaction
    {
        if ($existing = WalletTransaction::where('idempotency_key', $key)->first()) {
            return $existing;
        }
        $this->verifyPin($wallet, $pin);

        $txn = DB::transaction(function () use ($wallet, $type, $amount, $key, $actor, $description, $meta, $provider, $subject) {
            $locked = $this->ledger->lock($wallet->id);
            $this->assertUsable($locked);
            $this->assertDebitLimits($locked, $amount);
            $txn = $this->newTxn($locked, $type, 'debit', $amount, $key, [
                'is_external' => true, 'provider' => $provider, 'description' => $description, 'meta' => $meta + ['simulated' => true],
            ] + ($subject ? ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()] : []), $actor);
            $this->ledger->hold($locked, $txn);

            return $txn;
        });

        $order = $this->payments->initiate($purpose, $provider, $amount, $txn, 'po:'.$key, $counterparty, $scenario);
        $txn->forceFill(['payment_order_id' => $order->id])->save();

        return $txn->fresh();
    }

    private function newTxn(Wallet $wallet, WalletTransactionType $type, string $direction, string $amount, string $key, array $attrs = [], ?User $actor = null): WalletTransaction
    {
        $txn = new WalletTransaction(array_merge([
            'reference' => Numbering::next('wallet_txn', $wallet->tenant_id),
            'wallet_id' => $wallet->id,
            'type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'idempotency_key' => $key,
            'initiated_by' => $actor?->id ?? auth()->id(),
        ], $attrs));
        $txn->forceFill(['status' => WalletTransactionStatus::Pending])->save();

        return $txn;
    }

    private function existingOr(string $key, \Closure $create): WalletTransaction
    {
        return WalletTransaction::where('idempotency_key', $key)->first() ?? DB::transaction($create);
    }

    private function complete(WalletTransaction $txn, WalletTransactionStatus $to): void
    {
        $txn->status->assertCanTransitionTo($to, 'Wallet transaction');
        $txn->forceFill(['status' => $to, 'completed_at' => now()])->save();
    }

    private function assertUsable(Wallet $wallet): void
    {
        if (! $wallet->isActive()) {
            throw new DomainException('Your wallet is '.strtolower($wallet->status->label()).'. '.($wallet->status === WalletStatus::Pending ? 'Complete onboarding to activate it.' : 'Please contact support.'));
        }
    }

    private function assertWithin(string $amount, string $limit, string $label): void
    {
        if (! Money::isPositive($amount)) {
            throw new DomainException('Amount must be greater than zero.');
        }
        if (Money::gt($amount, $limit)) {
            throw new DomainException('Amount exceeds your '.$label.' of '.Money::format($limit).'.');
        }
    }

    private function assertDebitLimits(Wallet $wallet, string $amount, bool $checkDaily = true): void
    {
        $this->assertWithin($amount, $wallet->per_transaction_limit, 'per-transaction limit');
        if (! $checkDaily) {
            return;
        }
        $base = WalletTransaction::where('wallet_id', $wallet->id)->where('direction', 'debit')
            ->whereIn('status', ['pending', 'processing', 'succeeded'])->whereNotIn('type', ['reversal', 'refund', 'instalment_payment']);
        $today = Money::of((clone $base)->where('created_at', '>=', today())->sum('amount'));
        if (Money::gt(Money::add($today, $amount), $wallet->daily_limit)) {
            throw new DomainException('This exceeds your daily limit of '.Money::format($wallet->daily_limit).' (used today: '.Money::format($today).').');
        }
        $month = Money::of((clone $base)->where('created_at', '>=', now()->startOfMonth())->sum('amount'));
        if (Money::gt(Money::add($month, $amount), $wallet->monthly_limit)) {
            throw new DomainException('This exceeds your monthly limit of '.Money::format($wallet->monthly_limit).'.');
        }
    }

    private function reverseVoucherFor(WalletTransaction $txn, string $reason): void
    {
        $jv = \App\Models\JournalVoucher::where('source_type', $txn->getMorphClass())->where('source_id', $txn->id)->where('status', 'posted')->first();
        if ($jv) {
            $this->gl->reverse($jv, $reason);
        }
    }

    private function billStatus(WalletTransaction $txn): string
    {
        return match ($txn->status) {
            WalletTransactionStatus::Succeeded => 'succeeded',
            WalletTransactionStatus::Failed => 'failed',
            default => 'pending',
        };
    }

    private function dims(Wallet $wallet): array
    {
        $owner = $wallet->owner;

        return array_filter([
            'customer_id' => $owner instanceof Customer ? $owner->id : null,
            'vendor_id' => $owner instanceof Vendor ? $owner->id : null,
            'branch_id' => $owner instanceof Customer ? $owner->branch_id : null,
        ]);
    }

    private function afterCommitNotify(WalletTransaction $txn, string $event, string $title, string $body): void
    {
        DB::afterCommit(function () use ($txn, $event, $title, $body) {
            $wallet = $txn->wallet()->with('owner')->first();
            $owner = $wallet?->owner;
            if ($owner instanceof Customer) {
                $this->notifier->toCustomer($owner, $event, ['title' => $title, 'body' => $body, 'url' => '/app/wallet']);
            }
            $userId = $owner instanceof Customer ? $owner->user?->id : null;
            Realtime::emit(new WalletTransactionCompleted($txn->tenant_id, $userId, ['reference' => $txn->reference, 'type' => $txn->type->value, 'status' => $txn->fresh()->status->value]));
        });
    }

    /** "Umair Rana Khan" → "Umair R. K." – used wherever one customer's name is shown to another (QA S7). */
    public static function maskName(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)))->filter()->values()->map(fn ($p, $i) => $i === 0 ? $p : mb_substr($p, 0, 1).'.')->join(' ');
    }

    /** Simulated 1Bill biller directory. */
    public static function billers(): array
    {
        return [
            'LESCO' => ['name' => 'LESCO (Electricity)', 'type' => 'electricity'],
            'KE' => ['name' => 'K-Electric', 'type' => 'electricity'],
            'IESCO' => ['name' => 'IESCO (Electricity)', 'type' => 'electricity'],
            'SNGPL' => ['name' => 'SNGPL (Gas)', 'type' => 'gas'],
            'SSGC' => ['name' => 'SSGC (Gas)', 'type' => 'gas'],
            'PTCL' => ['name' => 'PTCL (Telephone / Internet)', 'type' => 'telephone'],
            'WASA' => ['name' => 'WASA Lahore (Water)', 'type' => 'water'],
        ];
    }
}
