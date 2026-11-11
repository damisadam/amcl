<?php

namespace App\Livewire\Ops\Loans;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\Product;
use App\Models\User;
use App\Policies\RecordAccess;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')] public string $filterStatus = '';
    #[Url(except: '')] public string $filterClass = '';
    #[Url(except: '')] public string $filterDpd = '';
    #[Url(except: '')] public string $filterProduct = '';
    #[Url(except: '')] public string $filterBranch = '';
    #[Url(except: '')] public string $filterOfficer = '';

    public function mount(): void
    {
        $this->need('loans.view');
    }

    #[On('realtime')]
    public function refreshList(): void {}

    protected function query(): Builder
    {
        $q = RecordAccess::loansQuery(LoanAccount::query(), auth()->user());
        $q->when($this->filterStatus, fn ($w) => $w->where('status', $this->filterStatus))
          ->when($this->filterClass, fn ($w) => $w->where('classification', $this->filterClass))
          ->when($this->filterProduct, fn ($w) => $w->where('product_id', $this->filterProduct))
          ->when($this->filterBranch, fn ($w) => $w->where('branch_id', $this->filterBranch))
          ->when($this->filterOfficer, fn ($w) => $w->where('loan_officer_id', $this->filterOfficer))
          ->when($this->filterDpd, fn ($w) => match ($this->filterDpd) {
              'current' => $w->where('dpd', 0), '1-30' => $w->whereBetween('dpd', [1, 30]), '31-60' => $w->whereBetween('dpd', [31, 60]),
              '61-90' => $w->whereBetween('dpd', [61, 90]), '90+' => $w->where('dpd', '>', 90), default => $w,
          });
        if (trim($this->search) !== '') {
            $t = '%'.trim($this->search).'%';
            $q->where(fn ($w) => $w->where('account_no', 'like', $t)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $t)->orWhere('cif_no', 'like', $t)->orWhere('mobile', 'like', $t)));
        }

        return $q;
    }

    public function export()
    {
        $this->need('reports.export');
        $rows = $this->query()->with(['customer', 'product', 'branch'])->orderBy('id')->get();
        \App\Services\Audit\Auditor::record('loans.exported', null, [], ['rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account', 'Customer', 'CIF', 'Product', 'Branch', 'Status', 'Classification', 'DPD', 'Principal', 'Principal outstanding', 'Total outstanding', 'Total paid', 'Disbursed', 'Maturity']);
            foreach ($rows as $l) {
                fputcsv($out, [$l->account_no, $l->customer->full_name, $l->customer->cif_no, $l->product->name, $l->branch?->name, $l->status->value, $l->classification, $l->dpd,
                    $l->principal, $l->principal_outstanding, $l->totalOutstanding(), $l->total_paid, $l->disbursed_at->toDateString(), $l->maturity_date->toDateString()]);
            }
            fclose($out);
        }, 'loan-portfolio-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $totals = (clone $this->query())->reorder()->selectRaw('COUNT(*) n, COALESCE(SUM(principal),0) principal, COALESCE(SUM(principal_outstanding),0) po,
            COALESCE(SUM(principal_outstanding + profit_outstanding + charges_outstanding + late_charges_outstanding),0) outstanding, SUM(CASE WHEN dpd > 0 THEN 1 ELSE 0 END) overdue,
            COALESCE(SUM(CASE WHEN dpd > 30 THEN principal_outstanding ELSE 0 END),0) par30')->first();
        $loans = $this->applySort($this->query()->with(['customer', 'product', 'branch', 'officer']), 'id', ['id', 'dpd', 'principal_outstanding', 'disbursed_at', 'maturity_date'])->paginate($this->perPage);

        return view('livewire.ops.loans.index', [
            'loans' => $loans, 'totals' => $totals,
            'products' => Product::orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'officers' => User::where('type', 'staff')->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
}
