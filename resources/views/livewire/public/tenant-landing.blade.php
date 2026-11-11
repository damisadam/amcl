<div>
    {{-- Hero (ref: docs/ui-references/financing-products.png). Tenant colour tints the gradient; illustrations are SVG. --}}
    <header class="relative overflow-hidden text-white" style="background: linear-gradient(120deg, #052414 0%, #0d4229 50%, {{ $tenant->primary_color }} 140%)">
        <div class="pointer-events-none absolute inset-0 opacity-30 [background-image:url('/images/ui/pattern.svg')] [background-size:88px] [mask-image:linear-gradient(90deg,#000,transparent_45%,transparent_70%,#000)]" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-6xl px-4 py-5">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white p-1 shadow"><x-tenant-logo :tenant="$tenant" size="h-9 w-9"/></span>
                <div class="min-w-0"><p class="text-lg font-bold leading-tight">{{ $tenant->name }}</p><p class="truncate text-xs text-white/70"><bdi>{{ $tenant->license_ref }}</bdi></p></div>
                <div class="ml-auto flex items-center gap-3 text-xs sm:text-sm rtl:ml-0 rtl:mr-auto">
                    <x-locale-pair class="hidden sm:flex"/>
                    <x-locale-switch class="rounded-md px-2 py-1 hover:bg-white/10 sm:hidden"/>
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-white/30 px-3 py-1.5 font-medium hover:bg-white/10"><x-icon name="refresh" class="h-4 w-4"/>{{ __('Change institution') }}</a>
                </div>
            </div>
            <div class="grid items-center gap-8 py-8 sm:py-10 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                <div>
                    @php($headline = $banner ? (app()->getLocale() === 'ur' && $banner->title_ur ? $banner->title_ur : $banner->title) : __('Own what you need, the halal way.'))
                    {{-- Emphasise the price point ("PKR 6,000/month*") the way the reference does. --}}
                    <h1 class="max-w-xl text-3xl font-extrabold leading-tight tracking-tight sm:text-[2.6rem]">{!! preg_replace('/(PKR\s?[\d,]+(?:\/\S+)?)/u', '<span class="text-brand-300"><bdi>$1</bdi></span>', e($headline)) !!}</h1>
                    <p class="mt-3 max-w-xl text-base text-white/85">{{ app()->getLocale() === 'ur' && $tenant->tagline_ur ? $tenant->tagline_ur : $tenant->tagline }}</p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('customer.register', $tenant) }}" class="btn bg-white px-5 text-ink hover:bg-neutral-100">{{ __('Create account') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
                        <a href="{{ route('customer.login', $tenant) }}" class="btn border border-white/50 px-6 text-white hover:bg-white/10">{{ __('Sign in') }}</a>
                    </div>
                </div>
                <div class="relative hidden h-56 lg:block" aria-hidden="true">
                    <svg viewBox="0 0 300 220" class="absolute inset-y-0 right-6 h-full text-white/25" fill="none" stroke="currentColor" stroke-width="2"><path d="M30 220V110C30 50 90 12 150 4c60 8 120 46 120 106v110"/><path d="M48 220V114c0-52 50-86 102-94 52 8 102 42 102 94v106"/></svg>
                    <div class="absolute inset-x-10 bottom-0 h-24 rounded-[100%] bg-white/10 blur-2xl"></div>
                    <div class="absolute bottom-2 right-12 flex items-end gap-3">
                        <x-product-art category="motorbike" class="h-28 w-28 bg-white/95 shadow-xl"/>
                        <x-product-art category="solar" class="mb-10 h-24 w-24 bg-white/95 shadow-xl"/>
                        <x-product-art category="laptop" class="h-20 w-20 bg-white/95 shadow-xl"/>
                        <x-product-art category="home_appliances" class="mb-4 h-32 w-32 bg-white/95 shadow-xl"/>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <section class="mx-auto max-w-6xl px-4 py-10">
        <h2 class="text-2xl font-bold tracking-tight text-ink">{{ __('Financing products') }}</h2>
        <p class="mt-1 text-[15px] text-neutral-500">{{ __('Indicative terms. Final terms are shown in your Key Fact Statement before you sign.') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($products as $p)
                @php($v = $p->currentVersion)
                <article class="card flex flex-col p-4 transition hover:shadow-md">
                    <div class="flex gap-4">
                        <x-product-art :category="$p->category" class="h-20 w-20"/>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">{{ $p->localizedName() }}</h3><x-badge tone="green">{{ $p->shariah_structure->label() }}</x-badge></div>
                            <p class="mt-1 line-clamp-2 text-sm text-neutral-600">{{ $p->localizedDescription() }}</p>
                        </div>
                    </div>
                    @if($v)
                    <dl class="mt-4 grid grid-cols-[1.35fr_1fr_1fr_1fr] divide-x divide-neutral-200 text-xs rtl:divide-x-reverse">
                        <div class="pe-2"><dt class="text-neutral-500">{{ __('Amount') }}</dt><dd class="mt-0.5 font-semibold text-ink tabular"><bdi>{{ \App\Support\Money::format($v->min_amount, false) }} –<br>{{ \App\Support\Money::format($v->max_amount, false) }}</bdi></dd></div>
                        <div class="px-2"><dt class="text-neutral-500">{{ __('Tenor') }}</dt><dd class="mt-0.5 font-semibold text-ink">{{ $v->min_tenure }}–{{ $v->max_tenure }} {{ $v->isWeekly() ? __('weeks') : __('months') }}</dd></div>
                        <div class="px-2"><dt class="text-neutral-500">{{ __('Profit rate') }}</dt><dd class="mt-0.5 font-semibold text-ink">{{ rtrim(rtrim($v->profit_rate, '0'), '.') }}% {{ __('p.a.') }}</dd></div>
                        <div class="ps-2"><dt class="text-neutral-500">{{ __('Min. income') }}</dt><dd class="mt-0.5 font-semibold text-ink tabular">{{ \App\Support\Money::format($v->min_income, false) }}</dd></div>
                    </dl>
                    @endif
                </article>
            @endforeach
        </div>
        @if($faqs->isNotEmpty())
            <h2 class="mt-10 text-2xl font-bold tracking-tight text-ink">{{ __('Frequently asked questions') }}</h2>
            <div class="mt-3 grid items-start gap-3 md:grid-cols-2">
                @foreach($faqs as $f)
                    <details class="card group px-4 py-3"><summary class="flex cursor-pointer list-none items-center gap-3 font-medium text-ink [&::-webkit-details-marker]:hidden"><x-icon name="chevron-right" class="h-4 w-4 text-neutral-500 transition group-open:rotate-90 rtl:rotate-180 rtl:group-open:rotate-90"/>{{ app()->getLocale() === 'ur' && $f->title_ur ? $f->title_ur : $f->title }}</summary>
                        <p class="mt-2 ps-7 text-sm text-neutral-600">{{ app()->getLocale() === 'ur' && $f->body_ur ? $f->body_ur : $f->body }}</p></details>
                @endforeach
            </div>
        @endif
        <p class="mt-10 border-t border-neutral-200 pt-4 text-center text-xs text-neutral-500"><span class="font-semibold text-neutral-700">{{ __('Grievances') }}:</span> <bdi>{{ $tenant->support_phone }}</bdi> <span class="mx-2 text-neutral-300">|</span> <bdi>{{ $tenant->support_email }}</bdi></p>
    </section>
</div>
