<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Restricts a route group to one user type (staff|customer|vendor|platform) and active accounts. */
class EnsureUserType
{
    public function handle(Request $request, Closure $next, string ...$types)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->type->value, $types, true)) {
            abort(403, 'This area is not available for your account type.');
        }
        if ($user->status !== UserStatus::Active) {
            Auth::logout();

            return redirect('/')->with('error', 'Your account is not active.');
        }
        if ($user->must_change_password && ! $request->routeIs('password.change', 'password.change.*', 'logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
