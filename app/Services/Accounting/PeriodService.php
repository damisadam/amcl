<?php

namespace App\Services\Accounting;

use App\Exceptions\DomainException;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\User;
use App\Services\Audit\Auditor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Fiscal years and period close (FMS §1.5, §15.3). */
class PeriodService
{
    public function createFiscalYear(int $startYear, int $startMonth = 7): FiscalYear
    {
        $start = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addYear()->subDay();
        $name = 'FY'.$start->format('Y').'-'.$end->format('y');
        if ($fy = FiscalYear::where('name', $name)->first()) {
            return $fy;
        }

        return DB::transaction(function () use ($start, $end, $name) {
            $fy = FiscalYear::create(['name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => 'open']);
            for ($m = 0; $m < 12; $m++) {
                $ps = $start->copy()->addMonths($m);
                $fy->periods()->create(['name' => $ps->format('Y-m'), 'start_date' => $ps, 'end_date' => $ps->copy()->endOfMonth(), 'status' => 'open']);
            }

            return $fy;
        });
    }

    /** Checks run before closing; blocking issues prevent the close. */
    public function closeChecklist(AccountingPeriod $period): array
    {
        $issues = [];
        $earlierOpen = AccountingPeriod::where('start_date', '<', $period->start_date)->where('status', 'open')->exists();
        if ($earlierOpen) {
            $issues[] = 'Earlier periods are still open – close periods in order.';
        }
        $pending = JournalVoucher::whereBetween('voucher_date', [$period->start_date, $period->end_date])->whereIn('status', ['draft', 'pending_approval'])->count();
        if ($pending) {
            $issues[] = "{$pending} draft / pending journal voucher(s) are dated in this period.";
        }
        $unbalanced = JournalVoucher::where('accounting_period_id', $period->id)->whereIn('status', ['posted', 'reversed'])->whereColumn('total_debit', '!=', 'total_credit')->count();
        if ($unbalanced) {
            $issues[] = "{$unbalanced} unbalanced voucher(s) found.";
        }

        return $issues;
    }

    public function close(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        if (! $actor->hasPermission('fms.periods.manage')) {
            throw new DomainException('You are not authorized to close periods.');
        }
        if ($period->status === 'closed') {
            return $period;
        }
        if ($issues = $this->closeChecklist($period)) {
            throw new DomainException('Period cannot be closed: '.implode(' ', $issues));
        }
        $period->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
        Auditor::record('fms.period_closed', $period, ['status' => 'open'], ['status' => 'closed']);

        return $period;
    }

    /** Restricted, audited reopening. */
    public function reopen(AccountingPeriod $period, User $actor, string $reason): AccountingPeriod
    {
        if (! $actor->hasPermission('fms.periods.reopen')) {
            throw new DomainException('Reopening a closed period requires the "Reopen closed accounting periods" permission.');
        }
        if (strlen(trim($reason)) < 10) {
            throw new DomainException('Give a meaningful reason (at least 10 characters) for reopening a closed period.');
        }
        if (AccountingPeriod::where('start_date', '>', $period->start_date)->where('status', 'closed')->exists()) {
            throw new DomainException('Later periods are closed; reopen the latest closed period first.');
        }
        $period->update(['status' => 'open', 'reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
        Auditor::record('fms.period_reopened', $period, ['status' => 'closed'], ['status' => 'open'], $reason);

        return $period;
    }
}
