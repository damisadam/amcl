<?php

namespace App\Livewire\Customer;

use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\LoanPayment;
use App\Models\WalletTransaction;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Unified history: wallet transactions and financing repayments (Wallet §7). */
#[Layout('layouts.customer')]
class Transactions extends Component
{
    use CustomerContext, WithPagination;

    #[Url]
    public string $view = 'wallet';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?int $detail = null;

    public function updated($p): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('type', 'status', 'from', 'to');
        $this->resetPage('wp');
        $this->resetPage('lp');
    }

    /** CSV of the customer's own wallet transactions for the current filters (UI redesign: Transaction History). */
    public function export()
    {
        $w = $this->wallet();
        abort_unless($w, 404);
        $rows = WalletTransaction::where('wallet_id', $w->id)
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id')->get();
        \App\Services\Audit\Auditor::record('customer.transactions_exported', $this->customer(), [], ['rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Reference', 'Type', 'Description', 'Direction', 'Amount', 'Status']);
            foreach ($rows as $t) {
                fputcsv($out, [$t->created_at->format('Y-m-d H:i'), $t->reference, $t->type->label(), $t->description, $t->direction, (string) $t->amount, $t->status->value]);
            }
            fclose($out);
        }, 'wallet-transactions-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(int $id): void
    {
        $w = $this->wallet();
        abort_unless($w && WalletTransaction::where('wallet_id', $w->id)->whereKey($id)->exists(), 404);
        $this->detail = $id;
    }

    public function render()
    {
        $w = $this->wallet();
        $c = $this->customer();
        $walletQuery = $w ? WalletTransaction::where('wallet_id', $w->id)
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to)) : null;
        $wallet = $walletQuery ? (clone $walletQuery)->latest('id')->paginate(15, pageName: 'wp') : null;
        // Summary cards for the current filters: money in/out count succeeded transactions only.
        $summary = $walletQuery ? [
            'count' => (clone $walletQuery)->count(),
            'in' => Money::of((clone $walletQuery)->where('status', 'succeeded')->where('direction', 'credit')->sum('amount')),
            'out' => Money::of((clone $walletQuery)->where('status', 'succeeded')->where('direction', 'debit')->sum('amount')),
        ] : null;
        $loanPayments = LoanPayment::with('loanAccount')->where('customer_id', $c->id)
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id')->paginate(15, pageName: 'lp');

        return view('livewire.customer.transactions', [
            'wallet' => $wallet, 'loanPayments' => $loanPayments, 'hasWallet' => (bool) $w, 'summary' => $summary,
            'types' => \App\Enums\WalletTransactionType::options(), 'statuses' => \App\Enums\WalletTransactionStatus::options(),
            'd' => $this->detail ? WalletTransaction::with(['paymentOrder', 'counterpartyWallet'])->find($this->detail) : null,
        ])->title(__('Transaction History'));
    }
}
