<?php

namespace App\Livewire\Ops\WalletTransactions;

use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** All wallet transactions with detail and operator reversal / refund of internal transactions. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')]
    public string $filterType = '';

    #[Url(except: '')]
    public string $filterStatus = '';

    #[Url(except: '')]
    public string $filterExternal = '';

    #[Url(except: '')]
    public string $filterFrom = '';

    #[Url(except: '')]
    public string $filterTo = '';

    public ?int $detailId = null;

    public bool $showDetail = false;

    public bool $showReverse = false;

    public string $reverseReason = '';

    public function mount(): void
    {
        $this->need('wallet.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function view(int $id): void
    {
        $this->need('wallet.view');
        $this->detailId = WalletTransaction::findOrFail($id)->id;
        $this->showDetail = true;
    }

    public function askReverse(int $id): void
    {
        $this->need('wallet.reverse');
        $this->detailId = WalletTransaction::findOrFail($id)->id;
        $this->reverseReason = '';
        $this->showDetail = false;
        $this->showReverse = true;
    }

    public function reverse(WalletService $wallets): void
    {
        $this->need('wallet.reverse');
        $this->validate(['reverseReason' => 'required|string|min:5|max:500']);
        $txn = WalletTransaction::findOrFail($this->detailId);
        if ($this->attempt(fn () => $wallets->reverseInternal($txn, $this->reverseReason, auth()->user()), __('Transaction reversed with compensating ledger entries.'))) {
            $this->showReverse = false;
        }
    }

    private function query()
    {
        $q = WalletTransaction::query()->with('wallet')
            ->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterExternal !== '', fn ($q) => $q->where('is_external', $this->filterExternal === '1'))
            ->when($this->filterFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->filterFrom))
            ->when($this->filterTo, fn ($q) => $q->whereDate('created_at', '<=', $this->filterTo));

        return $this->applySearch($q, ['reference', 'description', 'idempotency_key']);
    }

    public function render()
    {
        $this->need('wallet.view');
        $q = $this->query();
        $sums = (clone $q)->reorder()->selectRaw("COUNT(*) c, COALESCE(SUM(CASE WHEN direction='credit' AND status='succeeded' THEN amount END),0) cr,
            COALESCE(SUM(CASE WHEN direction='debit' AND status='succeeded' THEN amount END),0) dr, SUM(status='failed') f, SUM(status IN ('pending','processing')) p")->first();
        $detail = $this->detailId ? WalletTransaction::with(['wallet', 'counterpartyWallet', 'related', 'paymentOrder.callbacks', 'ledgerEntries'])->find($this->detailId) : null;

        return view('livewire.ops.wallet-transactions.index', [
            'rows' => $this->applySort($q, 'id', ['id', 'amount', 'created_at', 'status', 'type'])->paginate($this->perPage),
            'types' => WalletTransactionType::options(),
            'statuses' => WalletTransactionStatus::options(),
            'sums' => ['count' => (int) $sums->c, 'credit' => Money::of($sums->cr), 'debit' => Money::of($sums->dr), 'failed' => (int) $sums->f, 'pending' => (int) $sums->p],
            'detail' => $detail,
            'canReverse' => auth()->user()->hasPermission('wallet.reverse'),
        ])->title(__('Wallet transactions'));
    }

    public static function reversible(WalletTransaction $t): bool
    {
        return $t->status === WalletTransactionStatus::Succeeded && ! $t->is_external
            && in_array($t->type, [WalletTransactionType::TransferOut, WalletTransactionType::MerchantPayment], true);
    }
}
