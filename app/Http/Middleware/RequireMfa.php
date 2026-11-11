<?php

namespace App\Http\Middleware;

use App\Services\Identity\Mfa;
use Closure;
use Illuminate\Http\Request;

/**
 * Enforces TOTP MFA for platform users and privileged staff (S1) on every web request, including
 * Livewire updates: until the challenge is passed in this session, only the MFA pages, logout and
 * the password-change page are reachable.
 */
class RequireMfa
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! Mfa::requiredFor($user)) {
            return $next($request);
        }
        if ($request->routeIs('mfa.*', 'logout', 'password.change')) {
            return $next($request);
        }
        $target = ! Mfa::enrolled($user) ? 'mfa.setup' : (! Mfa::passedThisSession() ? 'mfa.challenge' : null);
        if ($target === null) {
            return $next($request);
        }
        if ($request->is('livewire/*') || $request->expectsJson()) {
            abort(403, 'Two-step verification required.');
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route($target);
    }
}
