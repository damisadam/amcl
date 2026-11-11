<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\Account;
use App\Models\CharityDisbursement;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Services\Accounting\FinanceOperations;
use App\Services\Accounting\FinancialReports;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Islamic finance & charity fund accounting, Shariah audit support (FMS §11). */
#[Layout('layouts.ops')]
class Islamic extends Component
{
    use HandlesActions;

    public string $tab = 'charity';

    public array $form = ['beneficiary' => '', 'amount' => '', 'purpose' => ''];

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->need('fms.view', 'fms.islamic.manage');
        $this->from = now()->startOfYear()->toDateString();
        $this->to = today()->toDateString();
    }

    public function requestCharity(): void
    {
        $this->need('fms.islamic.manage');
        $this->validate(['form.beneficiary' => 'required|string|max:150', 'form.amount' => 'required|numeric|min:1', 'form.purpose' => 'required|string|min:5']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->requestCharityDisbursement($this->form['beneficiary'], Money::of($this->form['amount']), $this->form['purpose'], auth()->user()),
            'Charity disbursement submitted (Shariah compliance → Finance approval).')) {
            $this->form = ['beneficiary' => '', 'amount' => '', 'purpose' => ''];
        }
    }

    public function render(FinancialReports $reports, FinanceOperations $ops)
    {
        $charityAccount = Account::where('system_role', 'charity_payable')->first();
        $profitByStructure = PaymentAllocation::query()
            ->join('loan_payments', 'loan_payments.id', '=', 'payment_allocations.loan_payment_id')
            ->join('loan_accounts', 'loan_accounts.id', '=', 'loan_payments.loan_account_id')
            ->where('loan_payments.status', 'succeeded')->whereBetween('loan_payments.received_at', [$this->from, $this->to.' 23:59:59'])
            ->whereIn('payment_allocations.component', ['profit', 'late_charge'])
            ->selectRaw('loan_accounts.shariah_structure s, payment_allocations.component c, SUM(payment_allocations.amount) v')->groupBy('s', 'c')->get();
        $interestLike = Account::where('type', 'income')->where(fn ($q) => $q->where('name', 'like', '%interest%')->orWhere('code', 'like', '%INT%'))->count();

        return view('livewire.ops.finance.islamic', [
            'charityBalance' => $ops->charityBalance(),
            'charityLedger' => $charityAccount ? $reports->ledger($charityAccount, $this->from, $this->to) : null,
            'requests' => CharityDisbursement::with('requester')->latest('id')->get(),
            'profitByStructure' => $profitByStructure,
            'glProfit' => $reports->profitAndLoss($this->from, $this->to)['income'],
            'products' => Product::orderBy('name')->get(),
            'interestLike' => $interestLike,
        ]);
    }
}
