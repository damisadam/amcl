<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Merchant QR payment (Wallet §6). Merchants with a wallet are paid internally; others via Raast. */
#[Layout('layouts.customer')]
class Merchant extends Component
{
    use WalletScreen;

    public string $code = '';

    public string $amount = '';

    public string $pin = '';

    public ?string $merchantName = null;

    public function lookup(): void
    {
        $this->validate(['code' => 'required|string|max:30']);
        $m = Vendor::where('merchant_code', strtoupper(trim($this->code)))->where('is_merchant', true)->where('status', 'active')->first();
        $this->merchantName = $m?->business_name;
        if (! $m) {
            $this->addError('code', __('Merchant not found at this institution.'));
        }
    }

    public function pick(string $code): void
    {
        $this->code = $code;
        $this->lookup();
    }

    public function pay(WalletService $wallets): void
    {
        if ($this->walletBlocked()) {
            return;
        }
        $w = $this->myWallet();
        $this->validate(['code' => 'required', 'amount' => 'required|numeric|min:1|max:'.$w->per_transaction_limit, 'pin' => 'required|digits_between:4,6']);
        $txn = $this->withPin(fn () => $wallets->payMerchant($w, strtoupper(trim($this->code)), $this->amount, 'merchant:'.$this->idem, $this->pin, $this->demo() ? $this->scenario : null, $this->user()));
        $this->pin = '';
        if ($txn instanceof WalletTransaction) {
            $this->lastTxn = $txn->id;
            $this->reset('amount');
            $this->toast($txn->status->value === 'succeeded' ? __('Paid to :m.', ['m' => $this->merchantName]) : __('Payment submitted.'));
        }
    }

    public function render()
    {
        return view('livewire.customer.wallet.merchant', [
            'w' => $this->myWallet(), 'txn' => $this->lastTxn ? $this->ownTxn($this->lastTxn) : null, 'scenarios' => config('integrations.payment_scenarios'),
            'merchants' => Vendor::where('is_merchant', true)->where('status', 'active')->orderBy('business_name')->take(8)->get(['business_name', 'merchant_code', 'city']),
        ])->title(__('Pay merchant'));
    }
}
