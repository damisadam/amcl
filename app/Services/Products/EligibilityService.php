<?php

namespace App\Services\Products;

use App\Enums\AmlStatus;
use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Models\BlacklistEntry;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Models\ProductVersion;
use App\Services\Tenancy\TenantSettings;
use App\Support\Money;

/** Product eligibility gate (LMS §3, DFS §1.2/§2.2). Returns human-readable failures; empty = eligible. */
class EligibilityService
{
    public function __construct(private FinancingCalculator $calculator) {}

    public function check(Customer $customer, ProductVersion $v, string $amount, int $tenure, ?LoanApplication $ignore = null, bool $requireKyc = true): array
    {
        $fail = [];
        if (Money::lt($amount, $v->min_amount) || Money::gt($amount, $v->max_amount)) {
            $fail[] = 'Financing amount must be between '.Money::format($v->min_amount).' and '.Money::format($v->max_amount).'.';
        }
        if ($tenure < $v->min_tenure || $tenure > $v->max_tenure) {
            $fail[] = "Tenure must be between {$v->min_tenure} and {$v->max_tenure} ".($v->isWeekly() ? 'weeks' : 'months').'.';
        }
        $age = $customer->age();
        if ($age !== null && ($age < $v->min_age || $age > $v->max_age)) {
            $fail[] = "Applicant age must be between {$v->min_age} and {$v->max_age} years.";
        }
        if (Money::lt($customer->totalIncome(), $v->min_income)) {
            $fail[] = 'Minimum monthly income for this product is '.Money::format($v->min_income).'.';
        }
        if ($requireKyc && $customer->kyc_status !== KycStatus::Passed) {
            $fail[] = 'Identity verification (KYC) must be completed and passed.';
        }
        if (in_array($customer->aml_status, [AmlStatus::ConfirmedMatch], true)) {
            $fail[] = 'Application cannot proceed due to compliance screening.';
        }
        if ($customer->is_blacklisted || ($customer->cnic && BlacklistEntry::where('cnic', $customer->cnic)->where('is_active', true)->exists())) {
            $fail[] = 'Applicant is not eligible for financing at this time.';
        }
        $types = $v->eligibility['employment_types'] ?? null;
        if ($types && $customer->employment_type && ! in_array($customer->employment_type, $types, true)) {
            $fail[] = 'This product is available to: '.implode(', ', array_map('ucwords', str_replace('_', ' ', $types))).'.';
        }

        $open = LoanApplication::where('customer_id', $customer->id)->where('product_id', $v->product_id)
            ->whereNotIn('status', [ApplicationStatus::Disbursed->value, ApplicationStatus::Rejected->value, ApplicationStatus::Withdrawn->value, ApplicationStatus::Cancelled->value])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))->exists();
        if ($open) {
            $fail[] = 'You already have an open application for this product.';
        }

        $wait = (int) TenantSettings::get('lending', 'reapplication_wait_days', 30);
        $lastRejected = LoanApplication::where('customer_id', $customer->id)->where('status', ApplicationStatus::Rejected->value)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))->latest('rejected_at')->first();
        if ($lastRejected && $lastRejected->rejected_at && $lastRejected->rejected_at->gt(now()->subDays($wait)) && ! $ignore?->is_reevaluation) {
            $fail[] = "A previous application was declined on {$lastRejected->rejected_at->format('d M Y')}. You may reapply after {$wait} days.";
        }

        // Indicative DBR gate using declared figures (the formal DBR is computed at credit assessment).
        if (Money::isPositive($customer->totalIncome()) && ! $fail) {
            $quote = $this->calculator->quote($v, $amount, $tenure);
            $instalment = $v->isWeekly() ? Money::mul($quote['instalment'], '4.33') : $quote['instalment'];
            $dbr = $this->dbr($customer->totalIncome(), $customer->existing_obligations, $instalment);
            if ($v->dbr_hard_gate && bccomp($dbr, (string) $v->max_dbr, 2) > 0) {
                $fail[] = "Indicative debt burden ratio is {$dbr}%, above the {$v->max_dbr}% limit. Consider a lower amount or longer tenure.";
            }
        }

        return $fail;
    }

    /** DBR % = (existing monthly obligations + proposed monthly instalment) / monthly income × 100 */
    public function dbr(string $income, string $obligations, string $instalment): string
    {
        if (! Money::isPositive($income)) {
            return '999.99';
        }

        return number_format((float) bcdiv(bcmul(Money::add($obligations, $instalment), '100', 6), $income, 6), 2, '.', '');
    }
}
