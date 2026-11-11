<?php

namespace App\Livewire\Auth;

use App\Exceptions\DomainException;
use App\Services\Identity\AuthService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class PlatformLogin extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(AuthService $auth)
    {
        $this->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'platform-login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('Too many attempts. Try again later.'));

            return;
        }
        RateLimiter::hit($key, 60);
        try {
            $auth->attempt(null, $this->email, $this->password, ['platform']);
        } catch (DomainException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }

        return $this->redirect(route('platform.dashboard'));
    }

    public function render()
    {
        return view('livewire.auth.platform-login');
    }
}
