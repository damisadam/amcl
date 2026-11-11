<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>@include('layouts._head')</head>
<body class="ornament min-h-screen" x-data="{ nav: false }">
<x-demo-banner/>
<x-flash/>
@php($user = auth()->user())
<div class="flex min-h-screen">
    <aside class="bg-shell fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full transform flex-col text-white transition lg:sticky lg:top-0 lg:h-screen lg:translate-x-0" :class="nav ? 'translate-x-0' : ''">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-600 ring-2 ring-white/15"><x-tenant-logo :tenant="$currentTenant ?? null" size="h-10 w-10"/></span>
            <div class="min-w-0"><p class="truncate text-[15px] font-bold leading-tight">{{ $currentTenant->name ?? '' }}</p><p class="text-xs text-white/65">{{ __('Operations Portal') }}</p></div>
        </div>
        <nav class="relative z-10 flex-1 overflow-y-auto px-3 pb-6 pt-2 [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,.2)_transparent]">
            @foreach(\App\Support\Navigation::ops($user) as $section => $items)
                <p class="nav-section">{{ __($section) }}</p>
                @foreach($items as [$label, $route, $perm, $icon])
                    @php($active = request()->routeIs($route) || request()->routeIs($route.'.*') || (str_ends_with($route, '.index') && request()->routeIs(\Illuminate\Support\Str::beforeLast($route, '.').'.*')))
                    <a href="{{ route($route) }}" wire:navigate @class(['nav-item', 'nav-item-active' => $active]) @if($active) aria-current="page" @endif>
                        <x-icon :name="$icon" class="h-[18px] w-[18px]"/>{{ __($label) }}</a>
                @endforeach
            @endforeach
        </nav>
        <div class="mosque pointer-events-none absolute bottom-0 left-0 h-48 w-full bg-white/[0.035]" aria-hidden="true"></div>
    </aside>
    <div x-show="nav" x-cloak class="fixed inset-0 z-30 bg-ink/50 lg:hidden" x-on:click="nav=false"></div>
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="bg-shell-bar sticky top-0 z-20 flex h-16 items-center gap-3 px-4 text-white shadow-sm sm:px-6">
            <button class="rounded-md p-1.5 hover:bg-white/10 lg:hidden" x-on:click="nav = !nav" aria-label="{{ __('Menu') }}"><x-icon name="menu"/></button>
            <nav class="hidden min-w-0 items-center gap-2 text-sm text-white/85 sm:flex" aria-label="{{ __('Context') }}">
                <span class="truncate">{{ $user->branch?->name ?? __('Head Office') }}</span>
                <x-icon name="chevron-right" class="h-3.5 w-3.5 text-white/50"/>
                <span class="truncate">{{ $user->roleNames() }}</span>
            </nav>
            <div class="ml-auto flex items-center gap-1 sm:gap-3">
                <div class="hidden items-center gap-2 text-sm sm:flex">
                    <x-locale-pair/>
                </div>
                <livewire:shared.notification-bell dark/>
                <span class="hidden h-8 w-px bg-white/15 sm:block"></span>
                <div x-data="{ o: false }" class="relative">
                    <button x-on:click="o=!o" class="flex items-center gap-2.5 rounded-full py-1 pl-1 pr-2 hover:bg-white/10" aria-haspopup="menu">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white ring-2 ring-white/15">{{ $user->initials() }}</span>
                        <span class="hidden text-sm font-medium sm:block">{{ $user->name }}</span>
                        <x-icon name="chevron-down" class="hidden h-4 w-4 text-white/70 sm:block"/>
                    </button>
                    <div x-show="o" x-cloak x-on:click.outside="o=false" class="absolute right-0 mt-2 w-60 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 text-sm text-ink shadow-xl" role="menu">
                        <div class="border-b border-neutral-100 px-4 py-2.5"><p class="font-medium">{{ $user->name }}</p><p class="truncate text-xs text-neutral-500">{{ $user->email }}</p></div>
                        <div class="flex gap-3 border-b border-neutral-100 px-4 py-2 text-xs sm:hidden"><x-locale-switch to="en" label="English" class="link"/><x-locale-switch to="ur" class="link"/></div>
                        <a href="{{ route('password.change') }}" class="block px-4 py-2 hover:bg-neutral-50">{{ __('Change password') }}</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="w-full px-4 py-2 text-left text-red-600 hover:bg-neutral-50">{{ __('Sign out') }}</button></form>
                    </div>
                </div>
            </div>
        </header>
        <main class="mx-auto w-full max-w-[1400px] flex-1 p-4 sm:p-6 lg:p-8">{{ $slot }}</main>
    </div>
</div>
@livewireScripts
@include('layouts._realtime')
</body>
</html>
