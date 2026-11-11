<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\LoanAccount;
use App\Models\LoanProvision;
use App\Models\WriteOff;
use App\Services\Accounting\FinanceOperations;
use App\Services\Reports\RegulatoryReports;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Loan-loss provisioning & write-offs (FMS §4.4). Rates/classification thresholds are tenant settings. */
#[Layout('layouts.ops')]
class Provisioning extends Component
{
    use HandlesActions;

    public ?int $writeOffLoan = null;

    public string $reason = '';

    public bool $showWriteOff = false;

    public function mount(): void
    {
        $this->need('fms.view', 'fms.provisioning.manage');
    }

    public function run(): void
    {
        $this->need('fms.provisioning.manage');
        $r = $this->attempt(fn () => app(FinanceOperations::class)->runProvisioning());
        if ($r) {
            $this->toast('Provisioning run complete. Net movement '.Money::format($r['net_movement']).'.');
        }
    }

    public function askWriteOff(int $id): void
    {
        $this->need('fms.provisioning.manage');
        $this->writeOffLoan = $id;
        $this->reason = '';
        $this->showWriteOff = true;
    }

    public function requestWriteOff(): void
    {
        $this->need('fms.provisioning.manage');
        $this->validate(['reason' => 'required|string|min:10']);
        if ($this->attempt(fn () => app(FinanceOperations::class)->requestWriteOff(LoanAccount::findOrFail($this->writeOffLoan), $this->reason, auth()->user()), 'Write-off submitted for approval.')) {
            $this->showWriteOff = false;
        }
    }

    public function render()
    {
        return view('livewire.ops.finance.provisioning', [
            'summary' => (new RegulatoryReports)->portfolioQuality(),
            'history' => LoanProvision::selectRaw('as_of, COUNT(*) n, SUM(required_provision) req, SUM(movement) mov')->groupBy('as_of')->orderByDesc('as_of')->take(12)->get(),
            'lossLoans' => LoanAccount::with('customer')->whereIn('status', ['active', 'overdue'])->where('classification', 'loss')->get()->reject(fn ($l) => WriteOff::where('loan_account_id', $l->id)->where('status', 'pending_approval')->exists()),
            'writeOffs' => WriteOff::with(['loanAccount', 'requester'])->latest('id')->get(),
        ]);
    }
}
