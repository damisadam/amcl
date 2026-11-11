<?php

namespace App\Livewire\Customer\Auth;

use App\Exceptions\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Identity\AuthService;
use App\Services\Identity\OtpService;
use App\Services\Kyc\OnboardingService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Customer password reset by OTP to the registered mobile number. */
#[Layout('layouts.guest')]
class ForgotPassword extends Component
{
    use BindsTenant;

    public int $step = 1;

    public string $mobile = '';

    public string $otp = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $demoOtp = null;

    public function mount(Tenant $tenant)
    {
        $this->bindTenant($tenant);
    }

    public function send(OtpService $otp): void
    {
        $this->validate(['mobile' => 'required|string']);
        $mobile = OnboardingService::normaliseMobile($this->mobile);
        $user = User::where('tenant_id', $this->tenant->id)->where('type', 'customer')->where('mobile', $mobile)->first();
        try {
            // Same response whether or not the number exists, to avoid account enumeration.
            $this->demoOtp = $user ? $otp->issue($mobile, 'password_reset') : null;
        } catch (DomainException $e) {
            $this->addError('mobile', $e->getMessage());

            return;
        }
        $this->step = 2;
    }

    public function resetPassword(OtpService $otp)
    {
        $this->validate(['otp' => 'required|digits:6', 'password' => AuthService::passwordRules($this->tenant->id)]);
        $mobile = OnboardingService::normaliseMobile($this->mobile);
        $user = User::where('tenant_id', $this->tenant->id)->where('type', 'customer')->where('mobile', $mobile)->first();
        try {
            if (! $user) {
                throw new DomainException(__('Incorrect code.'));
            }
            $otp->verify($mobile, 'password_reset', $this->otp);
        } catch (DomainException $e) {
            $this->addError('otp', $e->getMessage());

            return;
        }
        $user->forceFill(['password' => $this->password, 'password_changed_at' => now(), 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        Auditor::record('auth.password_reset', $user, tenantId: $this->tenant->id);
        session()->flash('success', __('Password updated. Please sign in.'));

        return $this->redirect(route('customer.login', $this->tenant));
    }

    public function render()
    {
        return view('livewire.customer.auth.forgot-password');
    }
}
