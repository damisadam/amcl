<div>
    <div class="mb-5 flex items-start justify-between gap-6">
        <x-page-header class="mb-0! min-w-0 flex-1" :title="__('Apply for Financing')" :subtitle="__('Shariah-compliant products from :t. Indicative terms – your final terms are shown in the Key Fact Statement before signing.', ['t' => $currentTenant->name ?? ''])"/>
        <x-verse class="shrink-0"/>
    </div>
    @if($customer->kyc_status->value !== 'passed')
        <x-alert class="mb-4">{{ __('You can explore products now. Complete identity verification before submitting an application.') }} <a href="{{ route('app.onboarding') }}" wire:navigate class="font-semibold underline">{{ __('Verify now') }}</a></x-alert>
    @endif
    <div class="mb-5 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('category', '')" @class(['btn-sm rounded-full px-3.5 py-1.5 text-xs font-medium', 'bg-brand-700 text-white shadow-sm' => $category === '', 'bg-white border border-neutral-200 text-neutral-700 hover:border-brand-300' => $category !== ''])>{{ __('All') }}</button>
        @foreach($categories as $k => $l)
            <button wire:click="$set('category', '{{ $k }}')" @class(['shrink-0 rounded-full px-3.5 py-1.5 text-xs font-medium', 'bg-brand-700 text-white shadow-sm' => $category === $k, 'bg-white border border-neutral-200 text-neutral-700 hover:border-brand-300' => $category !== $k])>{{ $l }}</button>
        @endforeach
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
        @forelse($products as $p)
            @php($v = $p->currentVersion)
            <article class="card group relative flex flex-col p-4 transition hover:border-brand-300 hover:shadow-lg" wire:key="p-{{ $p->id }}">
                <div class="flex gap-4">
                    <x-product-art :category="$p->category" class="h-24 w-24"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-2">
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1">
                                <h2 class="text-lg font-bold text-ink"><a href="{{ route('app.products.show', $p) }}" wire:navigate class="after:absolute after:inset-0">{{ $p->localizedName() }}</a></h2>
                                @if($p->shariah_board_approved)<span class="inline-flex items-center gap-1 text-xs font-medium text-brand-700"><x-icon name="check-circle" class="h-4 w-4"/>{{ __('Shariah approved') }}</span>@endif
                                <x-badge tone="green">{{ __($p->shariah_structure->label()) }}</x-badge>
                            </div>
                            <x-icon name="chevron-right" class="mt-1 h-5 w-5 shrink-0 text-neutral-400 group-hover:text-ink rtl:rotate-180"/>
                        </div>
                        <p class="mt-1 line-clamp-2 text-sm text-neutral-600">{{ $p->localizedDescription() }}</p>
                        @if($v)
                            @php($rep = $v->representativeQuote())
                            <p class="mt-1.5 text-[15px] text-neutral-700">{{ __('From') }} <strong class="text-ink tabular"><bdi>{{ \App\Support\Money::format($rep['instalment']) }}</bdi></strong>/{{ $v->isWeekly() ? __('week') : __('month') }}
                                <span class="mx-1 text-neutral-400">•</span>{{ __('APR') }} <strong class="text-ink tabular"><bdi>{{ $rep['apr'] }}%</bdi></strong></p>
                        @endif
                    </div>
                </div>
                @if($v)
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div class="grid flex-1 grid-cols-3 divide-x divide-neutral-200 rounded-lg border border-neutral-200 py-2 text-center text-xs rtl:divide-x-reverse">
                            <div><p class="text-neutral-500">{{ __('Up to') }}</p><p class="font-semibold text-ink tabular">{{ \App\Support\Money::format($v->max_amount, false) }}</p></div>
                            <div><p class="text-neutral-500">{{ __('Tenor') }}</p><p class="font-semibold text-ink">{{ $v->min_tenure }}–{{ $v->max_tenure }} {{ $v->isWeekly() ? __('weeks') : __('months') }}</p></div>
                            <div><p class="text-neutral-500">{{ __('Profit rate') }}</p><p class="font-semibold text-ink"><bdi>{{ rtrim(rtrim((string) $v->profit_rate, '0'), '.') }}% {{ $v->profit_method === 'flat' ? __('flat') : __('reducing') }}</bdi></p></div>
                        </div>
                        <span class="btn-primary shrink-0 bg-brand-800 px-5 group-hover:bg-brand-900" aria-hidden="true">{{ __('View details') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></span>
                    </div>
                    {{-- Consumer disclosure kept from the previous design: representative example behind the advertised APR. --}}
                    <p class="mt-2 text-[11px] text-neutral-400">{{ __('Representative example: :a over :t :u. APR includes all fees.', ['a' => \App\Support\Money::format($rep['amount']), 't' => $rep['tenure'], 'u' => $v->isWeekly() ? __('weeks') : __('months')]) }} {{ __('Minimum income') }} <bdi>{{ \App\Support\Money::format($v->min_income) }}</bdi>.</p>
                @endif
            </article>
        @empty
            <div class="card xl:col-span-2"><x-empty :title="__('No products available')" :description="__('Please check back later.')"/></div>
        @endforelse
    </div>
</div>
