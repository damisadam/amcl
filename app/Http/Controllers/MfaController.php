<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Services\Identity\Mfa;
use App\Services\Identity\Totp;
use Illuminate\Http\Request;

/**
 * TOTP enrolment, sign-in challenge and step-up confirmation (S1). Plain controllers (not Livewire)
 * so the MFA middleware can block every Livewire request until the challenge is passed.
 */
class MfaController extends Controller
{
    public function setup(Request $request)
    {
        $user = $request->user();
        if (Mfa::enrolled($user)) {
            return redirect()->route('mfa.challenge');
        }
        $secret = Mfa::secret($user) ?? Mfa::beginEnrolment($user);
        $account = $user->email ?? $user->mobile ?? ('user-'.$user->id);

        return view('auth.mfa', [
            'mode' => 'setup',
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => Totp::uri($secret, $account, config('security.mfa.issuer')),
        ]);
    }

    public function confirmSetup(Request $request)
    {
        $request->validate(['code' => 'required|string|max:20']);
        try {
            $codes = Mfa::confirmEnrolment($request->user(), $request->input('code'));
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return view('auth.mfa', ['mode' => 'recovery', 'codes' => $codes, 'continue' => session()->pull('url.intended', $this->home($request))]);
    }

    public function challenge(Request $request)
    {
        $user = $request->user();
        if (! Mfa::enrolled($user)) {
            return redirect()->route('mfa.setup');
        }
        if ($request->filled('return') && str_starts_with((string) $request->input('return'), '/')) {
            session(['url.intended' => url($request->input('return'))]);
        }

        return view('auth.mfa', [
            'mode' => 'challenge',
            'stepup' => $request->boolean('stepup') || Mfa::passedThisSession(),
            // Demo convenience: seeded demo users share a known secret, so the current code is shown in the SIMULATED banner.
            'demoCode' => Mfa::isDemoSecret($user) ? Totp::code(config('security.mfa.demo_secret')) : null,
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string|max:20']);
        $key = 'mfa:'.$request->user()->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Too many attempts. Try again in '.\Illuminate\Support\Facades\RateLimiter::availableIn($key).' seconds.']);
        }
        try {
            Mfa::verify($request->user(), $request->input('code'));
        } catch (DomainException $e) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);

            return back()->withErrors(['code' => $e->getMessage()]);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);

        return redirect()->intended($this->home($request));
    }

    private function home(Request $request): string
    {
        return $request->user()->isPlatform() ? route('platform.dashboard') : route('ops.dashboard');
    }
}
