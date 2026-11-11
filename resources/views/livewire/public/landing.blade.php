{{-- Institution chooser (ref: docs/ui-references/institution-selection.png). --}}
<div class="relative min-h-screen overflow-hidden">
    <img src="/images/ui/mosque-scene.svg" alt="" aria-hidden="true" class="pointer-events-none absolute bottom-0 left-0 hidden w-[36rem] max-w-[42vw] select-none md:block">
    <img src="/images/ui/mosque-scene.svg" alt="" aria-hidden="true" class="pointer-events-none absolute bottom-0 right-0 hidden w-[28rem] max-w-[34vw] -scale-x-100 select-none opacity-80 md:block">
    <div class="relative mx-auto max-w-6xl px-4 py-10 sm:py-14">
        <div class="text-center">
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-800 to-shell-950 shadow-lg" aria-hidden="true">
                <svg viewBox="0 0 40 40" class="h-10 w-10"><path d="M20 6 8 33h6l6-14 6 14h6z" fill="#fff"/><path d="M14 31c4-6 9-8 14-8-3 5-8 8-14 8z" fill="#4ade80"/></svg>
            </span>
            <h1 class="mx-auto mt-5 max-w-4xl text-3xl font-extrabold tracking-tight text-ink sm:text-5xl">{{ __('Shariah-compliant digital financing & wallet') }}</h1>
            <p class="mx-auto mt-4 max-w-2xl text-base text-neutral-600 sm:text-lg">{{ __('Choose your institution to apply for financing, manage your wallet and pay instalments – entirely online.') }}</p>
            <div class="mt-6 inline-flex rounded-full border border-neutral-200 bg-white p-1 text-sm shadow-sm" role="group" aria-label="{{ __('Language') }}">
                <x-locale-switch to="en" label="English" :class="'rounded-full px-5 py-1.5 font-medium '.(app()->getLocale() === 'en' ? 'bg-brand-50 text-brand-800' : 'text-neutral-600 hover:text-ink')"/>
                <x-locale-switch to="ur" :class="'rounded-full px-5 py-1.5 font-medium '.(app()->getLocale() === 'ur' ? 'bg-brand-50 text-brand-800' : 'text-neutral-600 hover:text-ink')"/>
            </div>
        </div>
        <div class="mt-10 flex flex-wrap justify-center gap-5">
            @foreach($tenants as $t)
                <a href="{{ route('tenant.entry', $t) }}" class="card group flex w-full flex-col p-6 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-lg sm:w-[calc(50%-0.75rem)] lg:w-[calc(33.333%-0.85rem)]">
                    <div class="flex items-center gap-4">
                        <x-tenant-logo :tenant="$t" size="h-14 w-14"/>
                        <div class="min-w-0"><p class="text-lg font-bold leading-snug text-ink">{{ $t->name }}</p>
                            @if($t->city)<p class="mt-0.5 flex items-center gap-1 text-sm text-neutral-500"><x-icon name="map" class="h-4 w-4 text-brand-700"/>{{ $t->city }}</p>@endif</div>
                    </div>
                    <p class="mt-4 flex-1 text-[15px] text-neutral-600">{{ app()->getLocale() === 'ur' && $t->tagline_ur ? $t->tagline_ur : $t->tagline }}</p>
                    <div class="mt-5 flex items-center justify-between text-sm">
                        @if($t->is_demo)<x-badge tone="amber" class="px-3 py-1">{{ __('Demo institution') }}</x-badge>@else<span></span>@endif
                        <span class="inline-flex items-center gap-1.5 font-semibold text-brand-700 group-hover:text-brand-900">{{ __('Continue') }}<x-icon name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5 rtl:rotate-180"/></span>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mx-auto mt-12 flex max-w-xl items-center gap-4 text-sm text-neutral-600">
            <span class="h-px flex-1 bg-neutral-300"></span>
            <p class="text-center">{{ __('Staff or vendor?') }} <a href="{{ route('staff.login') }}" class="inline-flex items-center gap-1 font-semibold text-brand-700 hover:text-brand-900">{{ __('Sign in to the operations portal') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a></p>
            <span class="h-px flex-1 bg-neutral-300"></span>
        </div>
    </div>
</div>
