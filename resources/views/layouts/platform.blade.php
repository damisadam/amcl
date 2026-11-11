<!DOCTYPE html>
<html lang="en">
<head>@include('layouts._head')</head>
<body class="ornament min-h-screen" x-data="{ nav: false }">
<x-demo-banner/>
<x-flash/>
@php($user = auth()->user())
<div class="flex min-h-screen">
    <aside class="bg-shell fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col text-white transition lg:sticky lg:top-0 lg:h-screen lg:translate-x-0" :class="nav ? 'translate-x-0' : ''">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold">P</span>
            <div><p class="text-[15px] font-bold leading-tight">AMCL Platform</p><p class="text-xs text-white/65">Super Admin</p></div>
        </div>
        <nav class="relative z-10 flex-1 overflow-y-auto px-3 pb-6 pt-2">
            @php($items = \App\Support\Navigation::platform($user))
            @foreach($items as [$label, $route, $perm, $icon])
                @php($section = $route === 'platform.dashboard' ? 'Overview' : 'Tenancy')
                @if($section !== ($prevSection ?? null))<p class="nav-section">{{ $section }}</p>@php($prevSection = $section)@endif
                <a href="{{ route($route) }}" wire:navigate @class(['nav-item', 'nav-item-active' => request()->routeIs($route)])><x-icon :name="$icon" class="h-[18px] w-[18px]"/>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="mosque pointer-events-none absolute bottom-0 left-0 h-56 w-full bg-white/[0.06]" aria-hidden="true"></div>
    </aside>
    <div x-show="nav" x-cloak class="fixed inset-0 z-30 bg-ink/50 lg:hidden" x-on:click="nav=false"></div>
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="bg-shell-bar sticky top-0 z-20 flex h-16 items-center gap-3 px-4 text-white shadow-sm sm:px-6">
            <button class="rounded-md p-1.5 hover:bg-white/10 lg:hidden" x-on:click="nav=!nav" aria-label="Menu"><x-icon name="menu"/></button>
            <p class="hidden min-w-0 truncate text-sm md:block"><span class="text-[15px] font-semibold">Platform administration</span> <span class="text-white/70">– no default access to tenant customer or financial data</span></p>
            <div class="ml-auto flex items-center gap-2 sm:gap-3 text-sm">
                <livewire:shared.notification-bell dark/>
                <div x-data="{ o: false }" class="relative">
                    <button x-on:click="o=!o" class="flex items-center gap-2.5 rounded-full py-1 pl-1 pr-2 hover:bg-white/10" aria-haspopup="menu">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-xs font-bold ring-2 ring-white/15">{{ $user->initials() }}</span>
                        <span class="hidden font-medium sm:block">{{ $user->name }}</span><x-icon name="chevron-down" class="hidden h-4 w-4 text-white/70 sm:block"/>
                    </button>
                    <div x-show="o" x-cloak x-on:click.outside="o=false" class="absolute right-0 mt-2 w-60 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 text-ink shadow-xl" role="menu">
                        <div class="border-b border-neutral-100 px-4 py-2.5"><p class="font-medium">{{ $user->name }}</p><p class="truncate text-xs text-neutral-500">{{ $user->email }}</p></div>
                        <a href="{{ route('password.change') }}" class="block px-4 py-2 hover:bg-neutral-50">Change password</a>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-white/40 px-3 py-1.5 font-semibold hover:bg-white/10">Sign out</button></form>
            </div>
        </header>
        <main class="mx-auto w-full max-w-[1400px] flex-1 p-4 sm:p-6 lg:p-8">{{ $slot }}</main>
    </div>
</div>
@livewireScripts
@include('layouts._realtime')
</body>
</html>
