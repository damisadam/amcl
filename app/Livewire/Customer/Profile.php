<?php

namespace App\Livewire\Customer;

use App\Enums\ConsentType;
use App\Enums\KycStatus;
use App\Livewire\Customer\Concerns\CustomerContext;
use App\Models\Consent;
use App\Models\UserDevice;
use App\Services\Audit\Auditor;
use App\Services\Kyc\OnboardingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Profile & settings: contact details, identity change requests, language, consents, devices. */
#[Layout('layouts.customer')]
class Profile extends Component
{
    use CustomerContext;

    public const MANDATORY = ['terms_of_use', 'privacy_policy', 'nadra_verification', 'credit_bureau'];

    /** Contact (login id) change: 'email' | 'mobile', the new value, the OTP sent to it. */
    public string $contactField = 'email';

    public string $newContact = '';

    public string $contactCode = '';

    public bool $contactPending = false;

    public ?string $demoOtp = null;

    public string $current_address = '';

    public string $city = '';

    public string $changeField = 'cnic';

    public string $changeValue = '';

    public string $changeReason = '';

    public function mount(): void
    {
        $c = $this->customer();
        $this->current_address = (string) $c->current_address;
        $this->city = (string) $c->city;
    }

    public function saveContact(OnboardingService $onb): void
    {
        $this->validate(['current_address' => 'required|string|max:255', 'city' => 'required|string|max:80'], [], [
            'current_address' => __('current address'), 'city' => __('city'),
        ]);
        $this->attempt(fn () => $onb->updateProfile($this->customer(), ['current_address' => $this->current_address, 'city' => $this->city], $this->user()), __('Profile updated.'));
    }

    /**
     * Email and mobile are login ids (QA C3): the new value must be unused in this institution and is
     * confirmed with a one-time code sent to the NEW contact; the old contact is notified on change.
     */
    public function startContactChange(\App\Services\Kyc\ContactChangeService $svc): void
    {
        $this->validate([
            'contactField' => 'required|in:email,mobile',
            'newContact' => $this->contactField === 'email' ? 'required|email|max:150' : ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
        ], ['newContact.regex' => __('Enter a valid Pakistani mobile number, e.g. 03001234567.')],
            ['newContact' => $this->contactField === 'email' ? __('new email') : __('new mobile number')]);
        $key = 'contact-change:'.$this->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('newContact', __('Too many requests. Try again in :s seconds.', ['s' => \Illuminate\Support\Facades\RateLimiter::availableIn($key)]));

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 600);
        try {
            $this->demoOtp = $svc->start($this->user(), $this->contactField, $this->newContact);
            $this->contactPending = true;
            $this->contactCode = '';
        } catch (\App\Exceptions\DomainException $e) {
            $this->addError('newContact', $e->getMessage());
        }
    }

    public function confirmContactChange(\App\Services\Kyc\ContactChangeService $svc): void
    {
        $this->validate(['contactCode' => 'required|digits:6'], [], ['contactCode' => __('verification code')]);
        try {
            $svc->confirm($this->user(), $this->contactField, $this->newContact, $this->contactCode);
        } catch (\App\Exceptions\DomainException $e) {
            $this->addError('contactCode', $e->getMessage());

            return;
        }
        $this->reset('newContact', 'contactCode', 'contactPending', 'demoOtp');
        $this->toast(__('Contact details updated and verified.'));
    }

    public function cancelContactChange(): void
    {
        $this->reset('newContact', 'contactCode', 'contactPending', 'demoOtp');
    }

    public function requestChange(OnboardingService $onb): void
    {
        $this->validate([
            'changeField' => 'required|in:cnic,full_name,date_of_birth,father_husband_name',
            'changeValue' => $this->changeField === 'cnic' ? ['required', 'regex:/^\d{5}-\d{7}-\d$/'] : ($this->changeField === 'date_of_birth' ? 'required|date|before:-18 years' : 'required|string|max:100'),
            'changeReason' => 'required|string|min:10|max:500',
        ], ['changeValue.regex' => __('CNIC must be in the format 12345-1234567-1.')]);
        if ($this->attempt(fn () => $onb->requestChange($this->customer(), $this->changeField, $this->changeValue, $this->changeReason, $this->user()),
            __('Change request submitted for verification.'))) {
            $this->reset('changeValue', 'changeReason');
        }
    }

    public function withdrawConsent(string $type): void
    {
        abort_if(in_array($type, self::MANDATORY, true), 403);
        $type = ConsentType::from($type);
        $c = $this->customer();
        Consent::where('customer_id', $c->id)->where('consent_type', $type->value)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
        Auditor::record('customer.consent_withdrawn', $c, [], ['type' => $type->value]);
        $this->toast(__('Consent withdrawn.'));
    }

    public function grantConsent(string $type, OnboardingService $onb): void
    {
        $type = ConsentType::from($type);
        $onb->recordConsents($this->customer(), [$type->value => true], app()->getLocale(), request()->ip(), request()->userAgent());
        $this->toast(__('Consent recorded.'));
    }

    public function revokeDevice(int $id): void
    {
        $d = UserDevice::where('user_id', $this->user()->id)->findOrFail($id);
        $d->update(['revoked_at' => now()]);
        Auditor::record('auth.device_revoked', $this->user(), [], ['device' => $d->label]);
    }

    public function render()
    {
        $c = $this->customer();
        $consents = Consent::where('customer_id', $c->id)->orderByDesc('recorded_at')->get();

        return view('livewire.customer.profile', [
            'c' => $c, 'locked' => $c->kyc_status === KycStatus::Passed,
            'consents' => $consents,
            'active' => collect(ConsentType::cases())->mapWithKeys(fn ($t) => [$t->value => $consents->first(fn ($x) => $x->consent_type === $t && $x->granted && ! $x->withdrawn_at)]),
            'requests' => $c->changeRequests()->latest()->get(),
            'devices' => UserDevice::where('user_id', $this->user()->id)->latest('last_seen_at')->get(),
            'kins' => $c->nextOfKins,
        ])->title(__('Profile & Settings'));
    }
}
