<?php

namespace App\Livewire\Auth;

use App\Exceptions\DomainException;
use App\Models\Tenant;
use App\Services\Identity\AuthService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Staff and vendor sign-in: organisation code + email + password. */
#[Layout('layouts.guest')]
class StaffLogin extends Component
{
    #[Url]
    public string $org = '';

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(AuthService $auth)
    {
        $this->validate(['org' => 'required|string|max:40', 'email' => 'required|email', 'password' => 'required|string']);
        $key = 'staff-login:'.request()->ip().'|'.strtolower($this->email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('Too many attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)]));

            return;
        }
        RateLimiter::hit($key, 60);
        $tenant = Tenant::where('code', strtolower(trim($this->org)))->first();
        if (! $tenant) {
            // Same message as a wrong password: the login page must not reveal which institutions exist (S2).
            $this->addError('email', __('These credentials do not match our records.'));

            return;
        }
        try {
            $user = $auth->attempt($tenant, $this->email, $this->password, ['staff', 'vendor'], $this->remember);
        } catch (DomainException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }
        RateLimiter::clear($key);

        return $this->redirect($user->isVendor() ? route('vendor.dashboard') : route('ops.dashboard'));
    }

    public function render()
    {
        return view('livewire.auth.staff-login');
    }
}
