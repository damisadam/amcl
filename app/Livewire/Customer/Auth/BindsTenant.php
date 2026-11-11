<?php

namespace App\Livewire\Customer\Auth;

use App\Models\Tenant;
use App\Support\TenantContext;

/** Binds the guest flow to the validated tenant entry point. */
trait BindsTenant
{
    public Tenant $tenant;

    protected function bindTenant(Tenant $tenant): void
    {
        abort_unless($tenant->isActive(), 404);
        $this->tenant = $tenant;
        session(['entry_tenant_id' => $tenant->id]);
        TenantContext::set($tenant);
        view()->share('currentTenant', $tenant);
    }

    public function hydrate(): void
    {
        TenantContext::set($this->tenant);
        view()->share('currentTenant', $this->tenant);
    }
}
