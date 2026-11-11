<?php

namespace App\Livewire\Ops\Finance;

use App\Enums\AccountType;
use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Services\Accounting\FinanceAdminService;
use App\Services\Accounting\FinancialReports;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Chart of accounts (head → sub-head → account) with balances, plus cost centers. */
#[Layout('layouts.ops')]
class Accounts extends Component
{
    use HandlesActions;

    public string $tab = 'accounts';

    public string $asOf = '';

    public bool $showAccount = false;

    public ?int $editingId = null;

    public array $form = [];

    public bool $showCc = false;

    public ?int $ccId = null;

    public array $cc = [];

    public function mount(): void
    {
        $this->need('fms.view');
        $this->asOf = today()->toDateString();
    }

    public function newAccount(?int $parentId = null): void
    {
        $this->need('fms.coa.manage');
        $parent = $parentId ? Account::find($parentId) : null;
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'type' => $parent?->type->value ?? 'expense', 'parent_id' => $parent?->id, 'is_postable' => true, 'is_active' => true, 'cash_flow_category' => '', 'description' => ''];
        $this->showAccount = true;
    }

    public function editAccount(int $id): void
    {
        $this->need('fms.coa.manage');
        $a = Account::findOrFail($id);
        $this->editingId = $a->id;
        $this->form = ['code' => $a->code, 'name' => $a->name, 'type' => $a->type->value, 'parent_id' => $a->parent_id, 'is_postable' => $a->is_postable,
            'is_active' => $a->is_active, 'cash_flow_category' => $a->cash_flow_category ?? '', 'description' => $a->description ?? ''];
        $this->showAccount = true;
    }

    public function saveAccount(): void
    {
        $this->need('fms.coa.manage');
        $this->validate(['form.code' => 'required|string|max:20', 'form.name' => 'required|string|max:150', 'form.type' => 'required']);
        $ok = $this->attempt(fn () => app(FinanceAdminService::class)->saveAccount($this->form, $this->editingId ? Account::findOrFail($this->editingId) : null, auth()->user()), 'Account saved.');
        if ($ok) {
            $this->showAccount = false;
        }
    }

    public function editCc(?int $id = null): void
    {
        $this->need('fms.coa.manage');
        $c = $id ? CostCenter::findOrFail($id) : null;
        $this->ccId = $c?->id;
        $this->cc = ['code' => $c?->code ?? '', 'name' => $c?->name ?? '', 'business_line' => $c?->business_line ?? '', 'branch_id' => $c?->branch_id ?? '', 'is_active' => $c?->is_active ?? true];
        $this->showCc = true;
    }

    public function saveCc(): void
    {
        $this->need('fms.coa.manage');
        if ($this->attempt(fn () => app(FinanceAdminService::class)->saveCostCenter($this->cc, $this->ccId ? CostCenter::findOrFail($this->ccId) : null), 'Cost center saved.')) {
            $this->showCc = false;
        }
    }

    public function render(FinancialReports $reports)
    {
        $accounts = Account::orderBy('code')->get();
        $balances = [];
        foreach ($accounts->where('is_postable', true) as $a) {
            $balances[$a->id] = $reports->accountBalance($a, $this->asOf ?: null);
        }
        // roll up to parents (natural direction within the same type)
        $rolled = $balances;
        $sum = function (Account $a) use (&$sum, $accounts, $balances) {
            if ($a->is_postable) {
                return $balances[$a->id] ?? '0.00';
            }

            return \App\Support\Money::add('0', ...$accounts->where('parent_id', $a->id)->map(fn ($c) => $sum($c))->all());
        };
        foreach ($accounts->where('is_postable', false) as $a) {
            $rolled[$a->id] = $sum($a);
        }
        $tree = [];
        $walk = function ($parentId, $depth) use (&$walk, &$tree, $accounts) {
            foreach ($accounts->where('parent_id', $parentId) as $a) {
                $tree[] = ['account' => $a, 'depth' => $depth];
                $walk($a->id, $depth + 1);
            }
        };
        $walk(null, 0);

        return view('livewire.ops.finance.accounts', [
            'tree' => $tree,
            'balances' => $rolled,
            'types' => AccountType::options(),
            'parents' => $accounts->where('is_postable', false)->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'costCenters' => CostCenter::with('branch')->orderBy('code')->get(),
            'branches' => Branch::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }
}
