<?php

namespace App\Livewire\Ops\FieldVisits;

use App\Livewire\Concerns\HandlesActions;
use App\Livewire\Concerns\WithDataTable;
use App\Models\FieldVisit;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Visit Officer portal (LMS §7) and visit management for supervisors. */
#[Layout('layouts.ops')]
class Index extends Component
{
    use HandlesActions, WithDataTable;

    #[Url] public string $tab = 'today';
    #[Url(except: '')] public string $filterOfficer = '';

    public function mount(): void
    {
        $this->need('field_visits.view', 'field_visits.perform', 'field_visits.assign');
    }

    #[On('realtime')]
    public function refreshVisits(): void {}

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $u = auth()->user();
        $seeAll = $u->hasAnyPermission(['field_visits.view', 'field_visits.assign']);
        $q = FieldVisit::with(['customer', 'officer', 'application'])
            ->when(! $seeAll, fn ($w) => $w->where('assigned_to', $u->id))
            ->when($seeAll && $this->filterOfficer, fn ($w) => $w->where('assigned_to', $this->filterOfficer));
        if ($this->search !== '') {
            $t = '%'.$this->search.'%';
            $q->where(fn ($w) => $w->where('address', 'like', $t)->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $t)));
        }
        match ($this->tab) {
            'today' => $q->whereIn('status', ['scheduled', 'in_progress'])->whereDate('scheduled_for', '<=', today()),
            'upcoming' => $q->where('status', 'scheduled')->whereDate('scheduled_for', '>', today()),
            'completed' => $q->where('status', 'completed'),
            default => $q,
        };

        return view('livewire.ops.field-visits.index', [
            'visits' => $q->orderBy($this->tab === 'completed' ? 'submitted_at' : 'scheduled_for', $this->tab === 'completed' ? 'desc' : 'asc')->paginate($this->perPage),
            'seeAll' => $seeAll,
            'officers' => $seeAll ? User::where('type', 'staff')->whereHas('roles.permissions', fn ($p) => $p->where('key', 'field_visits.perform'))->pluck('name', 'id')->all() : [],
        ]);
    }
}
