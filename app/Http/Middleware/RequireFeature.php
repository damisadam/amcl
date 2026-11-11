<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;

/** Blocks a module the tenant is not entitled to (platform feature entitlements). */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        if (! TenantContext::get()?->hasFeature($feature)) {
            abort(403, 'This module is not enabled for your institution.');
        }

        return $next($request);
    }
}
