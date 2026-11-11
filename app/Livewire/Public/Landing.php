<?php

namespace App\Livewire\Public;

use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Institution selector (DFS §1.1): the customer chooses the tenant whose products and branding they use. */
#[Layout('layouts.guest')]
class Landing extends Component
{
    public function mount()
    {
        if ($user = auth()->user()) {
            return $this->redirect(match (true) {
                $user->isPlatform() => route('platform.dashboard'), $user->isCustomer() => route('app.home'),
                $user->isVendor() => route('vendor.dashboard'), default => route('ops.dashboard'),
            });
        }
    }

    public function render()
    {
        return view('livewire.public.landing', ['tenants' => Tenant::where('status', 'active')->orderBy('id')->get()]);
    }
}
