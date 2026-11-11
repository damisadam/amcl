<?php

namespace App\Livewire\Ops\Wallets;

use App\Enums\WalletStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Customer;
use App\Models\Wallet;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Wallet register: customer and merchant wallets of this tenant. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterOwner = '';

    public function mount(): void
    {
        $this->need('wallet.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function render()
    {
        $this->need('wallet.view');
        $q = Wallet::query()->with('owner')
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterOwner === 'customer', fn ($q) => $q->where('owner_type', (new Customer)->getMorphClass()))
            ->when($this->filterOwner === 'merchant', fn ($q) => $q->where('owner_type', '!=', (new Customer)->getMorphClass()));
        $this->applySearch($q, ['wallet_no', 'title', 'mobile']);

        $totals = (clone $q)->reorder()->selectRaw('COUNT(*) c, COALESCE(SUM(balance),0) b, COALESCE(SUM(held_balance),0) h')->first();

        return view('livewire.ops.wallets.index', [
            'wallets' => $this->applySort($q, 'id', ['id', 'wallet_no', 'balance', 'status', 'created_at'])->paginate($this->perPage),
            'statuses' => WalletStatus::options(),
            'totals' => ['count' => (int) $totals->c, 'balance' => Money::of($totals->b), 'held' => Money::of($totals->h)],
            'model' => \App\Support\TenantContext::get()->wallet_safeguarding_model,
        ])->title(__('Wallets'));
    }
}
