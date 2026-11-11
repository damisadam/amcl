<div>
    <x-page-header :title="__('My Financing')" :subtitle="__('View and manage your financing. Access details, repayment schedule and important documents.')"/>
    <div class="space-y-5">
        @forelse($loans as $l)
            @php($paid = (string) $l->total_paid)
            @php($outstanding = $l->totalOutstanding())
            @php($base = \App\Support\Money::add($paid, $outstanding))
            @php($pct = \App\Support\Money::isPositive($base) ? (int) floor((float) bcdiv(bcmul($paid, '100', 4), $base, 4)) : 0)
            @php($next = $l->nextInstalment())
            @php($open = in_array($l->status->value, ['active', 'overdue']))
            <article class="card border-brand-200 p-5 sm:p-6" wire:key="loan-{{ $l->id }}">
                <div class="flex flex-col gap-5 md:flex-row">
                    <x-product-art :category="$l->product->category" class="h-28 w-28 shrink-0 rounded-2xl"/>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><h2 class="text-xl font-bold text-ink">{{ $l->product->localizedName() }}</h2>
                                <p class="mt-0.5 text-sm text-neutral-500"><bdi>{{ $l->account_no }}</bdi> <span class="mx-1">•</span> {{ __('Disbursed on :d', ['d' => $l->disbursed_at->format('d M Y')]) }}</p></div>
                            <x-status :value="$l->status" class="px-3! py-1! text-sm! font-semibold"/>
                        </div>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            @foreach([['database', __('Financed amount'), $l->principal], ['wallet', __('Paid amount'), $paid], ['document-text', __('Outstanding amount'), $outstanding]] as [$ic, $lbl, $amt])
                                <div class="flex items-center gap-3 rounded-xl border border-neutral-200 px-4 py-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon :name="$ic" class="h-5 w-5"/></span>
                                    <div class="min-w-0"><p class="text-sm text-neutral-500">{{ $lbl }}</p><p class="text-lg font-bold text-ink"><x-money :amount="$amt"/></p></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div class="flex gap-4 rounded-xl bg-neutral-50 p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-700"><x-icon name="chart" class="h-5 w-5"/></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">{{ __('Repayment overview') }}</p>
                            <p class="text-sm text-neutral-600">{{ __('Your full instalment schedule is in your Key Fact Statement and on the financing details page.') }}</p>
                            <div class="mt-4 flex items-center gap-3"><div class="h-2.5 flex-1 rounded-full bg-neutral-200"><div class="h-2.5 rounded-full bg-brand-600" style="width: {{ $pct }}%"></div></div><span class="text-sm font-medium text-neutral-600 tabular">{{ $pct }}%</span></div>
                            <div class="mt-3 grid grid-cols-2 divide-x divide-neutral-200 rtl:divide-x-reverse">
                                <div class="pe-4"><p class="font-bold text-ink"><x-money :amount="$paid"/></p><p class="text-sm text-neutral-500">{{ __('Amount paid') }}</p></div>
                                <div class="ps-4"><p class="font-bold text-ink"><x-money :amount="$outstanding"/></p><p class="text-sm text-neutral-500">{{ __('Remaining amount') }}</p></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-4 rounded-xl bg-brand-50/60 p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-700"><x-icon name="clipboard" class="h-5 w-5"/></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-ink">{{ __('Next steps') }}</p>
                            <p @class(['text-sm', 'font-medium text-red-700' => $l->dpd > 0, 'text-neutral-600' => $l->dpd <= 0])>
                                @if($l->dpd > 0){{ __(':d days overdue – please pay as soon as possible.', ['d' => $l->dpd]) }}
                                @elseif($open && $next){{ __('Next instalment of :a is due on :d.', ['a' => \App\Support\Money::format(\App\Support\Money::sub(\App\Support\Money::add($next->total_due, $next->late_charge_due), $next->total_paid)), 'd' => $next->due_date->format('d M Y')]) }}
                                @else{{ __('This financing is closed. Your documents remain available on the details page.') }}@endif
                            </p>
                            <div class="mt-4 space-y-2">
                                <a href="{{ route('app.financing.show', $l) }}" wire:navigate class="btn-primary w-full bg-brand-800 hover:bg-brand-900">{{ __('View financing details') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
                                @if($open && $next && Route::has('app.pay'))<a href="{{ route('app.pay') }}?loan={{ $l->id }}" wire:navigate class="btn-secondary w-full border-brand-600 text-brand-800 hover:bg-brand-50"><x-icon name="calendar" class="h-4 w-4"/>{{ __('Pay instalment') }}</a>@endif
                            </div>
                        </div>
                    </div>
                </div>

                @if($l->application)
                    <div class="mt-4 flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50/70 p-4 sm:flex-row sm:items-center">
                        <x-icon name="info" class="h-6 w-6 shrink-0 text-sky-600"/>
                        <div class="min-w-0 flex-1"><p class="font-semibold text-sky-900">{{ __('Your Key Fact Statement') }}</p>
                            <p class="text-sm text-sky-800/80">{{ __('The Key Fact Statement (KFS) shows your agreed terms, instalment schedule and all applicable fees.') }}</p></div>
                        <a href="{{ route('app.applications.show', $l->application) }}" wire:navigate class="btn shrink-0 bg-white text-sky-700 ring-1 ring-sky-200 hover:bg-sky-100">{{ __('View KFS') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
                    </div>
                @endif
            </article>
        @empty
            <div class="card"><x-empty icon="wallet" :title="__('No financing yet')" :description="__('Once your application is disbursed, your repayment schedule appears here.')"><a href="{{ route('app.products') }}" wire:navigate class="btn-primary">{{ __('Browse products') }}</a></x-empty></div>
        @endforelse
    </div>
</div>
