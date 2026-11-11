<?php

namespace App\Livewire\Ops\Reports;

use App\Livewire\Concerns\HandlesActions;
use App\Services\Reports\CsvExport;
use App\Services\Reports\WalletReports;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Wallet extends Component
{
    use HandlesActions;

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->need('reports.wallet');
        $this->from = now()->subDays(30)->toDateString();
        $this->to = today()->toDateString();
    }

    public function export()
    {
        $r = new WalletReports(['from' => $this->from, 'to' => $this->to]);

        return CsvExport::download('wallet-transactions', ['Type', 'Status', 'Count', 'Amount'], collect($r->volumes())->map(fn ($x) => array_values($x)), ['from' => $this->from, 'to' => $this->to]);
    }

    public function render()
    {
        $r = new WalletReports(['from' => $this->from, 'to' => $this->to]);
        $volumes = collect($r->volumes());

        return view('livewire.ops.reports.wallet', [
            'balances' => $r->balances(), 'volumes' => $volumes, 'daily' => $r->daily(), 'recon' => $r->reconciliation(), 'three' => $r->threeWay(),
            'byType' => $volumes->where('status', 'succeeded')->groupBy('type')->map(fn ($g) => ['count' => $g->sum('count'), 'amount' => \App\Support\Money::add('0', ...$g->pluck('amount')->all())]),
            'failed' => $volumes->where('status', 'failed')->sum('count'),
            'reversed' => $volumes->whereIn('status', ['reversed', 'refunded'])->sum('count'),
            'pending' => $volumes->whereIn('status', ['pending', 'processing'])->sum('count'),
        ]);
    }
}
