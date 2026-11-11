<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>@include('layouts._head')</head>
<body class="ornament min-h-screen" x-data="{ nav: false }">
<x-demo-banner/>
<x-flash/>
@php($user = auth()->user())
<header class="bg-shell-bar sticky top-0 z-30 text-white shadow-sm">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
        <button class="md:hidden" x-on:click="nav=!nav"><x-icon name="menu"/></button>
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold ring-2 ring-white/15">V</span>
        <div class="min-w-0"><p class="truncate text-[15px] font-bold">{{ $user->vendor?->business_name }}</p><p class="text-xs text-white/65">{{ __('Vendor Portal') }} · {{ $currentTenant->name ?? '' }}</p></div>
        <div class="ml-auto flex items-center gap-2 sm:gap-3"><x-locale-pair class="hidden sm:flex"/><livewire:shared.notification-bell dark/>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-md p-2 hover:bg-white/10"><x-icon name="logout"/></button></form></div>
    </div>
    <nav class="border-t border-white/10 bg-black/10 md:block" :class="nav ? 'block' : 'hidden'">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 overflow-x-auto px-4 py-2 text-sm md:flex-row">
            @foreach(\App\Support\Navigation::vendor() as [$label, $route, $icon])
                <a href="{{ route($route) }}" wire:navigate @class(['flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5', 'bg-brand-600 font-medium text-white' => request()->routeIs($route.'*'), 'text-white/80 hover:bg-white/10 hover:text-white' => ! request()->routeIs($route.'*')])><x-icon :name="$icon" class="h-4 w-4"/>{{ __($label) }}</a>
            @endforeach
        </div>
    </nav>
</header>
<main class="mx-auto max-w-6xl p-4 sm:p-6 lg:py-8">{{ $slot }}</main>
@livewireScripts
@include('layouts._realtime')
</body>
</html>
