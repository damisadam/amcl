<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>@include('layouts._head', ['title' => 'Security check'])</head>
<body class="ornament min-h-screen">
<x-demo-banner/>
<div class="flex min-h-[90vh] items-center justify-center p-4">
    <div class="card w-full max-w-md p-6">
        <div class="mb-4 flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-700 text-white"><x-icon name="shield"/></span>
            <div><h1 class="text-lg font-bold text-ink">
                @if($mode === 'setup') Set up two-step verification @elseif($mode === 'recovery') Save your recovery codes @else {{ ($stepup ?? false) ? 'Confirm it\'s you' : 'Two-step verification' }} @endif
            </h1><p class="text-xs text-neutral-500">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p></div>
        </div>

        @if($mode === 'setup')
            <p class="text-sm text-neutral-700">Your role can approve, post or release money, so an authenticator app is required. Add this account in Google Authenticator, Microsoft Authenticator or Authy:</p>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-neutral-700">
                <li>Choose <strong>Enter a setup key</strong> (time-based).</li>
                <li>Key: <code class="select-all rounded bg-neutral-100 px-2 py-1 font-mono text-sm tracking-wider text-ink">{{ $secret }}</code></li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <details class="mt-3 text-xs text-neutral-500"><summary class="cursor-pointer">Setup link (otpauth URI)</summary><code class="mt-1 block break-all select-all">{{ $uri }}</code></details>
            <form method="POST" action="{{ route('mfa.setup.confirm') }}" class="mt-4 space-y-3">@csrf
                <x-input label="6-digit code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus/>
                <button class="btn-primary w-full">Verify and turn on</button>
            </form>
        @elseif($mode === 'recovery')
            <x-alert tone="amber" title="Shown only once">Store these one-time recovery codes somewhere safe. Each code can be used once if you lose your phone.</x-alert>
            <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">@foreach($codes as $c)<span class="rounded bg-neutral-100 px-2 py-1 text-center select-all">{{ $c }}</span>@endforeach</div>
            <a href="{{ $continue }}" class="btn-primary mt-4 w-full">I have saved them – continue</a>
        @else
            @if($demoCode)
                <x-alert class="mb-3" tone="amber" title="Simulated authenticator (demo)">Demo accounts share a known setup key. Current code: <strong class="tabular text-lg tracking-widest">{{ $demoCode }}</strong></x-alert>
            @endif
            <p class="text-sm text-neutral-700">{{ ($stepup ?? false) ? 'This action needs a fresh confirmation. ' : '' }}Enter the 6-digit code from your authenticator app, or a recovery code.</p>
            <form method="POST" action="{{ route('mfa.verify') }}" class="mt-4 space-y-3">@csrf
                <x-input label="Authentication code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="11" required autofocus/>
                <button class="btn-primary w-full">Verify</button>
            </form>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">@csrf<button class="text-xs text-neutral-500 hover:underline">Sign out</button></form>
    </div>
</div>
</body>
</html>
