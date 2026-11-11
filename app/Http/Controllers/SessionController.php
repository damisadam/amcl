<?php

namespace App\Http\Controllers;

use App\Services\Audit\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function logout(Request $request)
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        if ($user) {
            Auditor::record('auth.logout', $user, tenantId: $user->tenant_id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return match (true) {
            $user?->isCustomer() && $tenant => redirect()->route('customer.login', $tenant),
            $user?->isPlatform() => redirect()->route('platform.login'),
            $user !== null => redirect()->route('staff.login', ['org' => $tenant?->code]),
            default => redirect('/'),
        };
    }

    public function locale(Request $request, string $locale)
    {
        abort_unless(in_array($locale, ['en', 'ur'], true), 404);
        $request->session()->put('locale', $locale);
        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
            $user->customer?->forceFill(['preferred_locale' => $locale])->save();
        }

        return back();
    }
}
