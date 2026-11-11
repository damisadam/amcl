<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\BillPayment;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Utility bill payment through 1Bill (Wallet §5): inquiry → confirm → pay from wallet. */
#[Layout('layouts.customer')]
class Bills extends Component
{
    use WalletScreen;

    public string $biller = 'LESCO';

    public string $consumerRef = '';

    public ?array $bill = null;

    public string $pin = '';

    public function inquire(WalletService $wallets): void
    {
        $this->validate(['biller' => 'required|in:'.implode(',', array_keys(WalletService::billers())), 'consumerRef' => 'required|digits_between:8,14'],
            ['consumerRef.digits_between' => __('Enter the 8–14 digit reference number printed on your bill.')]);
        $this->bill = null;
        $res = $this->attempt(fn () => $wallets->inquireBill($this->biller, $this->consumerRef));
        if ($res && ! $res->successful()) {
            $this->addError('consumerRef', $res->message);
        } elseif ($res) {
            $this->bill = $res->data;
        }
    }

    public function pay(WalletService $wallets): void
    {
        if ($this->walletBlocked()) {
            return;
        }
        $this->validate(['pin' => 'required|digits_between:4,6']);
        $bp = $this->withPin(fn () => $wallets->payBill($this->myWallet(), $this->biller, $this->consumerRef, 'bill:'.$this->idem, $this->pin, $this->demo() ? $this->scenario : null, $this->user()));
        $this->pin = '';
        if ($bp instanceof BillPayment) {
            $this->lastTxn = $bp->wallet_transaction_id;
            $this->bill = null;
            $this->consumerRef = '';
            $this->toast($bp->status === 'failed' ? __('Bill payment failed; funds released.') : __('Bill payment submitted.'), $bp->status === 'failed' ? 'red' : 'green');
        }
    }

    public function render()
    {
        return view('livewire.customer.wallet.bills', [
            'w' => $this->myWallet(), 'billers' => collect(WalletService::billers())->map(fn ($b) => $b['name'])->all(),
            'history' => BillPayment::where('customer_id', $this->customer()->id)->latest('id')->take(10)->get(),
            'txn' => $this->lastTxn ? $this->ownTxn($this->lastTxn) : null, 'scenarios' => config('integrations.payment_scenarios'),
        ])->title(__('Pay bills'));
    }
}
