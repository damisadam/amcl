<?php

namespace App\Livewire\Ops\Reports;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Branch;
use App\Models\Product;
use App\Services\Reports\CsvExport;
use App\Services\Reports\LendingReports;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Lending extends Component
{
    use HandlesActions;

    #[Url]
    public string $tab = 'pipeline';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public string $branch_id = '';

    public string $product_id = '';

    public string $groupBy = 'product';

    public function mount(): void
    {
        $this->need('reports.lms');
        $this->from = $this->from ?: now()->subMonths(6)->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    private function filters(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'branch_id' => $this->branch_id, 'product_id' => $this->product_id]);
    }

    public function export()
    {
        $r = new LendingReports($this->filters());
        [$headers, $rows] = match ($this->tab) {
            'portfolio' => [['Group', 'Accounts', 'Disbursed', 'Principal outstanding', 'Total outstanding', 'PAR30 principal'], collect($r->portfolio($this->groupBy))->map(fn ($x) => array_values($x))],
            'quality' => [['Bucket', 'Accounts', 'Outstanding', '%'], collect($r->par()['buckets'])->map(fn ($x) => array_values($x))],
            'collections' => [['Month', 'Due', 'Paid against due', 'Collected', 'Payments', 'Efficiency %'], collect($r->collections())->map(fn ($x) => array_values($x))],
            'disbursements' => [['Month', 'Count', 'Amount'], collect($r->disbursements()['by_month'])->map(fn ($x) => array_values($x))],
            default => [['Status', 'Count', 'Amount'], collect($r->funnel()['by_status'])->map(fn ($x) => array_values($x))],
        };

        return CsvExport::download('lending-'.$this->tab, $headers, $rows, $this->filters());
    }

    public function render()
    {
        $r = new LendingReports($this->filters());

        return view('livewire.ops.reports.lending', [
            'data' => match ($this->tab) {
                'pipeline' => ['funnel' => $r->funnel(), 'decisions' => $r->decisions(), 'tat' => $r->turnaround()],
                'disbursements' => $r->disbursements(),
                'portfolio' => ['rows' => $r->portfolio($this->groupBy), 'closures' => $r->closures()],
                'quality' => $r->par(),
                'collections' => ['months' => $r->collections(), 'timeliness' => $r->timeliness()],
                'field' => ['visits' => $r->visits(), 'recovery' => $r->recovery()],
                default => [],
            },
            'branches' => Branch::pluck('name', 'id')->all(),
            'products' => Product::pluck('name', 'id')->all(),
            'period' => LendingReports::periodLabel($this->from, $this->to),
        ]);
    }
}
