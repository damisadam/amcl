<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\CorporateStaff;
use App\Models\PayrollRun;
use App\Models\StaffSalary;
use App\Services\Accounting\FinanceOperations;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Payroll & HR finance (FMS §8) – internal records only; no live payroll integration. */
#[Layout('layouts.ops')]
class Payroll extends Component
{
    use HandlesActions;

    public string $period = '';

    public ?int $runId = null;

    public string $tab = 'runs';

    public function mount(): void
    {
        $this->need('fms.view', 'fms.payroll.manage');
        $this->period = now()->format('Y-m');
    }

    public function prepare(): void
    {
        $this->need('fms.payroll.manage');
        $this->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $run = $this->attempt(fn () => app(FinanceOperations::class)->preparePayroll($this->period, auth()->user()), 'Payroll prepared and submitted for approval.');
        if ($run) {
            $this->runId = $run->id;
        }
    }

    public function show(int $id): void
    {
        $this->runId = $this->runId === $id ? null : $id;
    }

    public function render()
    {
        // Staff loan deduction reconciliation (FMS §8.2): salary deductions recorded vs repayments received via salary deduction.
        $recon = StaffSalary::query()->selectRaw('period, SUM(loan_deduction) deducted')->groupBy('period')->orderByDesc('period')->take(12)->get()
            ->map(function ($r) {
                $received = \App\Models\LoanPayment::where('channel', 'salary_deduction')->where('status', 'succeeded')
                    ->whereRaw("DATE_FORMAT(received_at, '%Y-%m') = ?", [$r->period])->sum('amount');

                return ['period' => $r->period, 'deducted' => $r->deducted, 'received' => $received, 'difference' => \App\Support\Money::sub($r->deducted, $received)];
            });

        return view('livewire.ops.finance.payroll', [
            'runs' => PayrollRun::with('creator')->latest('period')->get(),
            'run' => $this->runId ? PayrollRun::with('lines.employee')->find($this->runId) : null,
            'recon' => $recon,
            'staffCount' => CorporateStaff::count(),
        ]);
    }
}
