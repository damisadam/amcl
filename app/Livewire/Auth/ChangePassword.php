<?php

namespace App\Livewire\Auth;

use App\Exceptions\DomainException;
use App\Services\Identity\AuthService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class ChangePassword extends Component
{
    public string $current = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(AuthService $auth)
    {
        $user = auth()->user();
        $this->validate(['current' => 'required', 'password' => AuthService::passwordRules($user->tenant_id)]);
        try {
            $auth->changePassword($user, $this->current, $this->password);
        } catch (DomainException $e) {
            $this->addError('current', $e->getMessage());

            return;
        }
        session()->flash('success', __('Password changed.'));

        return $this->redirect(match (true) {
            $user->isPlatform() => route('platform.dashboard'), $user->isCustomer() => route('app.home'),
            $user->isVendor() => route('vendor.dashboard'), default => route('ops.dashboard'),
        });
    }

    public function render()
    {
        return view('livewire.auth.change-password', ['forced' => auth()->user()->must_change_password]);
    }
}
