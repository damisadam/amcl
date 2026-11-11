<?php

namespace App\Services\Lending;

use App\Exceptions\DomainException;
use App\Models\CorporateClient;
use App\Models\CorporateStaff;
use App\Models\Customer;
use App\Models\Instalment;
use App\Models\LoanAccount;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Carbon\Carbon;

/** Corporate client / staff lending programme (LMS §5) incl. payroll-deduction reconciliation (FMS §8.2). */
class StaffLendingService
{
    public function __construct(private RepaymentService $repayments) {}

    public function saveCorporate(array $data, User $by, ?CorporateClient $c = null): CorporateClient
    {
        $c ??= new CorporateClient(['code' => 'CORP-'.strtoupper(substr(uniqid(), -5))]);
        $c->fill($data);
        Auditor::changes($c->exists ? 'staff_lending.corporate_updated' : 'staff_lending.corporate_created', $c);
        $c->save();

        return $c;
    }

    public function saveStaff(CorporateClient $corp, array $data, User $by, ?CorporateStaff $s = null): CorporateStaff
    {
        if (CorporateStaff::where('corporate_client_id', $corp->id)->where('employee_no', $data['employee_no'])->when($s, fn ($q) => $q->whereKeyNot($s->id))->exists()) {
            throw new DomainException('Employee number already exists for this corporate client.');
        }
        $s ??= new CorporateStaff(['corporate_client_id' => $corp->id]);
        $s->fill($data);
        Auditor::changes($s->exists ? 'staff_lending.staff_updated' : 'staff_lending.staff_added', $s);
        $s->save();

        return $s;
    }

    public function linkCustomer(CorporateStaff $s, string $cnicOrCif, User $by): CorporateStaff
    {
        $c = Customer::where('cif_no', $cnicOrCif)->orWhere('cnic', $cnicOrCif)->first();
        if (! $c) {
            throw new DomainException('No customer with that CIF or CNIC at this institution.');
        }
        $s->update(['customer_id' => $c->id]);
        $c->forceFill(['corporate_client_id' => $s->corporate_client_id, 'client_type' => 'staff'])->save();
        Auditor::record('staff_lending.customer_linked', $s, [], ['cif' => $c->cif_no]);

        return $s;
    }

    /** Record (or replace) one salary row. Loan deduction defaults to the instalments due in the period. */
    public function recordSalary(CorporateStaff $s, string $period, string $gross, ?string $deduction = null, string $source = 'manual'): StaffSalary
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new DomainException('Period must be YYYY-MM.');
        }
        $deduction ??= $this->dueInPeriod($s, $period);
        if (Money::gt($deduction, $gross)) {
            throw new DomainException('Loan deduction cannot exceed gross salary.');
        }
        $row = StaffSalary::updateOrCreate(['corporate_staff_id' => $s->id, 'period' => $period],
            ['gross' => Money::of($gross), 'loan_deduction' => Money::of($deduction), 'net' => Money::sub($gross, $deduction), 'source' => $source]);
        Auditor::record('staff_lending.salary_recorded', $s, [], ['period' => $period, 'gross' => $gross, 'deduction' => $deduction]);

        return $row;
    }

    /**
     * CSV import: employee_no,period,gross[,loan_deduction]. Returns [imported, errors[]].
     */
    public function importCsv(CorporateClient $corp, string $csv): array
    {
        $ok = 0;
        $errors = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($csv)) as $i => $line) {
            if ($line === '' || ($i === 0 && str_contains(strtolower($line), 'employee'))) {
                continue;
            }
            $cols = array_map('trim', str_getcsv($line));
            [$emp, $period, $gross] = array_pad($cols, 3, null);
            $ded = $cols[3] ?? null;
            $staff = CorporateStaff::where('corporate_client_id', $corp->id)->where('employee_no', $emp)->first();
            try {
                if (! $staff) {
                    throw new DomainException("unknown employee {$emp}");
                }
                if (! is_numeric($gross) || ($ded !== null && $ded !== '' && ! is_numeric($ded))) {
                    throw new DomainException('invalid amount');
                }
                $this->recordSalary($staff, (string) $period, (string) $gross, $ded === '' ? null : $ded, 'import');
                $ok++;
            } catch (DomainException $e) {
                $errors[] = 'Line '.($i + 1).': '.$e->getMessage();
            }
        }

        return [$ok, $errors];
    }

    public function staffLoans(CorporateStaff $s)
    {
        return $s->customer_id ? LoanAccount::where('customer_id', $s->customer_id)->whereIn('status', ['active', 'overdue'])->get() : collect();
    }

    public function dueInPeriod(CorporateStaff $s, string $period): string
    {
        if (! $s->customer_id) {
            return '0.00';
        }
        $start = Carbon::parse($period.'-01');
        $sum = Instalment::whereIn('loan_account_id', LoanAccount::where('customer_id', $s->customer_id)->whereIn('status', ['active', 'overdue'])->pluck('id'))
            ->whereBetween('due_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->selectRaw('COALESCE(SUM(total_due + late_charge_due - total_paid),0) v')->value('v');

        return Money::max(Money::of($sum), '0');
    }

    /** Reconciliation rows for a corporate & period: salary deduction vs instalments due vs already recovered. */
    public function reconciliation(CorporateClient $corp, string $period): array
    {
        $rows = [];
        foreach (CorporateStaff::with('customer')->where('corporate_client_id', $corp->id)->orderBy('name')->get() as $s) {
            $salary = StaffSalary::where('corporate_staff_id', $s->id)->where('period', $period)->first();
            $recovered = $s->customer_id ? Money::of(\App\Models\LoanPayment::where('customer_id', $s->customer_id)->where('channel', 'salary_deduction')
                ->where('status', 'succeeded')->where('external_ref', 'like', 'SAL-'.$period.'-%')->sum('amount')) : '0.00';
            $deduction = $salary?->loan_deduction ?? '0.00';
            $rows[] = [
                'staff' => $s, 'due' => $this->dueInPeriod($s, $period), 'deduction' => Money::of($deduction), 'recovered' => $recovered,
                'pending' => Money::max(Money::sub($deduction, $recovered), '0'), 'has_salary' => (bool) $salary,
            ];
        }

        return $rows;
    }

    /** Post the salary deduction as a repayment (channel salary_deduction) on the staff member's oldest open loan. */
    public function postDeduction(CorporateStaff $s, string $period, User $by): ?\App\Models\LoanPayment
    {
        $row = collect($this->reconciliation($s->corporateClient, $period))->firstWhere(fn ($r) => $r['staff']->id === $s->id);
        if (! $row || ! Money::isPositive($row['pending'])) {
            throw new DomainException('Nothing pending to post for this staff member and period.');
        }
        $loan = $this->staffLoans($s)->sortBy('id')->first() ?? throw new DomainException('Staff member has no open financing.');

        return $this->repayments->recordManual($loan, $row['pending'], 'salary_deduction', 'SAL-'.$period.'-'.$s->employee_no, $by, Carbon::parse($period.'-01')->endOfMonth()->min(today())->toDateString());
    }
}
