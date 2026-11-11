<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\WalletTransaction;
use App\Services\Wallet\LinkedAccountService;
use App\Services\Wallet\WalletService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Wallet → bank withdrawal over Raast (Wallet §3). Funds are held until the payout is confirmed. */
#[Layout('layouts.customer')]
class Withdraw extends Component
{
    use WalletScreen;

    public ?int $account = null;

    public string $amount = '';

    public string $pin = '';

    public string $newBank = 'Meezan Bank';

    public string $newIban = '';

    public bool $adding = false;

    public function mount(): void
    {
        $this->account = $this->customer()->linkedBankAccounts()->orderByDesc('is_default')->value('id');
        $this->adding = ! $this->account;
    }

    public function link(LinkedAccountService $svc): void
    {
        $this->validate(['newBank' => 'required|string', 'newIban' => ['required', 'regex:/^PK\d{2}[A-Za-z]{4}\d{16}$/']], ['newIban.regex' => __('Enter a 24-character Pakistani IBAN, e.g. PK36MEZN0001234567890123.')]);
        $acc = $this->attempt(fn () => $svc->link($this->customer(), $this->newBank, $this->newIban), __('Bank account verified and linked.'));
        if ($acc) {
            $this->account = $acc->id;
            $this->adding = false;
            $this->newIban = '';
        }
    }

    public function submit(WalletService $wallets): void
    {
        if ($this->walletBlocked()) {
            return;
        }
        $w = $this->myWallet();
        $this->validate(['account' => 'required|integer', 'amount' => 'required|numeric|min:100|max:'.$w->per_transaction_limit, 'pin' => 'required|digits_between:4,6']);
        $acc = $this->customer()->linkedBankAccounts()->findOrFail($this->account);
        $txn = $this->withPin(fn () => $wallets->withdraw($w, $this->amount, $acc, 'withdraw:'.$this->idem, $this->pin, $this->demo() ? $this->scenario : null, $this->user()));
        $this->pin = '';
        if ($txn instanceof WalletTransaction) {
            $this->lastTxn = $txn->id;
            $this->amount = '';
            $this->toast($txn->status->value === 'failed' ? __('Withdrawal failed; funds released.') : __('Withdrawal submitted.'), $txn->status->value === 'failed' ? 'red' : 'green');
        }
    }

    public function render()
    {
        return view('livewire.customer.wallet.withdraw', [
            'w' => $this->myWallet(), 'accounts' => $this->customer()->linkedBankAccounts()->get(), 'banks' => LinkedAccountService::BANKS,
            'txn' => $this->lastTxn ? $this->ownTxn($this->lastTxn) : null, 'scenarios' => config('integrations.payment_scenarios'),
        ])->title(__('Withdraw'));
    }
}
