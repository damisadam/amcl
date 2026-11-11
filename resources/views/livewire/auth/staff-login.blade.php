<x-auth-split :heading="__('Operations, finance, field and vendor portals')" :tagline="__('One sign-in for branch, credit, finance and vendor teams. Every approval and posting is audited.')" :badge="__('Staff & vendors only')">
    <x-slot:brand><a href="{{ route('home') }}" class="flex items-center gap-3"><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20"><svg viewBox="0 0 40 40" class="h-7 w-7" aria-hidden="true"><path d="M20 6 8 33h6l6-14 6 14h6z" fill="#fff"/><path d="M14 31c4-6 9-8 14-8-3 5-8 8-14 8z" fill="#4ade80"/></svg></span><span class="text-lg font-bold">AMCL</span></a></x-slot:brand>
    <div>
        <div class="mb-6 text-center lg:text-start">
            <a href="{{ route('home') }}" class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-700 text-lg font-bold text-white lg:hidden">A</a>
            <h1 class="mt-3 text-2xl font-bold text-ink lg:mt-0">{{ __('Staff & Vendor sign in') }}</h1>
            <p class="text-sm text-neutral-500">{{ __('Operations, finance, field and vendor portals') }}</p>
        </div>
        <form wire:submit="login" class="card space-y-4 p-6 shadow-lg">
            <x-input :label="__('Organisation code')" wire:model="org" autocomplete="organization" autocapitalize="none" spellcheck="false" placeholder="e.g. your institution's code" :hint="__('Provided by your institution (also part of your institution\'s staff link).')" required/>
            <x-input :label="__('Email')" type="email" wire:model="email" icon="mail" autocomplete="username" required/>
            <x-input :label="__('Password')" type="password" wire:model="password" icon="lock" autocomplete="current-password" required/>
            <x-checkbox wire:model="remember" :label="__('Keep me signed in on this device')"/>
            <button class="btn-primary w-full py-2.5" wire:loading.attr="disabled">{{ __('Sign in') }}</button>
            <p class="text-center text-xs text-neutral-500">{{ __('Forgot your password? Ask your institution administrator to reset it.') }}</p>
        </form>
        <p class="mt-4 text-center text-xs text-neutral-500"><a class="link" href="{{ route('home') }}">{{ __('Customer portal') }}</a> · <a class="link" href="{{ route('platform.login') }}">{{ __('Platform admin') }}</a></p>
    </div>
</x-auth-split>
