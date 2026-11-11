<?php

namespace App\Livewire\Customer\Wallet;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentOrchestrator;

/** Shared wallet screen helpers: the wallet always belongs to the logged-in customer. */
trait WalletScreen
{
    use CustomerContext;

    public string $scenario = 'success';

    public ?int $lastTxn = null;

    protected function myWallet(): Wallet
    {
        return $this->wallet() ?? abort(404, __('You do not have a wallet yet. Complete identity verification first.'));
    }

    protected function ownTxn(int $id): WalletTransaction
    {
        return WalletTransaction::where('wallet_id', $this->myWallet()->id)->findOrFail($id);
    }

    /**
     * Why money actions are unavailable right now (null = usable). Forms are disabled up front with this
     * reason and a next step instead of failing at the PIN step (QA M5).
     */
    public function blockReason(): ?string
    {
        $w = $this->wallet();
        if (! $w) {
            return __('You do not have a wallet yet. Complete identity verification first.');
        }

        return match ($w->status->value) {
            'active' => $w->pin_attempts >= 5 ? __('Your wallet PIN is locked after too many incorrect attempts.') : null,
            'pending' => __('Your wallet is not active yet. Complete identity verification (KYC) to activate it.'),
            'restricted' => __('Your wallet is restricted: :r', ['r' => $w->status_reason ?: __('under review by the institution')]),
            'suspended' => __('Your wallet is suspended: :r', ['r' => $w->status_reason ?: __('contact support')]),
            default => __('Your wallet is closed.'),
        };
    }

    /** Next step for a blocked wallet: [label, url]. */
    public function blockAction(): array
    {
        $w = $this->wallet();

        return (! $w || $w->status->value === 'pending')
            ? [__('Complete verification'), route('app.onboarding')]
            : [__('Contact support'), route('app.support')];
    }

    /** Server-side guard for every money action; returns true when the action must stop. */
    protected function walletBlocked(): bool
    {
        if ($reason = $this->blockReason()) {
            $this->addError('action', $reason);

            return true;
        }

        return false;
    }

    /** Run a PIN-authorised action; a wrong/locked PIN is shown inline on the PIN field (QA M6). */
    protected function withPin(callable $fn, ?string $success = null): mixed
    {
        try {
            $result = $fn();
            if ($success) {
                $this->toast($success);
            }
            $this->idem = (string) \Illuminate\Support\Str::uuid();

            return $result;
        } catch (\App\Exceptions\DomainException $e) {
            str_contains($e->getMessage(), 'PIN') ? $this->addError('pin', $e->getMessage()) : $this->addError('action', $e->getMessage());
            $this->toast($e->getMessage(), 'red');

            return null;
        }
    }

    public function simulateCallback(int $txnId, string $status, PaymentOrchestrator $orchestrator): void
    {
        abort_unless($this->demo(), 403);
        $txn = $this->ownTxn($txnId);
        $order = $txn->paymentOrder ?? abort(404);
        $this->attempt(fn () => $this->toast(__('Simulated callback: :r', ['r' => $orchestrator->simulate($order, $status)]), 'blue'));
    }

    public function checkStatus(int $txnId, PaymentOrchestrator $orchestrator): void
    {
        $txn = $this->ownTxn($txnId);
        $this->attempt(fn () => $orchestrator->inquire($txn->paymentOrder ?? abort(404)), __('Status refreshed.'));
    }
}
