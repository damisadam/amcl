<?php

namespace App\Livewire\Customer\Auth;

use App\Exceptions\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\AuthService;
use App\Services\Identity\OtpService;
use App\Services\Kyc\OnboardingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Step 1–3 of DFS onboarding: create account, verify mobile by (simulated) OTP, sign in. */
#[Layout('layouts.guest')]
class Register extends Component
{
    use BindsTenant;

    public int $step = 1;

    public string $name = '';

    public string $mobile = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $accept = false;

    public string $otp = '';

    public ?string $demoOtp = null;

    public ?int $userId = null;

    public function mount(Tenant $tenant)
    {
        if (auth()->check()) {
            return $this->redirect(route('home'));
        }
        $this->bindTenant($tenant);
    }

    public function create(OnboardingService $onboarding, OtpService $otp)
    {
        $this->validate([
            'name' => 'required|string|min:3|max:100',
            'mobile' => ['required', 'regex:/^(\+92|92|0)3\d{9}$/'],
            'email' => 'nullable|email|max:150',
            'password' => AuthService::passwordRules($this->tenant->id),
            'accept' => 'accepted',
        ], ['mobile.regex' => __('Enter a valid Pakistani mobile number, e.g. 03001234567.')]);

        try {
            $user = $onboarding->register($this->tenant, ['name' => $this->name, 'mobile' => $this->mobile, 'email' => $this->email ?: null,
                'password' => $this->password, 'locale' => app()->getLocale()]);
            $onboarding->recordConsents($user->customer, ['terms_of_use' => true, 'privacy_policy' => true], app()->getLocale(), request()->ip(), request()->userAgent());
            $this->userId = $user->id;
            $this->demoOtp = $otp->issue($user->mobile, 'registration');
            $this->step = 2;
        } catch (DomainException $e) {
            $this->addError('mobile', $e->getMessage());
        }
    }

    public function resend(OtpService $otp): void
    {
        try {
            $this->demoOtp = $otp->issue(User::findOrFail($this->userId)->mobile, 'registration');
        } catch (DomainException $e) {
            $this->addError('otp', $e->getMessage());
        }
    }

    public function verify(OtpService $otp, OnboardingService $onboarding)
    {
        $this->validate(['otp' => 'required|digits:6']);
        $user = User::findOrFail($this->userId);
        try {
            $otp->verify($user->mobile, 'registration', $this->otp);
        } catch (DomainException $e) {
            $this->addError('otp', $e->getMessage());

            return;
        }
        $onboarding->markMobileVerified($user);
        Auth::login($user);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }
        session()->flash('success', __('Mobile verified. Let\'s complete your profile.'));

        return $this->redirect(route('app.onboarding'));
    }

    public function render()
    {
        return view('livewire.customer.auth.register');
    }
}
