<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>@include('layouts._head')</head>
<body class="ornament min-h-screen" x-data="{ more: false }">
<x-demo-banner/>
<x-flash/>
@php($nav = \App\Support\Navigation::customer())
@php($me = auth()->user())
@php($cif = $me?->customer?->cif_no)
{{-- Sidebar badges (ref: docs/ui-references/customer-dashboard.png): applications and unread notifications, from the database. --}}
@php($counts = [
    'app.applications' => $me?->customer_id ? \App\Models\LoanApplication::where('customer_id', $me->customer_id)->count() : 0,
    'app.notifications' => $me?->unreadNotifications()->count() ?? 0,
])
<header class="bg-shell-bar sticky top-0 z-30 text-white shadow-sm" x-data="{ q: '', open: false, items: @js(collect($nav)->map(fn ($i) => ['label' => __($i[0]), 'url' => route($i[1])])
            ->concat(Route::has('app.products.show') ? \App\Models\Product::where('is_active', true)->get()->map(fn ($p) => ['label' => $p->localizedName(), 'url' => route('app.products.show', $p)]) : [])->values()) }"
        x-on:keydown.window.prevent.ctrl.k="open = true; $nextTick(() => $refs.search.focus())" x-on:keydown.window.prevent.meta.k="open = true; $nextTick(() => $refs.search.focus())">
    <div class="flex h-16 items-center gap-4 px-4 lg:px-6">
        <a href="{{ route('app.home') }}" wire:navigate class="flex min-w-0 items-center gap-3 lg:w-64">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full ring-2 ring-white/80" style="background-color: {{ $currentTenant->primary_color ?? '#15803d' }}"><x-tenant-logo :tenant="$currentTenant ?? null" size="h-11 w-11"/></span>
            <span class="min-w-0"><span class="block truncate text-lg font-bold leading-tight">{{ $currentTenant->name ?? '' }}</span><span class="block text-xs text-white/70">{{ __('Customer Portal') }}</span></span>
        </a>
        {{-- Quick finder (⌘K / Ctrl+K): searches the portal's menu and the product catalogue client-side – no customer data leaves the page. --}}
        <div class="relative hidden max-w-md flex-1 md:block" x-on:click.outside="open = false">
            <x-icon name="search" class="pointer-events-none absolute start-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-white/60"/>
            <input x-ref="search" x-model="q" x-on:focus="open = true" x-on:keydown.escape="open = false; $el.blur()"
                   x-on:keydown.enter.prevent="const m = items.filter(i => i.label.toLowerCase().includes(q.toLowerCase())); if (m.length) Livewire.navigate(m[0].url)"
                   type="search" placeholder="{{ __('Search pages and products…') }}" aria-label="{{ __('Search the portal') }}"
                   class="w-full rounded-full border border-white/15 bg-white/10 py-2 pe-16 ps-10 text-sm text-white placeholder:text-white/60 focus:border-white/40 focus:bg-white/15 focus:outline-none">
            <kbd class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 rounded-md border border-white/20 px-1.5 text-[11px] text-white/70">⌘ K</kbd>
            <div x-show="open && q.length" x-cloak class="absolute inset-x-0 top-full mt-2 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 text-sm text-ink shadow-xl">
                <template x-for="i in items.filter(i => i.label.toLowerCase().includes(q.toLowerCase()))" :key="i.url">
                    <a :href="i.url" wire:navigate class="flex items-center justify-between px-4 py-2 hover:bg-brand-50"><span x-text="i.label"></span><x-icon name="chevron-right" class="h-4 w-4 text-neutral-400 rtl:rotate-180"/></a>
                </template>
                <p x-show="!items.some(i => i.label.toLowerCase().includes(q.toLowerCase()))" class="px-4 py-3 text-neutral-500">{{ __('No matching pages') }}</p>
            </div>
        </div>
        <div class="ms-auto flex items-center gap-1 sm:gap-3">
            <x-locale-pair class="hidden sm:flex"/>
            <x-locale-switch class="rounded-md px-2 py-1 text-xs font-semibold hover:bg-white/10 sm:hidden"/>
            <livewire:shared.notification-bell dark/>
            <span class="hidden h-9 w-px bg-white/15 sm:block"></span>
            <div x-data="{ o: false }" class="relative">
                <button x-on:click="o=!o" class="flex items-center gap-2.5 rounded-full py-1 ps-1 pe-2 hover:bg-white/10" aria-haspopup="menu">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/20 text-sm font-bold ring-1 ring-white/30">{{ $me?->initials() }}</span>
                    <span class="hidden text-start leading-tight sm:block"><span class="block max-w-40 truncate text-sm font-semibold">{{ $me?->name }}</span>@if($cif)<span class="block text-xs text-white/70">CIF: <bdi>{{ \Illuminate\Support\Str::after($cif, 'CIF-') }}</bdi></span>@endif</span>
                    <x-icon name="chevron-down" class="hidden h-4 w-4 text-white/70 sm:block"/>
                </button>
                <div x-show="o" x-cloak x-on:click.outside="o=false" class="absolute end-0 mt-2 w-56 overflow-hidden rounded-xl border border-neutral-200 bg-white py-1 text-sm text-ink shadow-xl" role="menu">
                    <div class="border-b border-neutral-100 px-4 py-2.5"><p class="truncate font-medium">{{ $me?->name }}</p><p class="truncate text-xs text-neutral-500"><bdi>{{ $me?->email ?? $me?->mobile }}</bdi></p></div>
                    @if(Route::has('app.profile'))<a href="{{ route('app.profile') }}" wire:navigate class="block px-4 py-2 hover:bg-neutral-50">{{ __('Profile & Settings') }}</a>@endif
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="w-full px-4 py-2 text-start text-red-600 hover:bg-neutral-50">{{ __('Sign out') }}</button></form>
                </div>
            </div>
        </div>
    </div>
</header>
<div class="flex">
    <aside class="sticky top-16 hidden h-[calc(100vh-4rem)] w-72 shrink-0 flex-col overflow-y-auto border-e border-neutral-200/70 bg-white/90 backdrop-blur md:flex">
        <nav class="space-y-1 p-4 text-[15px]">
            @foreach($nav as [$label, $route, $icon])
                @php($on = request()->routeIs($route.'*'))
                <a href="{{ route($route) }}" wire:navigate @class(['flex items-center gap-3 rounded-lg border-s-4 px-3 py-2.5 transition', 'border-brand-600 bg-brand-50 font-semibold text-brand-800' => $on, 'border-transparent text-neutral-700 hover:bg-neutral-50 hover:text-ink' => ! $on]) @if($on) aria-current="page" @endif>
                    <x-icon :name="$icon" @class(['h-5 w-5', 'text-brand-700' => $on, 'text-neutral-500' => ! $on])/><span class="flex-1">{{ __($label) }}</span>
                    @if(($counts[$route] ?? 0) > 0)<span @class(['rounded-full px-2 py-0.5 text-[11px] font-semibold tabular', 'bg-red-50 text-red-600 ring-1 ring-red-200' => $route === 'app.notifications', 'bg-neutral-100 text-neutral-600' => $route !== 'app.notifications'])><bdi><bdi>{{ $counts[$route] > 9 ? '9+' : $counts[$route] }}</bdi></bdi></span>@endif</a>
            @endforeach
        </nav>
        @if(Route::has('app.products'))
            <div class="relative mx-4 mb-4 mt-auto overflow-hidden rounded-2xl bg-gradient-to-b from-brand-50 to-white p-5 ring-1 ring-brand-100">
                <img src="/images/ui/mosque-scene.svg" alt="" aria-hidden="true" class="pointer-events-none absolute -bottom-2 -end-10 w-64 opacity-90 rtl:-scale-x-100">
                <div class="relative pb-28">
                    <p class="text-lg font-bold leading-snug text-ink">{{ __('Financing for a brighter tomorrow') }}</p>
                    <p class="mt-1 text-sm text-neutral-600">{{ __('Shariah-compliant solutions for your real needs.') }}</p>
                    <a href="{{ route('app.products') }}" wire:navigate class="btn-primary mt-4 bg-brand-800 hover:bg-brand-900">{{ __('Explore products') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
                </div>
            </div>
        @endif
    </aside>
    <main class="relative z-10 min-w-0 flex-1 px-4 pb-28 pt-5 sm:px-6 md:pb-8 lg:px-10 lg:pt-7">
        <img src="/images/ui/mosque-scene.svg" alt="" aria-hidden="true" class="pointer-events-none fixed bottom-0 end-0 -z-10 hidden w-[30rem] max-w-[32vw] -scale-x-100 select-none opacity-70 xl:block rtl:scale-x-100">
        <div class="mx-auto max-w-6xl">
        @php($crumb = collect($nav)->first(fn ($i) => $i[1] !== 'app.home' && request()->routeIs($i[1].'*')))
        @if($crumb)
            <nav class="mb-2 flex items-center gap-2 text-sm text-neutral-500" aria-label="{{ __('Breadcrumb') }}">
                <a href="{{ route('app.home') }}" wire:navigate class="hover:text-brand-800">{{ __('Home') }}</a><x-icon name="chevron-right" class="h-3.5 w-3.5 rtl:rotate-180"/>
                @if(request()->routeIs($crumb[1]))<span class="font-medium text-neutral-800">{{ __($crumb[0]) }}</span>
                @else<a href="{{ route($crumb[1]) }}" wire:navigate class="hover:text-brand-800">{{ __($crumb[0]) }}</a>@endif
            </nav>
        @endif
        {{ $slot }}
        {{-- Trust cues on every customer page (QA UX): licence, Shariah governance and the grievance route. --}}
        <footer class="mx-auto mt-10 max-w-3xl border-t border-neutral-300/70 pt-4 text-center text-[11px] leading-relaxed text-neutral-500">
            <p><span class="font-semibold text-neutral-700">{{ $currentTenant->legal_name ?? $currentTenant->name ?? '' }}</span>@if($currentTenant->license_ref ?? null) · <bdi>{{ $currentTenant->license_ref }}</bdi>@endif</p>
            <p>{{ __('All financing products are structured as Shariah-compliant contracts approved by the institution\'s Shariah board. Late payment amounts are donated to charity.') }}</p>
            <p>{{ __('Complaints & grievances') }}: <bdi>{{ $currentTenant->support_phone ?? '' }}</bdi> · <bdi>{{ $currentTenant->support_email ?? '' }}</bdi> · <a href="{{ route('app.support') }}" wire:navigate class="link">{{ __('Raise a complaint') }}</a></p>
        </footer>
        </div>
    </main>
</div>
{{-- Mobile app-style bottom navigation --}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-neutral-200 bg-white pb-[env(safe-area-inset-bottom)] md:hidden">
    <div class="grid grid-cols-5 text-[11px]">
        @foreach([['app.home','home','Home'],['app.products','plus','Apply'],['app.wallet','wallet','Wallet'],['app.financing','document','Financing']] as [$r,$i,$l])
            @if(Route::has($r))
            <a href="{{ route($r) }}" wire:navigate @class(['flex flex-col items-center gap-0.5 py-2', 'text-brand-700 font-semibold' => request()->routeIs($r.'*'), 'text-neutral-500' => ! request()->routeIs($r.'*')])><x-icon :name="$i"/>{{ __($l) }}</a>
            @endif
        @endforeach
        <button x-on:click="more = true" class="flex flex-col items-center gap-0.5 py-2 text-neutral-500"><x-icon name="menu"/>{{ __('More') }}</button>
    </div>
</nav>
<div x-show="more" x-cloak class="fixed inset-0 z-40 md:hidden">
    <div class="absolute inset-0 bg-ink/50" x-on:click="more=false"></div>
    <div class="absolute inset-x-0 bottom-0 rounded-t-2xl bg-white p-4 pb-8">
        <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-neutral-300"></div>
        <div class="grid grid-cols-3 gap-3 text-center text-xs">
            @foreach($nav as [$label, $route, $icon])
                <a href="{{ route($route) }}" wire:navigate x-on:click="more=false" class="flex flex-col items-center gap-1 rounded-xl p-3 hover:bg-neutral-50"><span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon :name="$icon"/></span>{{ __($label) }}</a>
            @endforeach
        </div>
    </div>
</div>
@livewireScripts
@include('layouts._realtime')
</body>
</html>
