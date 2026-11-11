<?php

namespace App\Livewire\Customer\Auth;

use App\Exceptions\DomainException;
use App\Models\Tenant;
use App\Services\Identity\AuthService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    use BindsTenant;

    public string $login = '';

    public string $password = '';

    public function mount(Tenant $tenant)
    {
        if (auth()->check()) {
            return $this->redirect(route('home'));
        }
        $this->bindTenant($tenant);
    }

    public function submit(AuthService $auth)
    {
        $this->validate(['login' => 'required|string|max:100', 'password' => 'required|string']);
        $key = 'cust-login:'.request()->ip().'|'.$this->login;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('login', __('Too many attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)]));

            return;
        }
        RateLimiter::hit($key, 60);
        try {
            $auth->attempt($this->tenant, $this->login, $this->password, ['customer']);
        } catch (DomainException $e) {
            $this->addError('login', $e->getMessage());

            return;
        }

        return $this->redirect(route('app.home'));
    }

    public function render()
    {
        return view('livewire.customer.auth.login');
    }
}
