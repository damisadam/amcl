<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Support\Correlation;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Establishes tenant context for every web request (including Livewire updates) from a trusted source:
 *   1. the authenticated user's tenant (staff / customer / vendor), or
 *   2. the validated tenant entry point stored in the session by /t/{code} (guest pages).
 * Browser-supplied tenant ids are never trusted. Suspended/inactive tenants are locked out.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        TenantContext::clear();
        Correlation::set($request->header('X-Correlation-Id') && preg_match('/^[A-Za-z0-9-]{8,64}$/', $request->header('X-Correlation-Id'))
            ? $request->header('X-Correlation-Id') : null);

        $user = Auth::user();
        $tenant = null;
        if ($user && $user->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);
        } elseif (! $user && $request->session()->has('entry_tenant_id')) {
            $tenant = Tenant::where('status', TenantStatus::Active->value)->find($request->session()->get('entry_tenant_id'));
        }

        if ($user && $user->tenant_id && (! $tenant || $tenant->status !== TenantStatus::Active)) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/')->with('error', 'This institution is currently unavailable. Please contact support.');
        }

        if ($tenant) {
            TenantContext::set($tenant);
            app()->setLocale($user?->locale ?? $request->session()->get('locale', $tenant->default_locale));
            view()->share('currentTenant', $tenant);
        } else {
            app()->setLocale($request->session()->get('locale', 'en'));
        }

        return $next($request);
    }
}
