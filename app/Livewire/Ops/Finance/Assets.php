<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FixedAsset;
use App\Services\Accounting\FinanceOperations;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Company fixed assets (FMS §7) – separate from customer-financed assets. */
#[Layout('layouts.ops')]
class Assets extends Component
{
    use HandlesActions, WithPagination;

    public array $form = [];

    public bool $showForm = false;

    public string $period = '';

    public string $filterBranch = '';

    public ?int $disposeId = null;

    public string $proceeds = '';

    public bool $showDispose = false;

    public function mount(): void
    {
        $this->need('fms.view', 'fms.assets.manage');
        $this->resetForm();
        $this->period = (string) AccountingPeriod::where('status', 'open')->whereDate('end_date', '<=', today()->endOfMonth())->orderByDesc('start_date')->value('id');
    }

    private function resetForm(): void
    {
        $this->form = ['name' => '', 'category' => 'it_equipment', 'branch_id' => '', 'acquired_on' => today()->toDateString(), 'cost' => '', 'salvage_value' => '0', 'useful_life_months' => '36'];
    }

    public function acquire(): void
    {
        $this->need('fms.assets.manage');
        $this->validate(['form.name' => 'required|string|max:150', 'form.category' => 'required', 'form.acquired_on' => 'required|date', 'form.cost' => 'required|numeric|min:1',
            'form.salvage_value' => 'required|numeric|min:0|lte:form.cost', 'form.useful_life_months' => 'required|integer|min:1|max:600']);
        $data = $this->form;
        $data['branch_id'] = $data['branch_id'] ?: null;
        $data['cost'] = Money::of($data['cost']);
        $data['salvage_value'] = Money::of($data['salvage_value']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->acquireAsset($data, auth()->user()), 'Asset registered and capitalised.')) {
            $this->resetForm();
            $this->showForm = false;
        }
    }

    public function depreciate(): void
    {
        $this->need('fms.assets.manage');
        $this->validate(['period' => 'required|integer']);
        $total = $this->attempt(fn () => app(FinanceOperations::class)->runDepreciation(AccountingPeriod::findOrFail($this->period)));
        if ($total !== null) {
            $this->toast('Depreciation posted: '.Money::format($total).'.');
        }
    }

    public function askDispose(int $id): void
    {
        $this->need('fms.assets.manage');
        $this->disposeId = $id;
        $this->proceeds = '0';
        $this->showDispose = true;
    }

    public function dispose(): void
    {
        $this->need('fms.assets.manage');
        $this->validate(['proceeds' => 'required|numeric|min:0']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->disposeAsset(FixedAsset::findOrFail($this->disposeId), Money::of($this->proceeds), auth()->user()), 'Asset disposed.')) {
            $this->showDispose = false;
        }
    }

    public function render()
    {
        $q = FixedAsset::with('branch')->when($this->filterBranch, fn ($q, $b) => $q->where('branch_id', $b));

        return view('livewire.ops.finance.assets', [
            'assets' => (clone $q)->orderBy('asset_code')->paginate(15),
            'byBranch' => FixedAsset::where('fixed_assets.status', 'active')->leftJoin('branches', 'branches.id', '=', 'fixed_assets.branch_id')
                ->selectRaw('COALESCE(branches.name, "Head office / unassigned") b, COUNT(*) n, SUM(fixed_assets.cost) cost, SUM(fixed_assets.accumulated_depreciation) acc')->groupBy('b')->get(),
            'branches' => Branch::pluck('name', 'id')->all(),
            'periods' => AccountingPeriod::where('status', 'open')->orderByDesc('start_date')->pluck('name', 'id')->all(),
        ]);
    }
}
