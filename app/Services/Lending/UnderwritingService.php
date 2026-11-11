<?php

namespace App\Services\Lending;

use App\Enums\AmlStatus;
use App\Enums\ApplicationStatus;
use App\Enums\ConsentType;
use App\Enums\FieldVisitStatus;
use App\Events\FieldVisitAssigned;
use App\Exceptions\DomainException;
use App\Models\BureauCheck;
use App\Models\Consent;
use App\Models\CreditAssessment;
use App\Models\FieldVisit;
use App\Models\Guarantor;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Integrations\CreditBureauGateway;
use App\Services\Notifications\Notifier;
use App\Services\Products\EligibilityService;
use App\Services\Products\FinancingCalculator;
use App\Support\Money;
use App\Support\Realtime;

/**
 * Customer verification & underwriting (LMS §10, DFS §2): bureau checks, field visits,
 * income / DBR analysis, internal scoring and composite risk rating.
 *
 * Internal score model (0–100, documented in docs/WORKFLOWS.md §Credit scoring):
 *   bureau 0–35 · DBR 0–25 · income type 6–10 · visit −20…+10 · repeat history −10…+5 · AML −30
 *   grade A ≥80, B+ ≥70, B ≥60, C ≥50, D <50.
 */
class UnderwritingService
{
    public function __construct(
        private CreditBureauGateway $bureau,
        private EligibilityService $eligibility,
        private FinancingCalculator $calculator,
        private Notifier $notifier,
    ) {}

    public function requestBureau(LoanApplication $app, User $actor, string $subjectType = 'applicant', ?Guarantor $guarantor = null, string $provider = 'tasdeeq', string $scenario = 'auto'): BureauCheck
    {
        $scenario = \App\Services\Payments\Simulation::scenario($scenario, 'credit_bureau', $app) ?? 'auto'; // QA S5
        if ($subjectType === 'applicant') {
            $consent = Consent::where('customer_id', $app->customer_id)->where('consent_type', ConsentType::CreditBureau->value)
                ->where('granted', true)->whereNull('withdrawn_at')->latest('recorded_at')->first();
            if (! $consent) {
                throw new DomainException('Credit bureau consent has not been captured for this applicant (DFS §1.7).');
            }
            $cnic = $app->customer->cnic;
            $name = $app->customer->full_name;
            $subjectId = $app->customer_id;
        } else {
            if (! $guarantor || (int) $guarantor->loan_application_id !== (int) $app->id) {
                throw new DomainException('Select a guarantor on this application.');
            }
            if (! $guarantor->consent_at) {
                throw new DomainException('The guarantor has not consented to a credit bureau check.');
            }
            $cnic = $guarantor->cnic;
            $name = $guarantor->name;
            $subjectId = $guarantor->id;
            $consent = null;
        }

        $res = $this->bureau->report($cnic, $name, $provider, $scenario, $app);
        if (! $res->successful()) {
            throw new DomainException($res->message ?? 'Credit bureau request failed. Please retry.');
        }

        $check = $app->bureauChecks()->create([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'subject_cnic' => $cnic, 'provider' => $provider,
            'status' => $res->get('status'), 'score' => $res->get('score'), 'active_facilities' => $res->get('active_facilities'),
            'total_outstanding' => $res->get('total_outstanding'), 'monthly_obligations' => $res->get('monthly_obligations'),
            'max_dpd_12m' => $res->get('max_dpd_12m'), 'report' => $res->data, 'consent_id' => $consent?->id,
            'integration_log_id' => $res->logId, 'requested_by' => $actor->id,
        ]);
        if ($guarantor) {
            $this->refreshGuarantorStatus($guarantor->fresh());
        }
        Auditor::record('underwriting.bureau_check', $app, [], ['subject' => $subjectType, 'provider' => $provider, 'status' => $check->status, 'score' => $check->score]);

        return $check;
    }

    /**
     * Sanctions / PEP screening of a guarantor (C2). Runs automatically when a consenting guarantor is added
     * and can be re-run by staff. The result feeds the guarantor's verification status.
     */
    public function screenGuarantor(Guarantor $g, ?User $actor = null, string $scenario = 'auto'): Guarantor
    {
        if (! $g->consent_at) {
            throw new DomainException('The guarantor has not consented to identity and AML/CFT screening.');
        }
        $res = app(\App\Services\Integrations\KycGateway::class)->screen($g->name, $g->cnic, null, $scenario, $g);
        if (! $res->successful()) {
            throw new DomainException($res->message ?? 'Sanctions / PEP screening failed. Please retry.');
        }
        $g->forceFill([
            'aml_status' => $res->get('result') === 'potential_match' ? 'potential_match' : 'clear',
            'aml_risk' => $res->get('risk'), 'aml_result' => $res->data, 'aml_checked_at' => now(),
        ])->save();
        Auditor::record('underwriting.guarantor_screened', $g->application, [], ['guarantor_id' => $g->id, 'aml' => $g->aml_status]);

        return $this->refreshGuarantorStatus($g);
    }

    /**
     * A guarantor is "verified" only when identity data is complete, income is declared, a bureau report exists
     * (not adverse) and sanctions/PEP screening is clear. Adverse bureau → failed; anything missing → pending.
     *
     * @return array{status:string, reasons:list<string>}
     */
    public function guarantorReadiness(Guarantor $g): array
    {
        $reasons = [];
        if (! preg_match('/^\d{5}-\d{7}-\d$/', (string) $g->cnic) || blank($g->name)) {
            $reasons[] = 'identity (name / CNIC) incomplete';
        }
        if (! Money::isPositive($g->monthly_income ?? 0)) {
            $reasons[] = 'monthly income not recorded';
        }
        if (! $g->consent_at) {
            $reasons[] = 'consent not captured';
        }
        $bureau = BureauCheck::where('loan_application_id', $g->loan_application_id)->where('subject_type', 'guarantor')->where('subject_id', $g->id)->latest('id')->first();
        if (! $bureau) {
            $reasons[] = 'credit bureau report not requested';
        }
        if (! $g->aml_checked_at) {
            $reasons[] = 'sanctions / PEP screening not run';
        } elseif ($g->aml_status !== 'clear') {
            $reasons[] = 'sanctions / PEP potential match – compliance review required';
        }
        $failed = $bureau?->status === 'adverse' || $g->aml_status === 'confirmed_match';

        return ['status' => $failed ? 'failed' : ($reasons ? 'pending' : 'verified'), 'reasons' => $failed ? array_merge(['adverse credit bureau or confirmed AML match'], $reasons) : $reasons];
    }

    public function refreshGuarantorStatus(Guarantor $g): Guarantor
    {
        $status = $this->guarantorReadiness($g)['status'];
        if ($g->verification_status !== $status) {
            $g->forceFill(['verification_status' => $status])->save();
        }

        return $g;
    }

    /** Fingerprint of everything a credit assessment depends on; a mismatch means the assessment is stale (H5). */
    public function assessmentInputs(LoanApplication $app): array
    {
        $c = $app->customer()->first();

        return [
            'bureau' => $app->bureauChecks()->orderBy('id')->pluck('id')->all(),
            'visits' => $app->fieldVisits()->orderBy('id')->get(['id', 'status', 'recommendation'])->map(fn ($v) => [$v->id, $v->status->value, $v->recommendation])->all(),
            'guarantors' => $app->guarantors()->orderBy('id')->get()->map(fn ($g) => [$g->id, $g->verification_status, $g->aml_status, (string) $g->monthly_income])->all(),
            'declared' => [(string) $c->monthly_income, (string) $c->other_income, (string) $c->existing_obligations],
            'amount' => [(string) $app->requested_amount, (int) $app->tenure],
        ];
    }

    public function assessmentFingerprint(LoanApplication $app): string
    {
        return hash('sha256', json_encode($this->assessmentInputs($app)));
    }

    /** null when there is no assessment; otherwise whether it still matches the current inputs. */
    public function isAssessmentStale(LoanApplication $app): ?bool
    {
        $latest = $app->assessments()->first();
        if (! $latest) {
            return null;
        }

        return $latest->input_fingerprint !== $this->assessmentFingerprint($app);
    }

    public function scheduleVisit(LoanApplication $app, User $actor, string $type, User $officer, string $date, ?string $address = null): FieldVisit
    {
        if (! $officer->hasPermission('field_visits.perform') || (int) $officer->tenant_id !== (int) $app->tenant_id) {
            throw new DomainException('Visits can only be assigned to Visit Officers of this institution.');
        }
        $visit = $app->fieldVisits()->create([
            'customer_id' => $app->customer_id,
            'visit_type' => $type,
            'address' => $address ?: ($type === 'business' ? ($app->customer->business_name.', '.$app->customer->current_address) : $app->customer->current_address),
            'assigned_to' => $officer->id, 'assigned_by' => $actor->id,
            'scheduled_for' => $date, 'status' => FieldVisitStatus::Scheduled,
        ]);
        Auditor::record('field_visit.assigned', $visit, [], ['officer' => $officer->name, 'type' => $type, 'date' => $date]);
        $this->notifier->toUser($officer, 'field_visit', ['title' => 'New field visit assigned', 'body' => ucfirst($type).' visit for '.$app->customer->full_name.' on '.$date, 'url' => '/ops/field/visits/'.$visit->id]);
        $this->notifier->toCustomer($app->customer, 'verification_visit', ['title' => 'Verification visit scheduled', 'body' => 'A verification officer will visit on '.$date.'.'], ['in_app', 'sms']);
        Realtime::emit(new FieldVisitAssigned($app->tenant_id, ['visit_id' => $visit->id, 'officer_id' => $officer->id]));

        return $visit;
    }

    public const MAX_GPS_ACCURACY_M = 200;

    public const ADDRESS_DISTANCE_FLAG_M = 500;

    /**
     * Proof of presence (C5): a GPS fix with accuracy ≤ 200 m is mandatory, and check-in is only accepted on the
     * scheduled date; any other day needs a supervisor to reschedule the visit first.
     */
    public function checkIn(FieldVisit $visit, User $officer, ?float $lat, ?float $lng, ?float $accuracy = null): FieldVisit
    {
        $this->assertOfficer($visit, $officer);
        $visit->status->assertCanTransitionTo(FieldVisitStatus::InProgress, 'Field visit');
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            throw new DomainException('A GPS location is required to check in. Allow location access and tap "Capture GPS" first.');
        }
        if ($accuracy === null || $accuracy <= 0 || $accuracy > self::MAX_GPS_ACCURACY_M) {
            throw new DomainException('GPS accuracy must be '.self::MAX_GPS_ACCURACY_M.' m or better'.($accuracy ? ' (current fix ±'.round($accuracy).' m)' : '').'. Move outdoors and capture again.');
        }
        if (! $visit->scheduled_for->isSameDay(today())) {
            throw new DomainException('This visit is scheduled for '.$visit->scheduled_for->format('d M Y').'. Check-in is only allowed on that date; ask your supervisor to reschedule it.');
        }
        $customer = $visit->customer;
        $distance = ($customer?->address_latitude !== null && $customer?->address_longitude !== null)
            ? self::distanceMeters((float) $customer->address_latitude, (float) $customer->address_longitude, $lat, $lng) : null;
        $visit->update(['status' => FieldVisitStatus::InProgress, 'checked_in_at' => now(), 'latitude' => $lat, 'longitude' => $lng,
            'gps_accuracy_m' => round($accuracy, 1), 'distance_from_address_m' => $distance]);
        Auditor::record('field_visit.checked_in', $visit, [], ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracy, 'distance_m' => $distance]);

        return $visit;
    }

    /** Great-circle distance in metres (haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function submitVisit(FieldVisit $visit, User $officer, array $findings): FieldVisit
    {
        $this->assertOfficer($visit, $officer);
        $visit->status->assertCanTransitionTo(FieldVisitStatus::Completed, 'Field visit');
        if ($visit->status !== FieldVisitStatus::InProgress || ! $visit->checked_in_at || $visit->latitude === null) {
            throw new DomainException('Check in at the location (with GPS) before submitting the visit report.');
        }
        if (! in_array($findings['recommendation'] ?? null, ['positive', 'negative', 'follow_up'], true)) {
            throw new DomainException('Select a recommendation.');
        }
        if (! $visit->documents()->exists() && empty($findings['skip_proof'])) {
            throw new DomainException('Attach at least one proof-of-visit photo or document before submitting (LMS §7.4).');
        }
        $visit->update(array_intersect_key($findings, array_flip([
            'address_match', 'living_conditions', 'business_conditions', 'neighbor_verification', 'checklist', 'recommendation', 'remarks',
        ])) + ['status' => FieldVisitStatus::Completed, 'submitted_at' => now()]);
        Auditor::record('field_visit.submitted', $visit, [], ['recommendation' => $visit->recommendation]);

        return $visit;
    }

    private function assertOfficer(FieldVisit $visit, User $officer): void
    {
        if ((int) $visit->assigned_to !== (int) $officer->id) {
            throw new DomainException('This visit is not assigned to you.');
        }
    }

    /**
     * Run (or re-run) the credit assessment. Inputs: monthly_income, other_income, household_expenses,
     * existing_obligations, business_cashflow (optional), notes, approved tenure/amount (optional).
     */
    public function assess(LoanApplication $app, User $actor, array $in): CreditAssessment
    {
        if (! in_array($app->status, [ApplicationStatus::UnderReview, ApplicationStatus::Submitted], true)) {
            throw new DomainException('Credit assessment is performed while the case is under review.');
        }
        $v = $app->productVersion;
        $customer = $app->customer;

        $income = Money::of($in['monthly_income'] ?? $customer->monthly_income);
        $other = Money::of($in['other_income'] ?? $customer->other_income);
        $bureau = $app->bureauChecks()->where('subject_type', 'applicant')->latest('id')->first();
        $declaredObligations = Money::of($in['existing_obligations'] ?? $customer->existing_obligations);
        $obligations = Money::max($declaredObligations, $bureau?->monthly_obligations ?? '0');

        $quote = $this->calculator->quote($v, (string) $app->requested_amount, (int) $app->tenure);
        $instalment = $v->isWeekly() ? Money::mul($quote['instalment'], '4.33') : $quote['instalment'];
        $totalIncome = Money::add($income, $other);
        $dbr = $this->eligibility->dbr($totalIncome, $obligations, $instalment);
        $dbrPass = bccomp($dbr, (string) $v->max_dbr, 2) <= 0;

        $visit = $app->fieldVisits()->where('status', 'completed')->latest('submitted_at')->first();
        // Read the REAL guarantor status (C2): never report "verified" just because guarantors exist.
        $guarantors = $app->guarantors()->get()->map(fn ($g) => $this->refreshGuarantorStatus($g));
        $guarantorFailed = $guarantors->contains('verification_status', 'failed');
        $guarantorResult = $guarantors->isEmpty() ? 'n/a' : ($guarantorFailed ? 'failed' : ($guarantors->every(fn ($g) => $g->verification_status === 'verified') ? 'verified' : 'pending'));
        $aml = $customer->aml_status;

        $breakdown = [];
        $breakdown['bureau'] = match (true) {
            ! $bureau => 0,
            $bureau->status === 'no_hit' => 15,
            default => (int) round(max(0, min(35, ($bureau->score - 300) / 550 * 35))),
        };
        $dbrF = (float) $dbr;
        $breakdown['dbr'] = $dbrF <= 20 ? 25 : ($dbrF <= 30 ? 20 : ($dbrF <= 40 ? 12 : 0));
        $breakdown['income_type'] = match ($customer->employment_type) { 'salaried' => 10, 'business' => 8, 'self_employed' => 6, default => 4 };
        $breakdown['field_visit'] = match ($visit?->recommendation) { 'positive' => 10, 'follow_up' => 4, 'negative' => -20, default => 0 };
        $history = LoanAccount::where('customer_id', $customer->id)->whereKeyNot($app->loanAccount?->id ?? 0)->get();
        $breakdown['history'] = $history->isEmpty() ? 0 : ($history->max('dpd') > 30 ? -10 : 5);
        $breakdown['aml'] = in_array($aml, [AmlStatus::PotentialMatch, AmlStatus::ConfirmedMatch], true) ? -30 : 0;
        $breakdown['guarantor'] = match ($guarantorResult) { 'failed' => -10, 'pending' => -5, default => 0 };
        $breakdown['bureau_adverse'] = $bureau?->status === 'adverse' ? -10 : 0;

        $score = max(0, min(100, 20 + array_sum($breakdown)));
        $grade = $score >= 80 ? 'A' : ($score >= 70 ? 'B+' : ($score >= 60 ? 'B' : ($score >= 50 ? 'C' : 'D')));
        $risk = match (true) {
            $grade === 'D' || $breakdown['aml'] < 0 || $bureau?->status === 'adverse' || $visit?->recommendation === 'negative' => 'high',
            in_array($grade, ['A', 'B+'], true) && $dbrPass => 'low',
            default => 'medium',
        };
        $minGrade = $v->min_credit_grade;
        $gradeOk = ! $minGrade || $this->gradeRank($grade) >= $this->gradeRank($minGrade);
        $recommendation = match (true) {
            (! $dbrPass && $v->dbr_hard_gate) || $grade === 'D' => 'decline',
            $gradeOk && $dbrPass && $risk !== 'high' => 'approve',
            default => 'refer',
        };

        $assessment = $app->assessments()->create([
            'monthly_income' => $income, 'other_income' => $other, 'household_expenses' => Money::of($in['household_expenses'] ?? 0),
            'existing_obligations' => $obligations, 'proposed_instalment' => $instalment, 'dbr' => $dbr, 'dbr_limit' => $v->max_dbr, 'dbr_pass' => $dbrPass,
            'business_cashflow' => $in['business_cashflow'] ?? null, 'bureau_score' => $bureau?->score, 'internal_score' => $score, 'grade' => $grade,
            'visit_result' => $visit?->recommendation, 'aml_result' => $aml->value, 'guarantor_result' => $guarantorResult,
            'risk_rating' => $risk, 'score_breakdown' => $breakdown,
            'input_fingerprint' => $this->assessmentFingerprint($app), 'inputs_snapshot' => $this->assessmentInputs($app), 'recommendation' => $recommendation, 'notes' => $in['notes'] ?? null, 'assessed_by' => $actor->id,
        ]);
        $app->forceFill(['dbr' => $dbr, 'credit_grade' => $grade, 'risk_rating' => $risk, 'indicative_instalment' => $quote['instalment']])->save();
        $customer->forceFill(['risk_rating' => $risk])->save();
        Auditor::record('underwriting.assessed', $app, [], ['dbr' => $dbr, 'score' => $score, 'grade' => $grade, 'risk' => $risk, 'recommendation' => $recommendation]);

        return $assessment;
    }

    private function gradeRank(string $g): int
    {
        return ['D' => 0, 'C' => 1, 'B' => 2, 'B+' => 3, 'A' => 4][$g] ?? 0;
    }
}
