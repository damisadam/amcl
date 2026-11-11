<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Branch;
use App\Models\LoanAccount;
use App\Models\OtherReceivable;
use App\Services\Accounting\FinanceAdminService;
use App\Services\Accounting\FinancialReports;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Accounts receivable (FMS §4): loan portfolio (from LMS sub-ledger, reconciled to GL) and other receivables. */
#[Layout('layouts.ops')]
class Receivables extends Component
{
    use HandlesActions;

    public string $tab = 'portfolio';

    public string $branch = '';

    public array $form = ['party_name' => '', 'kind' => 'staff_advance', 'amount' => '', 'due_date' => '', 'notes' => ''];

    public ?int $receiveId = null;

    public string $receiveAmount = '';

    public bool $showReceive = false;

    public function mount(): void
    {
        $this->need('fms.view');
    }

    public function create(): void
    {
        $this->need('fms.payables.manage', 'fms.treasury.manage');
        $this->validate(['form.party_name' => 'required|string|max:150', 'form.amount' => 'required|numeric|min:1', 'form.due_date' => 'nullable|date']);
        if ($this->attempt(fn () => app(FinanceAdminService::class)->createReceivable(array_filter($this->form, fn ($v) => $v !== ''), auth()->user()), 'Receivable recorded and posted.')) {
            $this->form = ['party_name' => '', 'kind' => 'staff_advance', 'amount' => '', 'due_date' => '', 'notes' => ''];
        }
    }

    public function askReceive(int $id): void
    {
        $this->need('fms.payables.manage', 'fms.treasury.manage');
        $r = OtherReceivable::findOrFail($id);
        $this->receiveId = $id;
        $this->receiveAmount = Money::sub($r->amount, $r->received_amount);
        $this->showReceive = true;
    }

    public function receive(): void
    {
        $this->need('fms.payables.manage', 'fms.treasury.manage');
        if ($this->attempt(fn () => app(FinanceAdminService::class)->receiveReceivable(OtherReceivable::findOrFail($this->receiveId), $this->receiveAmount, auth()->user()), 'Receipt posted.')) {
            $this->showReceive = false;
        }
    }

    public function render(FinancialReports $reports)
    {
        $open = LoanAccount::whereIn('loan_accounts.status', ['active', 'overdue'])->when($this->branch, fn ($q, $b) => $q->where('loan_accounts.branch_id', $b));
        $byProduct = (clone $open)->join('products', 'products.id', '=', 'loan_accounts.product_id')
            ->selectRaw('products.name k, COUNT(*) n, SUM(principal_outstanding) p, SUM(profit_outstanding) pr, SUM(charges_outstanding + late_charges_outstanding) c')->groupBy('k')->get();
        $byBranch = (clone $open)->leftJoin('branches', 'branches.id', '=', 'loan_accounts.branch_id')
            ->selectRaw('COALESCE(branches.name, "—") k, COUNT(*) n, SUM(principal_outstanding) p, SUM(profit_outstanding) pr, SUM(charges_outstanding + late_charges_outstanding) c')->groupBy('k')->get();
        $subledger = Money::of(LoanAccount::whereIn('status', ['active', 'overdue'])->sum('principal_outstanding'));
        $gl = $reports->netFinancingReceivable(); // gross contract receivable less deferred profit & financed charges
        $aging = [];
        foreach (['Current' => [0, 0], '1–30' => [1, 30], '31–60' => [31, 60], '61–90' => [61, 90], '90+' => [91, 100000]] as $label => [$a, $b]) {
            $row = (clone $open)->whereBetween('dpd', [$a, $b])->selectRaw('COUNT(*) n, COALESCE(SUM(principal_outstanding),0) p')->first();
            $aging[] = ['bucket' => $label, 'count' => (int) $row->n, 'outstanding' => Money::of($row->p)];
        }

        return view('livewire.ops.finance.receivables', [
            'byProduct' => $byProduct, 'byBranch' => $byBranch, 'subledger' => $subledger, 'gl' => $gl, 'difference' => Money::sub($gl, $subledger),
            'aging' => $aging, 'others' => OtherReceivable::latest('id')->get(), 'branches' => Branch::pluck('name', 'id')->all(),
        ]);
    }
}
