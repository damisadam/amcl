<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\AccountingPeriod;
use App\Models\Fund;
use App\Models\LoanAccount;
use App\Models\LoanPayment;
use App\Models\ProfitDistribution;
use App\Services\Accounting\FinanceAdminService;
use App\Services\Accounting\FinanceOperations;
use App\Services\Lending\DisbursementService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Funding sources, utilization, investor reporting and Shariah profit distribution (FMS §6). */
#[Layout('layouts.ops')]
class Funds extends Component
{
    use HandlesActions;

    public string $tab = 'sources';

    public bool $showForm = false;

    public ?int $fundId = null;

    public array $form = [];

    public ?int $receiptFund = null;

    public string $receiptAmount = '';

    public bool $showReceipt = false;

    public string $reportFund = '';

    public string $distFund = '';

    public string $distPeriod = '';

    public function mount(): void
    {
        $this->need('fms.view');
    }

    public function edit(?int $id = null): void
    {
        $this->need('fms.funds.manage');
        $f = $id ? Fund::findOrFail($id) : null;
        $this->fundId = $f?->id;
        $this->form = ['code' => $f?->code ?? '', 'name' => $f?->name ?? '', 'type' => $f?->type ?? 'investor', 'provider_name' => $f?->provider_name ?? '',
            'committed_amount' => (string) ($f?->committed_amount ?? ''), 'structure' => $f?->structure ?? 'mudarabah', 'investor_profit_share_pct' => (string) ($f?->investor_profit_share_pct ?? ''),
            'start_date' => $f?->start_date?->toDateString() ?? '', 'end_date' => $f?->end_date?->toDateString() ?? '', 'terms' => $f?->terms ?? '', 'status' => $f?->status ?? 'active'];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->need('fms.funds.manage');
        if ($this->attempt(fn () => app(FinanceAdminService::class)->saveFund($this->form, $this->fundId ? Fund::findOrFail($this->fundId) : null), 'Funding source saved.')) {
            $this->showForm = false;
        }
    }

    public function askReceipt(int $id): void
    {
        $this->need('fms.funds.manage');
        $this->receiptFund = $id;
        $this->receiptAmount = '';
        $this->showReceipt = true;
    }

    public function recordReceipt(): void
    {
        $this->need('fms.funds.manage');
        $this->validate(['receiptAmount' => 'required|numeric|min:1']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->recordFundReceipt(Fund::findOrFail($this->receiptFund), Money::of($this->receiptAmount), auth()->user()), 'Fund receipt posted to the operating account.')) {
            $this->showReceipt = false;
        }
    }

    public function calculate(): void
    {
        $this->need('fms.funds.manage');
        $this->validate(['distFund' => 'required|integer', 'distPeriod' => 'required|integer']);
        $this->attempt(fn () => app(FinanceOperations::class)->calculateProfitDistribution(Fund::findOrFail($this->distFund), AccountingPeriod::findOrFail($this->distPeriod)), 'Distribution calculated.');
    }

    public function post(int $id): void
    {
        $this->need('fms.funds.manage');
        $this->attempt(fn () => app(FinanceOperations::class)->postProfitDistribution(ProfitDistribution::findOrFail($id)), 'Distribution posted.');
    }

    private function investorReport(Fund $f): array
    {
        $loans = LoanAccount::where('fund_id', $f->id);
        $open = (clone $loans)->whereIn('status', ['active', 'overdue']);
        $outstanding = Money::of((clone $open)->sum('principal_outstanding'));
        $par30 = Money::of((clone $open)->where('dpd', '>', 30)->sum('principal_outstanding'));

        return [
            'accounts' => (clone $loans)->count(),
            'disbursed' => Money::of((clone $loans)->sum('principal')),
            'outstanding' => $outstanding,
            'collections' => Money::of(LoanPayment::whereIn('loan_account_id', (clone $loans)->select('id'))->where('status', 'succeeded')->sum('amount')),
            'profit_collected' => Money::of(LoanPayment::whereIn('loan_account_id', (clone $loans)->select('id'))->where('status', 'succeeded')->get()->sum(fn ($p) => (float) ($p->allocation['profit'] ?? 0))),
            'par30' => Money::isPositive($outstanding) ? round((float) $par30 * 100 / (float) $outstanding, 2) : 0,
            'closed' => (clone $loans)->whereIn('status', ['closed', 'noc_issued'])->count(),
        ];
    }

    public function render(DisbursementService $disb)
    {
        $funds = Fund::orderBy('name')->get();

        return view('livewire.ops.finance.funds', [
            'funds' => $funds->map(fn ($f) => ['fund' => $f, 'available' => $disb->fundAvailable($f), 'used' => Money::sub($f->committed_amount, $disb->fundAvailable($f))]),
            'fundOptions' => $funds->pluck('name', 'id')->all(),
            'periods' => AccountingPeriod::orderByDesc('start_date')->get()->pluck('name', 'id')->all(),
            'report' => $this->reportFund ? $this->investorReport(Fund::findOrFail($this->reportFund)) : null,
            'distributions' => ProfitDistribution::with(['fund', 'period'])->latest('id')->get(),
        ]);
    }
}
