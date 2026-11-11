<?php

namespace App\Livewire\Ops\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\Concerns\HandlesActions;
use App\Models\ApprovalAction;
use App\Models\ApprovalRequest;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Unified approval inbox for loans and all finance approval types. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithPagination;

    #[Url] public string $tab = 'mine';
    #[Url(except: '')] public string $filterType = '';

    public ?int $selectedId = null;
    public string $comment = '';
    public string $delegateTo = '';
    public bool $showDecision = false;

    public function mount(): void
    {
        $this->need('approvals.act');
    }

    #[On('realtime')]
    public function refreshInbox(): void {}

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->need('approvals.act');
        $this->selectedId = ApprovalRequest::findOrFail($id)->id;
        $this->comment = '';
        $this->delegateTo = '';
        $this->resetErrorBag();
        $this->showDecision = true;
    }

    public function decide(string $action): void
    {
        $this->need('approvals.act');
        $req = ApprovalRequest::findOrFail($this->selectedId);
        if ($action === 'approve' && $req->type === \App\Enums\ApprovalType::JournalReversal && ! $this->stepUp()) {
            return;
        }
        $engine = app(ApprovalEngine::class);
        if (in_array($action, ['reject', 'return'], true)) {
            $this->validate(['comment' => 'required|string|min:5|max:2000']);
        }
        if ($action === 'delegate') {
            $this->validate(['delegateTo' => 'required|integer']);
        }
        $ok = $this->attempt(fn () => match ($action) {
            'approve' => $engine->approve($req, auth()->user(), $this->comment ?: null),
            'reject' => $engine->reject($req, auth()->user(), $this->comment),
            'return' => $engine->returnForCorrection($req, auth()->user(), $this->comment),
            'delegate' => $engine->delegate($req, auth()->user(), User::findOrFail($this->delegateTo), $this->comment ?: 'Delegated'),
        }, match ($action) { 'approve' => __('Approved.'), 'reject' => __('Rejected.'), 'return' => __('Returned for correction.'), default => __('Delegated.') });
        if ($ok) {
            $this->showDecision = false;
        }
    }

    /** Pending requests whose current stage this user could act on (role match or delegation). */
    private function actionableQuery()
    {
        $u = auth()->user();
        $roleIds = $u->roles->pluck('id');

        return ApprovalRequest::where('status', ApprovalStatus::Pending->value)
            ->where(fn ($q) => $q->where('assigned_to', $u->id)->orWhere(fn ($w) => $w->whereNull('assigned_to')->whereHas('currentStage', fn ($s) => $s->whereIn('role_id', $roleIds))));
    }

    public function render()
    {
        $u = auth()->user();
        $engine = app(ApprovalEngine::class);
        $with = ['approvable', 'currentStage.role', 'requester', 'workflow'];

        if ($this->tab === 'completed') {
            $ids = ApprovalAction::where('actor_id', $u->id)->whereIn('action', ['approve', 'reject', 'return', 'delegate'])->pluck('approval_request_id')->unique();
            $rows = ApprovalRequest::with($with)->whereIn('id', $ids)->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))->latest('updated_at')->paginate(15);
        } elseif ($this->tab === 'all') {
            $rows = ApprovalRequest::with($with)->where('status', 'pending')->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))->oldest('stage_entered_at')->paginate(15);
        } else {
            // canAct() applies SoD rules (own requests, already-acted stages) – filter in PHP, then paginate.
            $all = $this->actionableQuery()->with($with)->when($this->filterType, fn ($q) => $q->where('type', $this->filterType))->oldest('stage_entered_at')->get()
                ->filter(fn ($r) => $engine->canAct($r, $u))
                ->when($this->tab === 'overdue', fn ($c) => $c->filter->isOverdue())->values();
            $page = $this->getPage();
            $rows = new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page, 15), $all->count(), 15, $page);
        }

        $selected = $this->selectedId ? ApprovalRequest::with(['approvable', 'actions.actor', 'currentStage.role', 'requester', 'workflow'])->find($this->selectedId) : null;
        $mineCount = $this->actionableQuery()->with('currentStage')->get()->filter(fn ($r) => $engine->canAct($r, $u));

        return view('livewire.ops.approvals.index', [
            'rows' => $rows,
            'counts' => ['mine' => $mineCount->count(), 'overdue' => $mineCount->filter->isOverdue()->count()],
            'types' => ApprovalType::options(),
            'selected' => $selected,
            'canDecide' => $selected && $selected->status === ApprovalStatus::Pending && $engine->canAct($selected, $u),
            'delegates' => $selected?->current_stage_id ? User::where('status', 'active')->whereKeyNot($u->id)->whereHas('roles', fn ($q) => $q->where('roles.id', $selected->currentStage->role_id))->pluck('name', 'id')->all() : [],
            'summary' => $selected ? $this->summary($selected) : [],
            'urls' => collect($rows->items())->mapWithKeys(fn ($r) => [$r->id => $this->subjectUrl($r)])->all(),
            'selectedUrl' => $selected ? $this->subjectUrl($selected) : null,
            // S8: approvers (incl. Compliance) see applicant & guarantor KYC/AML/bureau/visit/assessment status before deciding.
            'readiness' => $selected && $selected->approvable instanceof \App\Models\LoanApplication
                ? app(\App\Services\Lending\ReadinessService::class)->checks($selected->approvable->loadMissing(['guarantors', 'fieldVisits', 'signatureRequests.documentType', 'customer', 'productVersion']), $u) : [],
        ]);
    }

    /** Human-readable summary of the subject for inline review (finance screens are owned by the FMS module). */
    protected function summary(ApprovalRequest $r): array
    {
        $s = $r->approvable;
        if (! $s) {
            return ['Subject' => 'Record no longer available'];
        }

        return match ($r->type) {
            ApprovalType::Loan => ['Application' => $s->application_no, 'Customer' => $s->customer?->full_name, 'Product' => $s->product?->name,
                'Amount' => Money::format($s->requested_amount), 'Tenure' => $s->tenure.' '.($s->frequency === 'weekly' ? 'weeks' : 'months'),
                'Grade / risk' => trim(($s->credit_grade ?? '').' '.($s->risk_rating ?? '')), 'DBR' => $s->dbr ? $s->dbr.'%' : null],
            ApprovalType::JournalVoucher => ['Voucher' => $s->voucher_no, 'Date' => $s->voucher_date?->format('d M Y'), 'Narration' => $s->narration, 'Total' => Money::format($s->total_debit),
                'Lines' => $s->lines()->with('account')->get()->map(fn ($l) => $l->account->code.' '.($l->debit > 0 ? 'Dr '.Money::format($l->debit, false) : 'Cr '.Money::format($l->credit, false)))->join(' · ')],
            ApprovalType::VendorPayment => ['Payment' => $s->payment_no, 'Vendor' => $s->vendor?->business_name, 'Amount' => Money::format($s->net_amount), 'Method' => ucfirst($s->method),
                'Purpose' => $s->purpose, 'Bill' => $s->bill?->bill_no, 'Advance' => $s->is_advance ? 'Yes' : 'No'],
            ApprovalType::Expense => ['Reference' => $s->reference, 'Kind' => ucfirst($s->kind), 'Account' => $s->expenseAccount?->label(), 'Amount' => Money::format($s->amount), 'Description' => $s->description],
            ApprovalType::FundTransfer => ['Reference' => $s->reference, 'From' => $s->fromAccount?->name, 'To' => $s->toAccount?->name, 'Amount' => Money::format($s->amount), 'Purpose' => $s->purpose],
            ApprovalType::WriteOff => ['Reference' => $s->reference, 'Loan' => $s->loanAccount?->account_no, 'Amount' => Money::format($s->amount), 'Reason' => $s->reason],
            ApprovalType::CharityDisbursement => ['Reference' => $s->reference, 'Beneficiary' => $s->beneficiary, 'Amount' => Money::format($s->amount), 'Purpose' => $s->purpose],
            ApprovalType::Payroll => ['Period' => $s->period, 'Gross' => Money::format($s->total_gross), 'Net' => Money::format($s->total_net), 'Employees' => $s->lines()->count()],
            ApprovalType::Reconciliation => ['Batch' => $s->reference, 'Bank account' => $s->bankAccount?->name, 'Period' => $s->period_start?->format('d M').' – '.$s->period_end?->format('d M Y'),
                'Matched' => $s->matched_count, 'Difference' => Money::format($s->difference)],
        };
    }

    protected function subjectUrl(ApprovalRequest $r): ?string
    {
        return $r->approvable instanceof LoanApplication ? route('ops.applications.show', $r->approvable) : null;
    }
}
