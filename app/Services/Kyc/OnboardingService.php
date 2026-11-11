<?php

namespace App\Services\Kyc;

use App\Enums\AmlStatus;
use App\Enums\ConsentType;
use App\Enums\CustomerStatus;
use App\Enums\KycStatus;
use App\Enums\KycStep;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Enums\VerificationResult;
use App\Exceptions\DomainException;
use App\Models\BlacklistEntry;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\KycVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Integrations\KycGateway;
use App\Services\Notifications\Notifier;
use App\Services\Support\Numbering;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;

/**
 * Single onboarding + e-KYC stack shared by DFS and Wallet (Agreement §6): registration, OTP,
 * profile, itemised consent, CNIC OCR → NADRA Verisys → liveness → sanctions/PEP, manual review.
 */
class OnboardingService
{
    public function __construct(private KycGateway $kyc, private Notifier $notifier) {}

    public function register(Tenant $tenant, array $data): User
    {
        $mobile = self::normaliseMobile($data['mobile']);

        return DB::transaction(function () use ($tenant, $data, $mobile) {
            if (Customer::where('mobile', $mobile)->exists() || User::where('mobile', $mobile)->exists()) {
                throw new DomainException('This mobile number is already registered. Please log in.');
            }
            $customer = new Customer([
                'cif_no' => Numbering::next('customer', $tenant->id, false, 7),
                'full_name' => $data['name'], 'mobile' => $mobile, 'email' => $data['email'] ?? null,
                'onboarding_channel' => 'web', 'onboarding_step' => 'otp', 'preferred_locale' => $data['locale'] ?? 'en',
                'branch_id' => \App\Models\Branch::where('is_head_office', true)->value('id'),
            ]);
            $customer->forceFill(['status' => CustomerStatus::Pending, 'kyc_status' => KycStatus::NotStarted, 'aml_status' => AmlStatus::NotScreened])->save();

            $user = new User(['name' => $data['name'], 'mobile' => $mobile, 'email' => $data['email'] ?? null, 'locale' => $data['locale'] ?? 'en']);
            $user->forceFill(['tenant_id' => $tenant->id, 'type' => UserType::Customer, 'status' => UserStatus::Active, 'customer_id' => $customer->id,
                'password' => $data['password'], 'password_changed_at' => now()])->save();
            Auditor::record('customer.registered', $customer, [], ['cif' => $customer->cif_no, 'channel' => 'web']);

            return $user;
        });
    }

    public function markMobileVerified(User $user): void
    {
        $user->forceFill(['mobile_verified_at' => now()])->save();
        $user->customer?->forceFill(['onboarding_step' => 'personal'])->save();
        if ($user->customer) {
            $this->recordStep($user->customer, KycStep::MobileOtp, VerificationResult::Passed, ['mobile' => $user->mobile]);
        }
        Auditor::record('customer.mobile_verified', $user->customer);
    }

    public function updateProfile(Customer $c, array $data, ?User $actor = null, ?string $step = null): Customer
    {
        if ($c->kyc_status === KycStatus::Passed && isset($data['cnic']) && $data['cnic'] !== $c->cnic) {
            throw new DomainException('CNIC cannot be changed after verification. Submit a change request instead.');
        }
        if (isset($data['cnic']) && $data['cnic'] !== $c->cnic && Customer::where('cnic', $data['cnic'])->whereKeyNot($c->id)->exists()) {
            throw new DomainException('A customer with this CNIC is already registered at this institution.');
        }
        $c->fill($data);
        if ($step) {
            $c->onboarding_step = $step;
        }
        Auditor::changes('customer.updated', $c);
        $c->save();
        if ($c->user && isset($data['full_name'])) {
            $c->user->update(['name' => $data['full_name']]);
        }

        return $c;
    }

    /** Itemised consents (DFS §1.7). $items: consent type => bool */
    public function recordConsents(Customer $c, array $items, string $locale, ?string $ip, ?string $ua): void
    {
        foreach ($items as $type => $granted) {
            $type = ConsentType::from($type);
            Consent::create([
                'customer_id' => $c->id, 'consent_type' => $type, 'version' => '1.0', 'granted' => (bool) $granted, 'locale' => $locale,
                'text_snapshot' => __('consents.'.$type->value, [], $locale), 'ip' => $ip, 'user_agent' => $ua ? substr($ua, 0, 500) : null, 'recorded_at' => now(),
            ]);
        }
        Auditor::record('customer.consents_recorded', $c, [], ['items' => $items]);
    }

    public function hasConsent(Customer $c, ConsentType $type): bool
    {
        return Consent::where('customer_id', $c->id)->where('consent_type', $type->value)->where('granted', true)->whereNull('withdrawn_at')->exists();
    }

    /**
     * Run the e-KYC sequence. Deterministic outcome by CNIC last digit unless $scenario is forced (demo only).
     * Returns the resulting KYC status.
     */
    public function runEkyc(Customer $c, string $scenario = 'auto', ?User $actor = null): KycStatus
    {
        // QA S5: a forced outcome is honoured only for demo tenants in demo mode, and is audited.
        $scenario = \App\Services\Payments\Simulation::scenario($scenario, 'kyc', $c) ?? 'auto';
        foreach (['full_name', 'cnic', 'date_of_birth'] as $f) {
            if (blank($c->{$f})) {
                throw new DomainException('Complete your personal details (name, CNIC, date of birth) first.');
            }
        }
        if (! $this->hasConsent($c, ConsentType::NadraVerification)) {
            throw new DomainException('Consent for NADRA identity verification is required first.');
        }
        if (! $c->documents()->whereHas('type', fn ($q) => $q->where('code', 'CNIC_FRONT'))->exists()) {
            throw new DomainException('Upload the front of your CNIC first.');
        }

        return DB::transaction(function () use ($c, $scenario) {
            $c->forceFill(['kyc_status' => KycStatus::InProgress])->save();
            $results = [];

            $ocr = $this->kyc->ocr($c, $scenario === 'fail' ? 'pass' : $scenario);
            $results[] = $this->recordStep($c, KycStep::CnicOcr, $ocr->successful() ? VerificationResult::Passed : VerificationResult::Failed, $ocr->data, $ocr->logId, $ocr->get('confidence') ? $ocr->get('confidence') * 100 : null);

            $nadra = $this->kyc->nadra($c, $scenario);
            $nadraResult = ! $nadra->successful() ? VerificationResult::Failed : ($nadra->get('match') === 'partial' ? VerificationResult::ManualReview : VerificationResult::Passed);
            $results[] = $this->recordStep($c, KycStep::NadraVerisys, $nadraResult, $nadra->data + ['message' => $nadra->message], $nadra->logId);

            $live = $this->kyc->liveness($c, $scenario);
            $score = (float) $live->get('face_match_score', 0);
            $liveResult = ! $live->successful() ? VerificationResult::Failed : ($score < 0.80 ? VerificationResult::ManualReview : VerificationResult::Passed);
            $results[] = $this->recordStep($c, KycStep::Liveness, $liveResult, $live->data, $live->logId, $score * 100);

            $aml = $this->kyc->screen($c->full_name, $c->cnic, $c->date_of_birth, $scenario, $c);
            $hit = $aml->get('result') === 'potential_match';
            $blacklisted = BlacklistEntry::where('cnic', $c->cnic)->where('is_active', true)->exists();
            $results[] = $this->recordStep($c, KycStep::AmlScreening, $hit ? VerificationResult::ManualReview : VerificationResult::Passed, $aml->data, $aml->logId);

            $c->aml_status = $hit ? AmlStatus::PotentialMatch : AmlStatus::Clear;
            $c->aml_risk = $aml->get('risk');
            $c->is_blacklisted = $blacklisted;

            $status = match (true) {
                in_array(VerificationResult::Failed, $results, true) => KycStatus::Failed,
                in_array(VerificationResult::ManualReview, $results, true) => KycStatus::PendingReview,
                default => KycStatus::Passed,
            };
            $this->applyKycStatus($c, $status);
            Auditor::record('kyc.completed', $c, [], ['status' => $status->value, 'aml' => $c->aml_status->value, 'scenario' => $scenario]);

            return $status;
        });
    }

    public function recordStep(Customer $c, KycStep $step, VerificationResult $result, array $data = [], ?int $logId = null, ?float $score = null): VerificationResult
    {
        KycVerification::create([
            'customer_id' => $c->id, 'step' => $step, 'provider' => match ($step) {
                KycStep::CnicOcr => 'cnic_ocr', KycStep::NadraVerisys => 'nadra_verisys', KycStep::Liveness => 'liveness', KycStep::AmlScreening => 'aml_screening', default => 'sms',
            }, 'status' => $result, 'score' => $score, 'result' => $data, 'integration_log_id' => $logId,
        ]);

        return $result;
    }

    /** Manual KYC decision (identity) by an authorized reviewer. */
    public function reviewKyc(Customer $c, User $reviewer, bool $approve, string $notes): Customer
    {
        if (! $reviewer->hasPermission('kyc.review')) {
            throw new DomainException('You are not authorized to decide KYC reviews.');
        }
        if ($c->kyc_status !== KycStatus::PendingReview) {
            throw new DomainException('This customer is not awaiting manual KYC review.');
        }
        if (blank($notes)) {
            throw new DomainException('Review notes are required.');
        }

        return DB::transaction(function () use ($c, $reviewer, $approve, $notes) {
            KycVerification::where('customer_id', $c->id)->where('status', VerificationResult::ManualReview->value)
                ->whereIn('step', [KycStep::NadraVerisys->value, KycStep::Liveness->value])->whereNull('reviewed_at')
                ->update(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_decision' => $approve ? 'approved' : 'rejected', 'review_notes' => $notes]);
            $amlPending = in_array($c->aml_status, [AmlStatus::PotentialMatch], true);
            $status = ! $approve ? KycStatus::Failed : ($amlPending ? KycStatus::PendingReview : KycStatus::Passed);
            if ($approve && $amlPending) {
                // identity cleared; still waiting for compliance (AML) decision
                Auditor::record('kyc.identity_review_approved', $c, [], [], $notes);

                return $c;
            }
            $this->applyKycStatus($c, $status);
            Auditor::record('kyc.reviewed', $c, ['kyc_status' => 'pending_review'], ['kyc_status' => $status->value], $notes);

            return $c;
        });
    }

    /** Compliance decision on an AML/PEP potential match. */
    public function reviewAml(Customer $c, User $reviewer, bool $clear, string $notes): Customer
    {
        if (! $reviewer->hasPermission('aml.review')) {
            throw new DomainException('Only compliance can decide AML/CFT matches.');
        }
        if ($c->aml_status !== AmlStatus::PotentialMatch) {
            throw new DomainException('There is no open AML/CFT match for this customer.');
        }
        if (blank($notes)) {
            throw new DomainException('Review notes are required.');
        }

        return DB::transaction(function () use ($c, $reviewer, $clear, $notes) {
            KycVerification::where('customer_id', $c->id)->where('step', KycStep::AmlScreening->value)->whereNull('reviewed_at')
                ->update(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_decision' => $clear ? 'cleared' : 'confirmed', 'review_notes' => $notes]);
            $c->aml_status = $clear ? AmlStatus::ClearedAfterReview : AmlStatus::ConfirmedMatch;
            if (! $clear) {
                $c->is_blacklisted = true;
                $c->blacklist_reason = 'AML/CFT confirmed match';
                BlacklistEntry::create(['cnic' => $c->cnic, 'name' => $c->full_name, 'reason' => 'aml', 'notes' => $notes, 'added_by' => $reviewer->id]);
            }
            $identityPending = KycVerification::where('customer_id', $c->id)->where('status', VerificationResult::ManualReview->value)
                ->whereIn('step', [KycStep::NadraVerisys->value, KycStep::Liveness->value])->whereNull('reviewed_at')->exists();
            $identityFailed = KycVerification::where('customer_id', $c->id)->where('review_decision', 'rejected')->exists();
            $status = ! $clear ? KycStatus::Failed : ($identityFailed ? KycStatus::Failed : ($identityPending ? KycStatus::PendingReview : KycStatus::Passed));
            $this->applyKycStatus($c, $status);
            Auditor::record('aml.reviewed', $c, ['aml_status' => 'potential_match'], ['aml_status' => $c->aml_status->value], $notes);

            return $c;
        });
    }

    private function applyKycStatus(Customer $c, KycStatus $status): void
    {
        $c->kyc_status = $status;
        $c->onboarding_step = match ($status) { KycStatus::Passed => 'complete', KycStatus::Failed => 'kyc_failed', default => 'kyc_review' };
        if ($status === KycStatus::Passed) {
            $c->status = CustomerStatus::Active;
            $c->activated_at ??= now();
        }
        $c->save();

        if ($status === KycStatus::Passed && $c->tenant->hasFeature('wallet')) {
            $wallets = app(WalletService::class);
            $wallet = $wallets->open($c);
            if (! $wallets->unmetPrerequisites($wallet) && $wallet->status->value === 'pending') {
                $wallets->activate($wallet);
            }
        }
        DB::afterCommit(fn () => $this->notifier->toCustomer($c, 'kyc_'.$status->value, [
            'title' => match ($status) {
                KycStatus::Passed => 'Identity verified', KycStatus::Failed => 'Identity verification unsuccessful', default => 'Verification under review',
            },
            'body' => match ($status) {
                KycStatus::Passed => 'Your account is active. You can now apply for financing and use your wallet.',
                KycStatus::Failed => 'We could not verify your identity. Please visit a branch or contact support.',
                default => 'Your details are being reviewed by our team. We will notify you shortly.',
            },
        ]));
    }

    /** Sensitive field change (e.g. CNIC correction) requires approval (LMS §2.2). */
    public function requestChange(Customer $c, string $field, string $newValue, string $reason, User $by): CustomerChangeRequest
    {
        if (! in_array($field, ['cnic', 'full_name', 'date_of_birth', 'father_husband_name'], true)) {
            throw new DomainException('Only identity fields require a change request.');
        }
        $req = $c->changeRequests()->create(['field' => $field, 'old_value' => (string) $c->{$field}, 'new_value' => $newValue, 'reason' => $reason, 'requested_by' => $by->id]);
        Auditor::record('customer.change_requested', $c, [$field => $c->{$field}], [$field => $newValue], $reason);

        return $req;
    }

    public function decideChange(CustomerChangeRequest $req, User $by, bool $approve, string $comment): void
    {
        if (! $by->hasPermission('customers.approve_changes')) {
            throw new DomainException('You are not authorized to approve customer changes.');
        }
        if ((int) $req->requested_by === (int) $by->id) {
            throw new DomainException('Segregation of duties: you cannot approve your own change request.');
        }
        if ($req->status !== 'pending') {
            throw new DomainException('This request was already decided.');
        }
        DB::transaction(function () use ($req, $by, $approve, $comment) {
            $req->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_comment' => $comment]);
            if ($approve) {
                $c = $req->customer;
                $c->{$req->field} = $req->new_value;
                Auditor::changes('customer.identity_changed', $c, $comment);
                $c->save();
            }
        });
    }

    public static function normaliseMobile(string $mobile): string
    {
        $d = preg_replace('/\D/', '', $mobile);
        if (str_starts_with($d, '92')) {
            $d = '0'.substr($d, 2);
        }

        return $d;
    }
}
