<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Instalment;
use App\Models\LoanApplication;
use App\Services\Accounting\FinanceAdminService;
use App\Services\Accounting\FinancialReports;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Budgeting, budget-vs-actual and simple forecasting (FMS §10). */
#[Layout('layouts.ops')]
class Budgets extends Component
{
    use HandlesActions;

    public string $tab = 'budgets';

    public ?int $budgetId = null;

    public string $fiscalYear = '';

    public string $name = '';

    public array $lines = [];

    public bool $editing = false;

    public ?int $viewId = null;

    public function mount(): void
    {
        $this->need('fms.view', 'fms.budgets.manage');
        $this->fiscalYear = (string) FiscalYear::whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->value('id');
    }

    public function create(): void
    {
        $this->need('fms.budgets.manage');
        $this->budgetId = null;
        $this->name = 'Operating budget';
        $this->lines = [['account_id' => '', 'accounting_period_id' => '', 'cost_center_id' => '', 'amount' => '']];
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $this->need('fms.budgets.manage');
        $b = Budget::with('lines')->findOrFail($id);
        $this->budgetId = $b->id;
        $this->fiscalYear = (string) $b->fiscal_year_id;
        $this->name = $b->name;
        $this->lines = $b->lines->map(fn ($l) => ['account_id' => (string) $l->account_id, 'accounting_period_id' => (string) $l->accounting_period_id,
            'cost_center_id' => (string) $l->cost_center_id, 'amount' => (string) $l->amount])->all();
        $this->editing = true;
    }

    public function addLine(): void
    {
        $this->lines[] = ['account_id' => '', 'accounting_period_id' => '', 'cost_center_id' => '', 'amount' => ''];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    /** A line with no period is an annual amount spread evenly across the fiscal year's 12 periods. */
    private function expanded(): array
    {
        $periods = AccountingPeriod::where('fiscal_year_id', $this->fiscalYear)->orderBy('start_date')->pluck('id')->all();
        $out = [];
        foreach ($this->lines as $l) {
            if (! is_numeric($l['amount'] ?? null)) {
                continue;
            }
            if ($l['accounting_period_id']) {
                $out[] = $l;
            } elseif ($periods) {
                foreach (Money::split($l['amount'], count($periods)) as $i => $part) {
                    $out[] = ['account_id' => $l['account_id'], 'accounting_period_id' => $periods[$i], 'cost_center_id' => $l['cost_center_id'] ?: null, 'amount' => $part];
                }
            }
        }

        return $out;
    }

    public function save(): void
    {
        $this->need('fms.budgets.manage');
        $this->validate(['fiscalYear' => 'required|integer', 'name' => 'required|string|max:120', 'lines.*.amount' => 'nullable|numeric']);
        $b = $this->attempt(fn () => app(FinanceAdminService::class)->saveBudget(FiscalYear::findOrFail($this->fiscalYear), $this->name, $this->expanded(),
            $this->budgetId ? Budget::findOrFail($this->budgetId) : null, auth()->user()), 'Budget saved.');
        if ($b) {
            $this->editing = false;
            $this->viewId = $b->id;
        }
    }

    public function approve(int $id): void
    {
        $this->need('fms.budgets.manage');
        $this->attempt(fn () => app(FinanceAdminService::class)->approveBudget(Budget::findOrFail($id), auth()->user()), 'Budget approved.');
    }

    private function forecast(): array
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $m = now()->addMonths($i);
            $from = $m->copy()->startOfMonth();
            $to = $m->copy()->endOfMonth();
            $rows[] = [
                'month' => $m->format('M Y'),
                'collections' => Money::of(Instalment::where('status', '!=', 'paid')->whereBetween('due_date', [$i === 0 ? today() : $from, $to])
                    ->selectRaw('COALESCE(SUM(total_due + late_charge_due - total_paid),0) v')->value('v')),
                'disbursements' => $i === 0 ? Money::add('0', ...LoanApplication::whereIn('status', ['approved', 'kfs_acknowledged', 'cooling_off', 'ready_for_disbursement'])->get()->map->financedAmount()->all())
                    : Money::of(LoanApplication::whereIn('status', ['submitted', 'under_review', 'pending_approval'])->sum('requested_amount')),
            ];
        }

        return $rows;
    }

    public function render(FinancialReports $reports)
    {
        $view = $this->viewId ? Budget::find($this->viewId) : null;

        return view('livewire.ops.finance.budgets', [
            'budgets' => Budget::with('fiscalYear')->withSum('lines', 'amount')->latest('id')->get(),
            'years' => FiscalYear::orderByDesc('start_date')->pluck('name', 'id')->all(),
            'periods' => AccountingPeriod::where('fiscal_year_id', $this->fiscalYear)->orderBy('start_date')->pluck('name', 'id')->all(),
            'accounts' => Account::whereIn('type', ['income', 'expense'])->where('is_postable', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'costCenters' => CostCenter::pluck('name', 'id')->all(),
            'bva' => $view ? $reports->budgetVsActual($view) : null,
            'viewBudget' => $view,
            'forecast' => $this->tab === 'forecast' ? $this->forecast() : [],
        ]);
    }
}
