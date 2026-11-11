<?php

namespace App\Livewire\Ops\Recovery;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\LoanAccount;
use App\Models\User;
use App\Policies\RecordAccess;
use App\Services\Lending\ServicingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Recovery agent portal (own cases) and manager view (all delinquent cases + assignment). */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url(except: '')] public string $filterAgent = '';
    #[Url(except: '')] public string $filterBucket = '';
    #[Url(except: 'mine')] public string $scope = 'mine';

    public array $selected = [];

    public string $assignTo = '';

    public function mount(): void
    {
        $this->need('recovery.view', 'recovery.work');
        if (! auth()->user()->hasAnyPermission(['recovery.view', 'recovery.assign'])) {
            $this->scope = 'mine';
        } elseif ($this->scope === 'mine' && ! auth()->user()->hasPermission('recovery.work')) {
            $this->scope = 'all';
        }
    }

    #[On('realtime')]
    public function refreshList(): void {}

    public function isManager(): bool
    {
        return auth()->user()->hasAnyPermission(['recovery.view', 'recovery.assign']);
    }

    private function query()
    {
        $user = auth()->user();
        $q = LoanAccount::query()->with(['customer', 'branch', 'recoveryAgent', 'recoveryContacts' => fn ($c) => $c->limit(1)])
            ->whereIn('status', ['active', 'overdue'])->where('dpd', '>', 0);
        if ($this->scope === 'mine' || ! $this->isManager()) {
            $q->where('recovery_agent_id', $user->id);
        } else {
            $q = RecordAccess::loansQuery($q, $user);
            $q->when($this->filterAgent === 'unassigned', fn ($w) => $w->whereNull('recovery_agent_id'))
              ->when($this->filterAgent && $this->filterAgent !== 'unassigned', fn ($w) => $w->where('recovery_agent_id', $this->filterAgent));
        }
        $q->when($this->filterBucket, fn ($w) => match ($this->filterBucket) {
            '1-30' => $w->whereBetween('dpd', [1, 30]), '31-60' => $w->whereBetween('dpd', [31, 60]), '61-90' => $w->whereBetween('dpd', [61, 90]), default => $w->where('dpd', '>', 90),
        });
        if (trim($this->search) !== '') {
            $t = '%'.trim($this->search).'%';
            $q->where(fn ($w) => $w->where('account_no', 'like', $t)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $t)));
        }

        return $q;
    }

    public function assign(ServicingService $svc): void
    {
        $this->need('recovery.assign');
        $this->validate(['selected' => 'required|array|min:1', 'assignTo' => 'required|integer']);
        $agent = User::where('type', 'staff')->findOrFail($this->assignTo);
        $ids = RecordAccess::loansQuery(LoanAccount::query(), auth()->user())->whereIn('id', $this->selected)->pluck('id')->all();
        if ($this->attempt(fn () => $svc->assignRecovery($ids, $agent, auth()->user()), __('Cases assigned to :a.', ['a' => $agent->name]))) {
            $this->selected = [];
        }
    }

    public function render()
    {
        $agents = User::where('type', 'staff')->where('status', 'active')->get()->filter(fn ($u) => $u->hasPermission('recovery.work'))->pluck('name', 'id')->all();

        return view('livewire.ops.recovery.index', [
            'cases' => $this->applySort($this->query(), 'dpd', ['dpd', 'principal_outstanding'])->paginate($this->perPage),
            'agents' => $agents, 'manager' => $this->isManager(), 'canAssign' => auth()->user()->hasPermission('recovery.assign'),
            'canWork' => auth()->user()->hasPermission('recovery.work'),
        ]);
    }
}
