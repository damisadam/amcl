<?php

namespace App\Livewire\Ops\Finance;

use App\Livewire\Concerns\HandlesActions;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\PeriodService;
use App\Services\Tenancy\TenantSettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.ops')]
class Periods extends Component
{
    use HandlesActions;

    public ?int $reopenId = null;

    public bool $showReopen = false;

    public string $reason = '';

    public ?int $checkId = null;

    public function mount(): void
    {
        $this->need('fms.view');
    }

    public function createNextYear(): void
    {
        $this->need('fms.periods.manage');
        $last = FiscalYear::orderByDesc('start_date')->first();
        $year = $last ? $last->start_date->year + 1 : now()->year;
        $this->attempt(fn () => app(PeriodService::class)->createFiscalYear($year, (int) TenantSettings::get('finance', 'fiscal_year_start_month', 7)), 'Fiscal year created.');
    }

    public function close(int $id): void
    {
        $this->need('fms.periods.manage');
        $this->attempt(fn () => app(PeriodService::class)->close(AccountingPeriod::findOrFail($id), auth()->user()), 'Period closed. Further postings into it are blocked.');
    }

    public function check(int $id): void
    {
        $this->checkId = $this->checkId === $id ? null : $id;
    }

    public function askReopen(int $id): void
    {
        $this->need('fms.periods.reopen');
        $this->reopenId = $id;
        $this->reason = '';
        $this->showReopen = true;
    }

    public function reopen(): void
    {
        $this->need('fms.periods.reopen');
        if ($this->attempt(fn () => app(PeriodService::class)->reopen(AccountingPeriod::findOrFail($this->reopenId), auth()->user(), $this->reason), 'Period reopened (audited).')) {
            $this->showReopen = false;
        }
    }

    public function render(PeriodService $svc)
    {
        return view('livewire.ops.finance.periods', [
            'years' => FiscalYear::with(['periods' => fn ($q) => $q->orderBy('start_date')->with('closer')])->orderByDesc('start_date')->get(),
            'checklist' => $this->checkId ? $svc->closeChecklist(AccountingPeriod::findOrFail($this->checkId)) : null,
        ]);
    }
}
