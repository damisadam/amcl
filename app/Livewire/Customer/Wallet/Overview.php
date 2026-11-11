<?php

namespace App\Livewire\Customer\Wallet;

use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.customer')]
class Overview extends Component
{
    use WalletScreen;

    #[On('realtime')]
    public function refresh(): void {}

    public function activate(WalletService $wallets): void
    {
        $this->attempt(fn () => $wallets->activate($this->myWallet(), $this->user()), __('Wallet activated.'));
    }

    public function render(WalletService $wallets)
    {
        $w = $this->wallet();
        $debits = fn ($since) => $w ? Money::of(WalletTransaction::where('wallet_id', $w->id)->where('direction', 'debit')->whereIn('status', ['pending', 'processing', 'succeeded'])
            ->whereNotIn('type', ['reversal', 'refund', 'instalment_payment'])->where('created_at', '>=', $since)->sum('amount')) : '0.00';
        $used = $debits(today());

        return view('livewire.customer.wallet.overview', [
            'w' => $w,
            'missing' => $w ? $wallets->unmetPrerequisites($w) : [],
            'usedToday' => $used,
            'usedMonth' => $debits(now()->startOfMonth()),
            'pending' => $w ? WalletTransaction::where('wallet_id', $w->id)->whereIn('status', ['pending', 'processing'])->latest('id')->get() : collect(),
            'recent' => $w ? WalletTransaction::where('wallet_id', $w->id)->latest('id')->take(10)->get() : collect(),
        ])->title(__('My Wallet'));
    }
}
