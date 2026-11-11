<?php

namespace App\Livewire\Ops\Wallets;

use App\Enums\WalletStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.ops')]
class Show extends Component
{
    use HandlesActions, WithPagination;

    public Wallet $wallet;

    public string $tab = 'transactions';

    public bool $showLimits = false;

    public bool $showStatus = false;

    public array $limits = [];

    public string $limitReason = '';

    public string $targetStatus = '';

    public string $statusReason = '';

    public function mount(Wallet $wallet): void
    {
        $this->need('wallet.view');
        $this->wallet = $wallet;
        $this->resetLimits();
    }

    #[On('realtime')]
    public function refreshWallet(): void
    {
        $this->wallet->refresh();
    }

    private function resetLimits(): void
    {
        $this->limits = collect(['daily_limit', 'per_transaction_limit', 'monthly_limit', 'max_balance'])
            ->mapWithKeys(fn ($k) => [$k => (string) $this->wallet->{$k}])->all();
    }

    public function saveLimits(WalletService $wallets): void
    {
        $this->need('wallet.manage');
        $this->validate([
            'limits.daily_limit' => 'required|numeric|min:1', 'limits.per_transaction_limit' => 'required|numeric|min:1',
            'limits.monthly_limit' => 'required|numeric|min:1', 'limits.max_balance' => 'required|numeric|min:1',
            'limitReason' => 'required|string|min:5|max:300',
        ]);
        if (Money::gt($this->limits['per_transaction_limit'], $this->limits['daily_limit']) || Money::gt($this->limits['daily_limit'], $this->limits['monthly_limit'])) {
            $this->addError('limits.per_transaction_limit', __('Per-transaction ≤ daily ≤ monthly limit.'));

            return;
        }
        if ($this->attempt(fn () => $wallets->updateLimits($this->wallet, $this->limits, $this->limitReason), __('Limits updated.'))) {
            $this->showLimits = false;
            $this->limitReason = '';
            $this->wallet->refresh();
        }
    }

    public function openStatus(string $to): void
    {
        $this->need('wallet.manage');
        $this->targetStatus = $to;
        $this->statusReason = '';
        $this->showStatus = true;
    }

    public function changeStatus(WalletService $wallets): void
    {
        $this->need('wallet.manage');
        $this->validate(['targetStatus' => 'required|in:'.implode(',', WalletStatus::values()), 'statusReason' => 'required|string|min:5|max:300']);
        $to = WalletStatus::from($this->targetStatus);
        $ok = $this->attempt(function () use ($wallets, $to) {
            if ($to === WalletStatus::Active && $this->wallet->status === WalletStatus::Pending) {
                return $wallets->activate($this->wallet, auth()->user());
            }

            return $wallets->changeStatus($this->wallet, $to, $this->statusReason, auth()->user());
        }, __('Wallet status changed to :s.', ['s' => $to->label()]));
        if ($ok) {
            $this->showStatus = false;
            $this->wallet->refresh();
        }
    }

    public function render(WalletLedger $ledger, WalletService $wallets)
    {
        $this->need('wallet.view');
        $ledgerBalance = $ledger->ledgerBalance($this->wallet);
        $ledgerHeld = $ledger->ledgerHeld($this->wallet);
        $todayDebits = Money::of($this->wallet->transactions()->getQuery()->where('direction', 'debit')
            ->whereIn('status', ['pending', 'processing', 'succeeded'])->whereNotIn('type', ['reversal', 'refund', 'instalment_payment'])
            ->where('created_at', '>=', today())->sum('amount'));

        return view('livewire.ops.wallets.show', [
            'transactions' => $this->tab === 'transactions' ? $this->wallet->transactions()->paginate(15, pageName: 'tx') : null,
            'entries' => $this->tab === 'ledger' ? $this->wallet->ledgerEntries()->with('transaction')->reorder('id', 'desc')->paginate(20, pageName: 'le') : null,
            'integrity' => ['ledger' => $ledgerBalance, 'held' => $ledgerHeld, 'ok' => Money::eq($ledgerBalance, $this->wallet->balance) && Money::eq($ledgerHeld, $this->wallet->held_balance)],
            'unmet' => $wallets->unmetPrerequisites($this->wallet),
            'todayDebits' => $todayDebits,
            'transitions' => array_map(fn ($s) => $s, \App\Enums\WalletStatus::transitions()[$this->wallet->status->value] ?? []),
            'canManage' => auth()->user()->hasPermission('wallet.manage'),
        ])->title($this->wallet->wallet_no);
    }
}
