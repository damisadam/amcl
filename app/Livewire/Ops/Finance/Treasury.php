<?php

namespace App\Livewire\Ops\Finance;

use App\Enums\ApplicationStatus;
use App\Livewire\Concerns\HandlesActions;
use App\Models\BankAccount;
use App\Models\FundTransfer;
use App\Models\Instalment;
use App\Models\LoanApplication;
use App\Models\SettlementRecord;
use App\Models\VendorPayment;
use App\Services\Accounting\FinanceAdminService;
use App\Services\Accounting\FinanceOperations;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Treasury & cash/bank management (FMS §5). Bank figures are SIMULATED settlement accounts. */
#[Layout('layouts.ops')]
class Treasury extends Component
{
    use HandlesActions, WithPagination;

    public string $tab = 'accounts';

    public array $transfer = ['from' => '', 'to' => '', 'amount' => '', 'purpose' => ''];

    public ?int $editBankId = null;

    public array $bank = [];

    public bool $showBank = false;

    public string $filterBank = '';

    public function mount(): void
    {
        $this->need('fms.view');
    }

    public function editBank(int $id): void
    {
        $this->need('fms.treasury.manage');
        $b = BankAccount::findOrFail($id);
        $this->editBankId = $b->id;
        $this->bank = ['name' => $b->name, 'minimum_balance' => (string) $b->minimum_balance, 'is_active' => $b->is_active];
        $this->showBank = true;
    }

    public function saveBank(): void
    {
        $this->need('fms.treasury.manage');
        $this->validate(['bank.name' => 'required|string|max:120', 'bank.minimum_balance' => 'required|numeric|min:0']);
        if ($this->attempt(fn () => app(FinanceAdminService::class)->updateBankAccount(BankAccount::findOrFail($this->editBankId), $this->bank), 'Bank account updated.')) {
            $this->showBank = false;
        }
    }

    public function requestTransfer(): void
    {
        $this->need('fms.treasury.manage');
        $this->validate(['transfer.from' => 'required|integer', 'transfer.to' => 'required|integer', 'transfer.amount' => 'required|numeric|min:1', 'transfer.purpose' => 'required|string|min:5|max:500']);
        $ok = $this->attempt(fn () => app(FinanceOperations::class)->requestFundTransfer(BankAccount::findOrFail($this->transfer['from']), BankAccount::findOrFail($this->transfer['to']),
            Money::of($this->transfer['amount']), $this->transfer['purpose'], auth()->user()), 'Fund transfer submitted for approval.');
        if ($ok) {
            $this->transfer = ['from' => '', 'to' => '', 'amount' => '', 'purpose' => ''];
        }
    }

    /** 30-day liquidity projection from schedules and committed outflows. */
    private function projection(): array
    {
        $from = today();
        $to = today()->addDays(30);
        $inflow = Money::of(Instalment::where('status', '!=', 'paid')->whereBetween('due_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(total_due + late_charge_due - total_paid),0) v')->value('v'));
        $overdue = Money::of(Instalment::where('status', '!=', 'paid')->where('due_date', '<', $from)->selectRaw('COALESCE(SUM(total_due + late_charge_due - total_paid),0) v')->value('v'));
        $disb = LoanApplication::whereIn('status', [ApplicationStatus::ReadyForDisbursement->value, ApplicationStatus::CoolingOff->value, ApplicationStatus::KfsAcknowledged->value, ApplicationStatus::Approved->value])->get();
        $disbursements = Money::add('0', ...$disb->map(fn ($a) => $a->financedAmount())->all());
        $vendor = Money::of(VendorPayment::whereIn('status', ['approved', 'pending_approval', 'processing'])->sum('net_amount'));
        $cash = Money::add('0', ...collect(app(FinanceOperations::class)->bankBalances())->filter(fn ($b) => $b['bank']->purpose !== 'safeguarded')->pluck('balance')->all());

        return ['cash' => $cash, 'inflow' => $inflow, 'overdue' => $overdue, 'disbursements' => $disbursements, 'disbursement_count' => $disb->count(),
            'vendor' => $vendor, 'net' => Money::sub(Money::add($cash, $inflow), Money::add($disbursements, $vendor))];
    }

    public function render(FinanceOperations $ops)
    {
        return view('livewire.ops.finance.treasury', [
            'banks' => $ops->bankBalances(),
            'options' => BankAccount::where('is_active', true)->where('purpose', '!=', 'safeguarded')->get()->mapWithKeys(fn ($b) => [$b->id => $b->name.' ('.ucfirst($b->purpose).')'])->all(),
            'transfers' => FundTransfer::with(['fromAccount', 'toAccount', 'requester'])->latest('id')->take(20)->get(),
            'settlements' => SettlementRecord::with('bankAccount')->when($this->filterBank, fn ($q, $b) => $q->where('bank_account_id', $b))->latest('value_date')->latest('id')->paginate(15),
            'bankFilter' => BankAccount::pluck('name', 'id')->all(),
            'p' => $this->tab === 'liquidity' ? $this->projection() : null,
        ]);
    }
}
