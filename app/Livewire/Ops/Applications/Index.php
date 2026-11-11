<?php

namespace App\Livewire\Ops\Applications;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\User;
use App\Policies\RecordAccess;
use App\Services\Audit\Auditor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Application work queues (LMS §6) with branch scoping. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    public const TABS = [
        'active' => ['submitted', 'under_review', 'pending_approval', 'returned'],
        'submitted' => ['submitted'], 'under_review' => ['under_review'], 'pending_approval' => ['pending_approval'], 'returned' => ['returned'],
        'approved' => ['approved', 'kfs_acknowledged', 'cooling_off', 'ready_for_disbursement', 'disbursement_in_progress'],
        'disbursed' => ['disbursed'], 'rejected' => ['rejected'], 'closed' => ['withdrawn', 'cancelled'], 'draft' => ['draft'], 'all' => [],
    ];

    #[Url] public string $tab = 'active';
    #[Url(except: '')] public string $filterProduct = '';
    #[Url(except: '')] public string $filterBranch = '';
    #[Url(except: '')] public string $filterChannel = '';
    #[Url(except: '')] public string $filterOfficer = '';
    #[Url(except: '')] public string $from = '';
    #[Url(except: '')] public string $to = '';

    public function mount(): void
    {
        $this->need('applications.view');
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'active';
        }
    }

    #[On('realtime')]
    public function refreshQueue(): void {}

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    private function base()
    {
        return RecordAccess::applicationsQuery(LoanApplication::query(), auth()->user());
    }

    private function query()
    {
        $q = $this->base()->with(['customer', 'product', 'branch', 'officer', 'approvalRequest.currentStage']);
        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $q->where(fn ($w) => $w->where('application_no', 'like', $term)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $term)->orWhere('cif_no', 'like', $term)->orWhere('mobile', 'like', $term)));
        }

        return $q->when(self::TABS[$this->tab] ?? [], fn ($w, $st) => $w->whereIn('status', $st))
            ->when($this->filterProduct, fn ($w) => $w->where('product_id', $this->filterProduct))
            ->when($this->filterBranch, fn ($w) => $w->where('branch_id', $this->filterBranch))
            ->when($this->filterChannel, fn ($w) => $w->where('channel', $this->filterChannel))
            ->when($this->filterOfficer, fn ($w) => $w->where('loan_officer_id', $this->filterOfficer))
            ->when($this->from, fn ($w) => $w->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($w) => $w->whereDate('created_at', '<=', $this->to));
    }

    public function export()
    {
        $this->need('reports.export');
        $rows = $this->query()->orderBy('id')->get();
        Auditor::record('applications.exported', null, [], ['tab' => $this->tab, 'rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Application', 'Customer', 'CIF', 'Product', 'Channel', 'Branch', 'Requested', 'Tenure', 'Status', 'Grade', 'Risk', 'DBR', 'Submitted', 'Approved', 'Rejected', 'Rejection code']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->application_no, $a->customer->full_name, $a->customer->cif_no, $a->product->name, $a->channel, $a->branch?->name, $a->requested_amount, $a->tenure,
                    $a->status->label(), $a->credit_grade, $a->risk_rating, $a->dbr, $a->submitted_at?->toDateString(), $a->approved_at?->toDateString(), $a->rejected_at?->toDateString(), $a->rejection_code]);
            }
            fclose($out);
        }, 'applications-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $counts = $this->base()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $tabCounts = collect(self::TABS)->map(fn ($st) => $st ? collect($st)->sum(fn ($s) => $counts[$s] ?? 0) : $counts->sum());

        return view('livewire.ops.applications.index', [
            'applications' => $this->applySort($this->query(), 'id', ['application_no', 'requested_amount', 'created_at', 'submitted_at'])->paginate($this->perPage),
            'tabCounts' => $tabCounts,
            'products' => Product::orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'officers' => User::where('type', 'staff')->whereHas('roles', fn ($r) => $r->whereIn('key', ['loan_officer', 'branch_manager']))->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
}
