<?php

namespace App\Livewire\Ops\Loans;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\PortfolioTransfer;
use App\Models\User;
use App\Policies\RecordAccess;
use App\Services\Lending\ServicingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Loan case / portfolio transfer between branches and officers (LMS §9.13). */
#[Layout('layouts.ops')]
class Transfer extends Component
{
    use HandlesActions, WithDataTable;

    public array $selected = [];

    public string $fromBranch = '';

    public string $fromOfficer = '';

    public string $toBranch = '';

    public string $toOfficer = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->need('loans.manage');
    }

    public function selectAllVisible(array $ids): void
    {
        $this->selected = array_values(array_unique(array_merge($this->selected, array_map('strval', $ids))));
    }

    public function transfer(ServicingService $svc): void
    {
        $this->need('loans.manage');
        $this->validate(['selected' => 'required|array|min:1', 'reason' => 'required|string|min:5|max:500',
            'toBranch' => 'nullable|exists:branches,id', 'toOfficer' => 'nullable|exists:users,id']);
        $ids = RecordAccess::loansQuery(LoanAccount::query(), auth()->user())->whereIn('id', $this->selected)->pluck('id')->all();
        $t = $this->attempt(fn () => $svc->transferPortfolio($ids, $this->toBranch ?: null, $this->toOfficer ?: null, $this->reason, auth()->user()));
        if ($t) {
            $this->toast(__('Transferred :n account(s) – :r.', ['n' => count($ids), 'r' => $t->reference]));
            $this->reset('selected', 'reason');
        }
    }

    public function render()
    {
        $q = RecordAccess::loansQuery(LoanAccount::query(), auth()->user())->with(['customer', 'branch', 'officer'])->whereIn('status', ['active', 'overdue'])
            ->when($this->fromBranch, fn ($w) => $w->where('branch_id', $this->fromBranch))
            ->when($this->fromOfficer, fn ($w) => $w->where('loan_officer_id', $this->fromOfficer));
        $this->applySearch($q, ['account_no']);

        return view('livewire.ops.loans.transfer', [
            'loans' => $q->orderBy('account_no')->paginate(25),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
            'officers' => User::where('type', 'staff')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'history' => PortfolioTransfer::with(['fromBranch', 'toBranch', 'toOfficer', 'actor'])->latest('id')->take(10)->get(),
        ]);
    }
}
