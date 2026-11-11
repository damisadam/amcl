<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\WalletTransaction;
use App\Services\Wallet\LinkedAccountService;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Bank / mobile-wallet → wallet top-up (Wallet §2). Funds land in the safeguarded account (simulated). */
#[Layout('layouts.customer')]
class TopUp extends Component
{
    use WalletScreen;

    public string $amount = '';

    public string $source = 'raast';

    public string $bank = 'Meezan Bank';

    public const SOURCES = ['raast' => 'Bank account via Raast', 'easypaisa' => 'Easypaisa', 'jazzcash' => 'JazzCash', 'sponsor_bank' => 'Sponsor bank transfer (IBFT)'];

    public function submit(WalletService $wallets): void
    {
        if ($this->walletBlocked()) {
            return;
        }
        $w = $this->myWallet();
        $this->validate(['amount' => 'required|numeric|min:100|max:'.$w->per_transaction_limit, 'source' => 'required|in:'.implode(',', array_keys(self::SOURCES)),
            'bank' => 'required_if:source,raast,sponsor_bank|nullable|string|max:60']);
        $sourceBank = in_array($this->source, ['raast', 'sponsor_bank'], true) ? $this->bank : ucfirst($this->source);
        $txn = $this->attempt(fn () => $wallets->topUp($w, $this->amount, $this->source, 'topup:'.$this->idem, $this->demo() ? $this->scenario : null, $this->user(), $sourceBank));
        if ($txn instanceof WalletTransaction) {
            $this->lastTxn = $txn->id;
            $this->toast(match ($txn->status->value) { 'succeeded' => __('Top-up successful.'), 'failed' => __('Top-up failed.'), default => __('Top-up initiated – awaiting bank confirmation.') },
                $txn->status->value === 'failed' ? 'red' : 'green');
            $this->amount = '';
        }
    }

    public function render()
    {
        return view('livewire.customer.wallet.topup', [
            'w' => $this->myWallet(), 'txn' => $this->lastTxn ? $this->ownTxn($this->lastTxn) : null,
            'banks' => LinkedAccountService::BANKS, 'scenarios' => config('integrations.payment_scenarios'),
        ])->title(__('Top up'));
    }
}
