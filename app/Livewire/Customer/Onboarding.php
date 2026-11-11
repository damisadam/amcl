<?php

namespace App\Livewire\Customer;

use App\Enums\ConsentType;
use App\Enums\KycStatus;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Consent;
use App\Models\DocumentType;
use App\Models\KycVerification;
use App\Services\Documents\DocumentStore;
use App\Services\Identity\OtpService;
use App\Services\Kyc\OnboardingService;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/** DFS §1 onboarding wizard: OTP → personal → address & income → next of kin → documents → consents → e-KYC. */
#[Layout('layouts.customer')]
class Onboarding extends Component
{
    use CustomerContext, WithFileUploads;

    public const STEPS = ['otp' => 'Verify mobile', 'personal' => 'Personal details', 'address' => 'Address & income', 'kin' => 'Next of kin',
        'documents' => 'Documents', 'consents' => 'Consents', 'kyc' => 'Verification'];

    public string $step = 'personal';

    public string $otp = '';

    public ?string $demoOtp = null;

    public array $personal = ['full_name' => '', 'cnic' => '', 'cnic_issue_date' => '', 'cnic_expiry_date' => '', 'date_of_birth' => '', 'gender' => '', 'marital_status' => '', 'father_husband_name' => '', 'email' => ''];

    public array $address = ['current_address' => '', 'permanent_address' => '', 'city' => '', 'employment_type' => '', 'occupation' => '', 'employer_name' => '',
        'business_name' => '', 'business_type' => '', 'income_source' => '', 'monthly_income' => '', 'other_income' => '0', 'existing_obligations' => '0'];

    public array $kin = ['name' => '', 'relation' => '', 'cnic' => '', 'mobile' => '', 'address' => ''];

    public $cnicFront;

    public $cnicBack;

    public $selfie;

    public array $consents = [];

    public string $scenario = 'auto';

    public function mount(): void
    {
        $c = $this->customer();
        foreach ($this->personal as $k => $v) {
            $val = $c->{$k};
            $this->personal[$k] = $val instanceof \DateTimeInterface ? $val->format('Y-m-d') : (string) ($val ?? '');
        }
        foreach ($this->address as $k => $v) {
            $this->address[$k] = (string) ($c->{$k} ?? $v);
        }
        if ($k = $c->nextOfKins()->first()) {
            $this->kin = $k->only(array_keys($this->kin));
            $this->kin = array_map(fn ($v) => (string) $v, $this->kin);
        }
        foreach (ConsentType::cases() as $t) {
            $this->consents[$t->value] = Consent::where('customer_id', $c->id)->where('consent_type', $t->value)->where('granted', true)->whereNull('withdrawn_at')->exists();
        }
        $this->step = $this->firstIncompleteStep();
    }

    public function firstIncompleteStep(): string
    {
        $c = $this->customer();
        if (! $this->user()->mobile_verified_at) {
            return 'otp';
        }
        if (in_array($c->kyc_status, [KycStatus::Passed, KycStatus::Failed, KycStatus::PendingReview, KycStatus::InProgress], true)) {
            return 'kyc';
        }
        if (! $c->cnic || ! $c->date_of_birth) {
            return 'personal';
        }
        if (! $c->current_address || ! $c->employment_type) {
            return 'address';
        }
        if (! $c->nextOfKins()->exists()) {
            return 'kin';
        }
        if (! $this->hasDoc('CNIC_FRONT')) {
            return 'documents';
        }
        if (! $this->consents[ConsentType::NadraVerification->value] || ! $this->consents[ConsentType::CreditBureau->value]) {
            return 'consents';
        }

        return 'kyc';
    }

    private function hasDoc(string $code): bool
    {
        return $this->customer()->documents()->whereHas('type', fn ($q) => $q->where('code', $code))->where('status', '!=', 'superseded')->exists();
    }

    public function goTo(string $step): void
    {
        abort_unless(array_key_exists($step, self::STEPS), 404);
        if ($this->customer()->kyc_status === KycStatus::Passed && ! in_array($step, ['kin', 'kyc'], true)) {
            $this->toast(__('Your identity is verified. Use Profile to request changes.'), 'blue');

            return;
        }
        $this->step = $step;
    }

    public function sendOtp(OtpService $otp): void
    {
        $this->attempt(fn () => $this->demoOtp = $otp->issue($this->user()->mobile, 'registration'), __('Code sent.'));
    }

    public function verifyOtp(OtpService $otp, OnboardingService $onb): void
    {
        $this->validate(['otp' => 'required|digits:6']);
        if ($this->attempt(function () use ($otp, $onb) {
            $otp->verify($this->user()->mobile, 'registration', $this->otp);
            $onb->markMobileVerified($this->user());
        }, __('Mobile number verified.'))) {
            $this->step = 'personal';
        }
    }

    public function savePersonal(OnboardingService $onb): void
    {
        $this->validate([
            'personal.full_name' => 'required|string|min:3|max:100',
            'personal.cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'personal.cnic_issue_date' => 'nullable|date|before_or_equal:today',
            'personal.cnic_expiry_date' => 'nullable|date|after:today',
            'personal.date_of_birth' => 'required|date|before:-18 years|after:-75 years',
            'personal.gender' => 'required|in:male,female,other',
            'personal.marital_status' => 'nullable|in:single,married,widowed,divorced',
            'personal.father_husband_name' => 'required|string|max:100',
            'personal.email' => 'nullable|email|max:150',
        ], [
            'personal.cnic.regex' => __('CNIC must be in the format 12345-1234567-1.'),
            'personal.date_of_birth.before' => __('You must be at least 18 years old.'),
            'personal.cnic_expiry_date.after' => __('Your CNIC has expired. Please renew it with NADRA first.'),
        ]);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->personal);
        if ($this->attempt(fn () => $onb->updateProfile($this->customer(), $data, $this->user(), 'address'), __('Personal details saved.'))) {
            $this->step = 'address';
        }
    }

    public function sameAddress(): void
    {
        $this->address['permanent_address'] = $this->address['current_address'];
    }

    public function saveAddress(OnboardingService $onb): void
    {
        $this->validate([
            'address.current_address' => 'required|string|max:255',
            'address.permanent_address' => 'required|string|max:255',
            'address.city' => 'required|string|max:80',
            'address.employment_type' => 'required|in:salaried,self_employed,business,unemployed',
            'address.occupation' => 'nullable|string|max:100',
            'address.employer_name' => 'required_if:address.employment_type,salaried|nullable|string|max:150',
            'address.business_name' => 'required_if:address.employment_type,business|nullable|string|max:150',
            'address.business_type' => 'nullable|string|max:100',
            'address.income_source' => 'nullable|string|max:100',
            'address.monthly_income' => 'required|numeric|min:0|max:100000000',
            'address.other_income' => 'nullable|numeric|min:0|max:100000000',
            'address.existing_obligations' => 'nullable|numeric|min:0|max:100000000',
        ]);
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->address);
        $data['other_income'] ??= 0;
        $data['existing_obligations'] ??= 0;
        if ($this->attempt(fn () => $onb->updateProfile($this->customer(), $data, $this->user(), 'kin'), __('Address & income saved.'))) {
            $this->step = 'kin';
        }
    }

    public function saveKin(): void
    {
        $this->validate([
            'kin.name' => 'required|string|max:100',
            'kin.relation' => 'required|string|max:30',
            'kin.cnic' => ['nullable', 'regex:/^\d{5}-\d{7}-\d$/'],
            'kin.mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'kin.address' => 'nullable|string|max:255',
        ], ['kin.mobile.regex' => __('Enter a valid Pakistani mobile number.'), 'kin.cnic.regex' => __('CNIC must be in the format 12345-1234567-1.')]);
        $c = $this->customer();
        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->kin);
        $existing = $c->nextOfKins()->first();
        $existing ? $existing->update($data) : $c->nextOfKins()->create($data);
        \App\Services\Audit\Auditor::record('customer.next_of_kin_saved', $c);
        $this->toast(__('Next of kin saved.'));
        $this->step = $c->kyc_status === KycStatus::Passed ? 'kyc' : 'documents';
    }

    public function uploadDocuments(DocumentStore $store): void
    {
        $this->validate([
            'cnicFront' => ($this->hasDoc('CNIC_FRONT') ? 'nullable' : 'required').'|image|max:5120',
            'cnicBack' => ($this->hasDoc('CNIC_BACK') ? 'nullable' : 'required').'|image|max:5120',
            'selfie' => 'nullable|image|max:5120',
        ]);
        $ok = $this->attempt(function () use ($store) {
            $c = $this->customer();
            foreach (['cnicFront' => 'CNIC_FRONT', 'cnicBack' => 'CNIC_BACK', 'selfie' => 'SELFIE'] as $prop => $code) {
                if ($this->{$prop}) {
                    $store->store($this->{$prop}, $c, DocumentType::where('code', $code)->first(), $this->user(), null, $code === 'CNIC_FRONT' ? ($c->cnic_expiry_date?->toDateString()) : null);
                }
            }
        }, __('Documents uploaded securely.'));
        if ($ok) {
            $this->reset('cnicFront', 'cnicBack', 'selfie');
            $this->step = 'consents';
        }
    }

    public function saveConsents(OnboardingService $onb): void
    {
        if (empty($this->consents[ConsentType::NadraVerification->value]) || empty($this->consents[ConsentType::CreditBureau->value])) {
            $this->addError('consents', __('NADRA verification and credit bureau consents are required to continue.'));

            return;
        }
        $c = $this->customer();
        $items = [];
        foreach ($this->consents as $type => $granted) {
            $current = Consent::where('customer_id', $c->id)->where('consent_type', $type)->whereNull('withdrawn_at')->latest('id')->first();
            if (! $current || (bool) $current->granted !== (bool) $granted) {
                $items[$type] = (bool) $granted;
            }
        }
        if ($items) {
            $onb->recordConsents($c, $items, app()->getLocale(), request()->ip(), request()->userAgent());
        }
        $this->toast(__('Consents recorded.'));
        $this->step = 'kyc';
    }

    public function runKyc(OnboardingService $onb): void
    {
        $scenario = $this->demo() && array_key_exists($this->scenario, config('integrations.kyc_scenarios')) ? $this->scenario : 'auto';
        $this->attempt(function () use ($onb, $scenario) {
            $status = $onb->runEkyc($this->customer(), $scenario, $this->user());
            $this->toast(match ($status) {
                KycStatus::Passed => __('Identity verified. Your account is active.'),
                KycStatus::PendingReview => __('Your verification needs a quick manual review by our team.'),
                default => __('We could not verify your identity.'),
            }, $status === KycStatus::Passed ? 'green' : ($status === KycStatus::Failed ? 'red' : 'blue'));
        });
    }

    public function render()
    {
        $c = $this->customer();
        $latest = KycVerification::where('customer_id', $c->id)->latest('id')->get()->unique(fn ($v) => $v->step->value);

        return view('livewire.customer.onboarding', [
            'customer' => $c,
            'steps' => self::STEPS,
            'verifications' => $latest->sortBy(fn ($v) => array_search($v->step->value, ['mobile_otp', 'cnic_ocr', 'nadra_verisys', 'liveness', 'aml_screening'])),
            'docs' => $c->documents()->with('type')->where('status', '!=', 'superseded')->latest()->get(),
            'consentTypes' => ConsentType::cases(),
            'scenarios' => config('integrations.kyc_scenarios'),
            'wallet' => $c->wallet()->first(),
        ])->title(__('Verify your identity'));
    }
}
