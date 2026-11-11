<?php

namespace App\Livewire\Ops\Reports;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Disbursement;
use App\Services\Accounting\FinancialReports;
use App\Services\Reports\CsvExport;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Financial statements (FMS §13.1) computed from posted journal lines of this tenant only. */
#[Layout('layouts.ops')]
class Financial extends Component
{
    use HandlesActions;

    #[Url]
    public string $tab = 'tb';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public string $branch_id = '';

    public string $account_id = '';

    public string $budget_id = '';

    public function mount(): void
    {
        $this->need('reports.fms');
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
        $this->account_id = (string) Account::where('system_role', 'operating_bank')->value('id');
        $this->budget_id = (string) Budget::latest('id')->value('id');
    }

    private function branch(): ?int
    {
        return $this->branch_id ? (int) $this->branch_id : null;
    }

    public function export(FinancialReports $r)
    {
        $f = ['tab' => $this->tab, 'from' => $this->from, 'to' => $this->to, 'branch' => $this->branch_id];
        switch ($this->tab) {
            case 'pl':
                $pl = $r->profitAndLoss($this->from, $this->to, $this->branch());
                $rows = collect($pl['income'])->map(fn ($x) => ['Income', $x['account']->code, $x['account']->name, $x['amount']])
                    ->merge(collect($pl['expenses'])->map(fn ($x) => ['Expense', $x['account']->code, $x['account']->name, $x['amount']]))
                    ->push(['Net profit', '', '', $pl['net_profit']]);

                return CsvExport::download('profit-and-loss', ['Section', 'Code', 'Account', 'Amount'], $rows, $f);
            case 'bs':
                $bs = $r->balanceSheet($this->to, $this->branch());
                $rows = collect($bs['sections'])->flatMap(fn ($items, $sec) => collect($items)->map(fn ($x) => [ucfirst($sec), $x['account']?->code, $x['account']?->name ?? $x['label'], $x['amount']]));

                return CsvExport::download('balance-sheet', ['Section', 'Code', 'Account', 'Amount'], $rows, $f);
            case 'gl':
                $l = $r->ledger(Account::findOrFail($this->account_id), $this->from, $this->to);

                return CsvExport::download('general-ledger', ['Date', 'Voucher', 'Narration', 'Debit', 'Credit', 'Balance'],
                    collect($l['rows'])->map(fn ($x) => [$x['line']->voucher_date, $x['line']->voucher_no, $x['line']->narration, $x['line']->debit, $x['line']->credit, $x['balance']]), $f);
            default:
                $tb = $r->trialBalance(null, $this->to, $this->branch());

                return CsvExport::download('trial-balance', ['Code', 'Account', 'Type', 'Debit', 'Credit'],
                    collect($tb['rows'])->map(fn ($x) => [$x['account']->code, $x['account']->name, $x['account']->type->value, $x['debit'], $x['credit']]), $f);
        }
    }

    private function targets(): array
    {
        $receivable = Account::where('system_role', 'financing_receivable')->value('id');
        $budgeted = BudgetLine::query()->join('accounting_periods', 'accounting_periods.id', '=', 'budget_lines.accounting_period_id')
            ->where('budget_lines.account_id', $receivable)->whereBetween('accounting_periods.start_date', [$this->from, $this->to])
            ->selectRaw("DATE_FORMAT(accounting_periods.start_date, '%Y-%m') m, SUM(budget_lines.amount) v")->groupBy('m')->pluck('v', 'm');
        $actual = Disbursement::where('status', 'succeeded')->whereBetween('completed_at', [$this->from, $this->to.' 23:59:59'])
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') m, SUM(gross_amount) v, COUNT(*) n")->groupBy('m')->get()->keyBy('m');

        return $budgeted->keys()->merge($actual->keys())->unique()->sort()->values()->map(fn ($m) => [
            'month' => $m, 'target' => isset($budgeted[$m]) ? Money::of($budgeted[$m]) : null, 'actual' => Money::of($actual[$m]->v ?? 0), 'count' => (int) ($actual[$m]->n ?? 0),
        ])->all();
    }

    public function render(FinancialReports $r)
    {
        $data = match ($this->tab) {
            'tb' => $r->trialBalance(null, $this->to, $this->branch()),
            'gl' => $this->account_id ? $r->ledger(Account::findOrFail($this->account_id), $this->from, $this->to) : null,
            'pl' => $r->profitAndLoss($this->from, $this->to, $this->branch()),
            'bs' => $r->balanceSheet($this->to, $this->branch()),
            'cf' => $r->cashFlow($this->from, $this->to),
            'bva' => $this->budget_id ? $r->budgetVsActual(Budget::findOrFail($this->budget_id)) : [],
            'targets' => $this->targets(),
            'branches' => Branch::orderBy('name')->get()->map(fn ($b) => ['branch' => $b, 'pl' => $r->profitAndLoss($this->from, $this->to, $b->id)])->all(),
            default => null,
        };

        return view('livewire.ops.reports.financial', [
            'data' => $data,
            'branches' => Branch::pluck('name', 'id')->all(),
            'accounts' => Account::where('is_postable', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])->all(),
            'budgets' => Budget::pluck('name', 'id')->all(),
        ]);
    }
}
