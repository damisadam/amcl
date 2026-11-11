@props(["tenant"])
<x-auth-split :heading="($tenant->tagline_ur && app()->getLocale() === 'ur') ? $tenant->tagline_ur : ($tenant->tagline ?: __('Own what you need, the halal way.'))"
              :tagline="__('Apply for financing, manage your wallet and pay instalments – entirely online.')" :badge="$tenant->license_ref">
    <x-slot:brand>
        <a href="{{ route('tenant.entry', $tenant) }}" class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center overflow-hidden rounded-xl ring-2 ring-white/90" style="background-color: {{ $tenant->primary_color ?? '#15803d' }}"><x-tenant-logo :tenant="$tenant" size="h-10 w-10"/></span>
            <span class="text-xl font-bold">{{ $tenant->name }}</span>
        </a>
    </x-slot:brand>
    <a href="{{ route('tenant.entry', $tenant) }}" class="mb-6 flex items-center justify-center gap-2 lg:hidden">
        <x-tenant-logo :tenant="$tenant" size="h-11 w-11"/>
        <span class="text-lg font-bold text-ink">{{ $tenant->name }}</span>
    </a>
    <div class="card p-6 shadow-lg sm:p-8">{{ $slot }}</div>
    <x-slot:below>
        <div class="flex items-center justify-center gap-4 text-xs text-neutral-500">
            <x-locale-pair tone="light" class="text-xs"/>
            <span class="text-neutral-300">|</span>
            <a href="{{ route('home') }}" class="inline-flex items-center gap-1 font-medium text-brand-700 hover:text-brand-900"><x-icon name="refresh" class="h-3.5 w-3.5"/>{{ __('Change institution') }}</a>
        </div>
    </x-slot:below>
</x-auth-split>
