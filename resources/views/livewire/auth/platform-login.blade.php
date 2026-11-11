<x-auth-split :heading="__('Platform administration')" :tagline="__('Provision institutions, watch integration health and manage support access. Tenant customer and financial data is never visible here.')" :badge="__('Restricted access – all actions are audited')" width="max-w-sm">
    <x-slot:brand><div class="flex items-center gap-3"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold">P</span><div><p class="text-lg font-bold leading-tight">AMCL Platform</p><p class="text-xs text-white/60">Super Admin</p></div></div></x-slot:brand>
    <div class="mb-6 text-center lg:text-start">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-brand-700 text-lg font-bold text-white lg:hidden">P</span>
        <h1 class="mt-3 text-2xl font-bold text-ink lg:mt-0">{{ __('Sign in') }}</h1>
        <p class="text-sm text-neutral-500">{{ __('Two-step verification follows your password.') }}</p>
    </div>
    <form wire:submit="login" class="card space-y-4 p-6 shadow-lg">
        <x-input label="Email" type="email" wire:model="email" icon="mail" required/>
        <x-input label="Password" type="password" wire:model="password" icon="lock" required/>
        <button class="btn-primary w-full py-2.5">{{ __('Sign in') }}</button>
    </form>
</x-auth-split>
