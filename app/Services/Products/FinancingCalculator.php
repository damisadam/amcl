<?php

namespace App\Services\Products;

use App\Models\Holiday;
use App\Models\ProductVersion;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Deterministic financing schedule calculator.
 *
 *  - flat:     profit = principal × annual rate × tenor-in-years, spread evenly (Murabaha markup).
 *  - reducing: equal instalments where each period's profit = outstanding × periodic rate
 *              (Diminishing Musharakah / Ijarah rental style).
 *  - Charges "added_to_instalments" are spread evenly; "upfront_deducted" reduce the net disbursed.
 *  - Due dates roll forward past holidays / non-working days of the tenant calendar.
 *  - APR is the annualised internal rate of return of net disbursed vs. instalments (incl. fees).
 *
 * All amounts are bcmath strings; instalment components always sum exactly to their totals.
 */
class FinancingCalculator
{
    public function quote(ProductVersion $v, string $principal, int $tenure, ?CarbonInterface $startDate = null): array
    {
        $principal = Money::of($principal);
        $periodsPerYear = $v->isWeekly() ? 52 : 12;
        $rate = (string) $v->profit_rate;

        [$upfront, $spread, $chargeLines] = $this->charges($v, $principal);
        $rows = $v->profit_method === 'reducing'
            ? $this->reducing($principal, $rate, $tenure, $periodsPerYear)
            : $this->flat($principal, $rate, $tenure, $periodsPerYear);

        $chargeParts = Money::split($spread, $tenure);
        $dates = $this->dueDates($startDate ?? now(), $tenure, $v->isWeekly());
        $schedule = [];
        foreach ($rows as $i => $r) {
            $charges = $chargeParts[$i];
            $schedule[] = [
                'number' => $i + 1,
                'due_date' => $dates[$i]->toDateString(),
                'principal_due' => $r['principal'],
                'profit_due' => $r['profit'],
                'charges_due' => $charges,
                'total_due' => Money::add($r['principal'], $r['profit'], $charges),
            ];
        }

        $profitTotal = Money::add(...array_column($rows, 'profit'));
        $totalPayable = Money::add(...array_column($schedule, 'total_due'));
        $net = Money::sub($principal, $upfront);

        return [
            'principal' => $principal,
            'profit_total' => $profitTotal,
            'charges_spread' => $spread,
            'charges_upfront' => $upfront,
            'charge_lines' => $chargeLines,
            'net_disbursed' => $net,
            'total_payable' => $totalPayable,
            'instalment' => $schedule[0]['total_due'] ?? '0.00',
            'profit_rate' => $rate,
            'profit_method' => $v->profit_method,
            'frequency' => $v->frequency,
            'tenure' => $tenure,
            'apr' => $this->apr($net, array_column($schedule, 'total_due'), $periodsPerYear),
            'first_due_date' => $schedule[0]['due_date'] ?? null,
            'maturity_date' => end($schedule)['due_date'] ?? null,
            'schedule' => $schedule,
        ];
    }

    /** @return array{0:string,1:string,2:array} upfront-deducted total, spread total, charge lines */
    public function charges(ProductVersion $v, string $principal): array
    {
        $upfront = '0.00';
        $spread = '0.00';
        $lines = [];
        $vendorMode = $v->disbursement_mode === 'vendor';
        foreach ($v->charges as $c) {
            if (in_array($c->type->value, ['late_payment', 'prepayment'], true) || $c->apply_at === 'on_event') {
                $lines[] = ['name' => $c->name, 'type' => $c->type->value, 'amount' => null, 'basis' => $c->calculation === 'flat' ? Money::format($c->value) : rtrim(rtrim((string) $c->value, '0'), '.').'%', 'when' => 'on event'];

                continue;
            }
            $amount = $c->calculation === 'percentage' ? Money::pct($principal, (string) $c->value) : Money::of($c->value);
            // Vendor-paid assets cannot have deductions from the payout: such charges are spread instead (DECISIONS D-009).
            $applyAt = ($vendorMode && $c->apply_at === 'upfront_deducted') ? 'added_to_instalments' : $c->apply_at;
            if ($applyAt === 'upfront_deducted') {
                $upfront = Money::add($upfront, $amount);
            } else {
                $spread = Money::add($spread, $amount);
            }
            $lines[] = ['name' => $c->name, 'type' => $c->type->value, 'amount' => $amount, 'when' => $applyAt];
        }

        return [$upfront, $spread, $lines];
    }

    private function flat(string $principal, string $rate, int $n, int $periodsPerYear): array
    {
        $years = bcdiv((string) $n, (string) $periodsPerYear, 8);
        $profit = Money::of(bcdiv(bcmul(bcmul($principal, $rate, 8), $years, 8), '100', 8));
        $p = Money::split($principal, $n);
        $q = Money::split($profit, $n);

        return array_map(fn ($i) => ['principal' => $p[$i], 'profit' => $q[$i]], range(0, $n - 1));
    }

    private function reducing(string $principal, string $rate, int $n, int $periodsPerYear): array
    {
        $r = bcdiv($rate, (string) (100 * $periodsPerYear), 12);
        if (bccomp($r, '0', 12) === 0) {
            return $this->flat($principal, '0', $n, $periodsPerYear);
        }
        $pow = bcpow(bcadd('1', $r, 12), (string) $n, 12);
        $emi = Money::of(bcdiv(bcmul(bcmul($principal, $r, 12), $pow, 12), bcsub($pow, '1', 12), 12));
        $out = [];
        $outstanding = $principal;
        for ($i = 0; $i < $n; $i++) {
            $profit = Money::of(bcmul($outstanding, $r, 12));
            $prin = $i === $n - 1 ? $outstanding : Money::sub($emi, $profit);
            $out[] = ['principal' => $prin, 'profit' => $profit];
            $outstanding = Money::sub($outstanding, $prin);
        }

        return $out;
    }

    /** @return list<Carbon> */
    public function dueDates(CarbonInterface $start, int $n, bool $weekly): array
    {
        $holidays = Holiday::pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();
        $workingDays = (array) TenantSettings::get('working_days', 'days', [1, 2, 3, 4, 5]);
        $dates = [];
        for ($i = 1; $i <= $n; $i++) {
            $d = $weekly ? Carbon::parse($start)->addWeeks($i) : Carbon::parse($start)->addMonthsNoOverflow($i);
            $guard = 0;
            while ((isset($holidays[$d->toDateString()]) || ! in_array($d->dayOfWeekIso, $workingDays, true)) && $guard++ < 14) {
                $d->addDay();
            }
            $dates[] = $d->startOfDay();
        }

        return $dates;
    }

    /** Annualised IRR (%), solved by bisection on the periodic rate. */
    private function apr(string $net, array $instalments, int $periodsPerYear): string
    {
        if (! Money::isPositive($net) || ! $instalments) {
            return '0.00';
        }
        $npv = function (float $r) use ($net, $instalments) {
            $v = -(float) $net;
            foreach ($instalments as $k => $a) {
                $v += (float) $a / ((1 + $r) ** ($k + 1));
            }

            return $v;
        };
        $lo = 0.0;
        $hi = 1.0;
        if ($npv($lo) <= 0) {
            return '0.00';
        }
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2;
            $npv($mid) > 0 ? $lo = $mid : $hi = $mid;
        }

        return number_format($lo * $periodsPerYear * 100, 2, '.', '');
    }
}
