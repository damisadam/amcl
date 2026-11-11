<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · @yield('title') · {{ ($currentTenant ?? null)?->name ?? 'AMCL Platform' }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="ornament min-h-screen">
    <main class="flex min-h-screen items-center justify-center p-4">
        <div class="card w-full max-w-md p-8 text-center">
            <x-tenant-logo :tenant="$currentTenant ?? null" size="mx-auto h-12 w-12"/>
            <p class="mt-5 text-sm font-semibold uppercase tracking-wide text-brand-700"><bdi>@yield('code')</bdi></p>
            <h1 class="mt-1 text-2xl font-bold text-ink">@yield('title')</h1>
            <p class="mt-2 text-sm text-neutral-600">@yield('message')</p>
            <div class="mt-6 flex flex-wrap justify-center gap-2">
                @php($u = auth()->user())
                @php($home = match (true) { ! $u => url('/'), $u->isCustomer() => route('app.home'), $u->isPlatform() => route('platform.dashboard'), $u->isVendor() => route('vendor.dashboard'), default => route('ops.dashboard') })
                <a href="{{ $home }}" class="btn-primary">{{ __('Go to home') }}</a>
                <button type="button" onclick="history.back()" class="btn-secondary">{{ __('Go back') }}</button>
            </div>
            @if(($currentTenant ?? null)?->support_phone)<p class="mt-6 text-xs text-neutral-500">{{ __('Need help? Call :p', ['p' => $currentTenant->support_phone]) }}</p>@endif
        </div>
    </main>
</body>
</html>
