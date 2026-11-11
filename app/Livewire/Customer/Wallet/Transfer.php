<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\Customer;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Kyc\OnboardingService;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Wallet-to-wallet transfer within the same institution (Wallet §4): ledger only, instant. */
#[Layout('layouts.customer')]
class Transfer extends Component
{
    use WalletScreen;

    public string $mobile = '';

    public string $amount = '';

    public string $note = '';

    public string $pin = '';

    public ?string $recipientName = null;

    public bool $confirming = false;

    public function review(): void
    {
        $this->resetErrorBag(); // stale messages from a previous attempt are cleared
        if ($this->walletBlocked()) {
            return;
        }
        // Recipient lookups are rate-limited to stop mobile-number enumeration (QA S7).
        $key = 'wallet-lookup:'.$this->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('mobile', __('Too many recipient lookups. Try again in :s seconds.', ['s' => \Illuminate\Support\Facades\RateLimiter::availableIn($key)]));

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        $w = $this->myWallet();
        $this->validate(['mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'], 'amount' => 'required|numeric|min:1|max:'.$w->per_transaction_limit, 'note' => 'nullable|string|max:100'],
            ['mobile.regex' => __('Enter a valid Pakistani mobile number.')]);
        $mobile = OnboardingService::normaliseMobile($this->mobile);
        $to = Wallet::where('mobile', $mobile)->where('owner_type', (new Customer)->getMorphClass())->first();
        if (! $to || $to->id === $w->id || ! $to->isActive()) {
            $this->addError('mobile', $to && $to->id === $w->id ? __('You cannot transfer to your own wallet.') : __('No active wallet is registered to that mobile number at this institution.'));

            return;
        }
        // Show a masked name only, to confirm the right recipient without exposing personal data.
        $this->recipientName = WalletService::maskName($to->title);
        $this->confirming = true;
    }

    public function send(WalletService $wallets): void
    {
        if ($this->walletBlocked()) {
            return;
        }
        $this->validate(['pin' => 'required|digits_between:4,6']);
        $txn = $this->withPin(fn () => $wallets->transfer($this->myWallet(), OnboardingService::normaliseMobile($this->mobile), $this->amount, 'transfer:'.$this->idem, $this->pin, $this->user(), $this->note ?: null),
            __('Money sent.'));
        $this->pin = '';
        if ($txn instanceof WalletTransaction) {
            $this->lastTxn = $txn->id;
            $this->reset('mobile', 'amount', 'note', 'confirming', 'recipientName');
        }
    }

    public function render()
    {
        return view('livewire.customer.wallet.transfer', ['w' => $this->myWallet(), 'txn' => $this->lastTxn ? $this->ownTxn($this->lastTxn) : null])->title(__('Send money'));
    }
}
