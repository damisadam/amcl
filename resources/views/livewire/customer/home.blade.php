<div class="space-y-5" wire:poll.60s>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-[15px] text-neutral-600">{{ __('Assalam-o-Alaikum') }},</p>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight text-ink sm:text-[1.9rem]">{{ $customer->full_name }}</h1>
                @if($customer->kyc_status->value === 'passed')<x-badge tone="green" class="px-2.5 py-1"><x-icon name="check-circle" class="h-4 w-4"/>{{ __('KYC Passed') }}</x-badge>@else<x-status :value="$customer->kyc_status"/>@endif
            </div>
            <p class="mt-1 text-sm text-neutral-500">CIF: <bdi>{{ \Illuminate\Support\Str::after($customer->cif_no, 'CIF-') }}</bdi> <span class="mx-2 text-neutral-300">|</span> {{ __('Member since :d', ['d' => $customer->created_at->format('d M Y')]) }}</p>
        </div>
        <x-verse/>
    </div>

    {{-- Next step: one primary card that changes with the customer's state --}}
    @php($tint = ['green' => ['border-brand-300 from-brand-50', 'bg-brand-100 text-brand-700', 'btn-primary'], 'amber' => ['border-amber-300 from-amber-50', 'bg-amber-100 text-amber-600', 'btn bg-amber-400 text-ink hover:bg-amber-500 focus:ring-amber-500'], 'red' => ['border-red-300 from-red-50', 'bg-red-100 text-red-600', 'btn-danger'], 'blue' => ['border-sky-300 from-sky-50', 'bg-sky-100 text-sky-600', 'btn-primary']][$next['tone']] ?? ['border-brand-300 from-brand-50', 'bg-brand-100 text-brand-700', 'btn-primary'])
    <section class="rounded-2xl border bg-gradient-to-r {{ $tint[0] }} to-white p-5 shadow-sm" aria-label="{{ __('Your next step') }}">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <span class="hidden h-16 w-16 shrink-0 items-center justify-center rounded-full sm:flex {{ $tint[1] }}"><x-icon :name="str_contains($next['url'], '/pay') ? 'calendar' : ($next['tone'] === 'red' ? 'alert-circle' : 'clipboard')" class="h-8 w-8"/></span>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wider text-neutral-600">{{ __('Your next step') }}</p>
                <h2 class="mt-1 text-xl font-bold text-ink">{{ $next['title'] }}</h2>
                <p class="mt-1 text-[15px] text-neutral-700"><bdi>{{ $next['body'] }}</bdi></p>
            </div>
            <a href="{{ $next['url'] }}" wire:navigate class="{{ $tint[2] }} shrink-0 px-6 py-3 text-base">{{ $next['label'] }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a>
        </div>
        @if($tracked)
            <div class="mt-4 border-t border-neutral-200/70 pt-3">
                <p class="mb-2 text-xs text-neutral-500">{{ $tracked->product->localizedName() }} · <bdi>{{ $tracked->application_no }}</bdi></p>
                @include('livewire.customer.applications._stepper', ['app' => $tracked])
            </div>
        @endif
    </section>

    @if($wallet && Route::has('app.wallet'))
        {{-- Wallet card (ref: docs/ui-references/customer-dashboard.png); tenant colour tints the gradient. --}}
        <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-lg sm:p-6" style="background: linear-gradient(135deg, #052414 0%, #0d4229 55%, {{ $currentTenant->primary_color ?? '#15803d' }} 160%)">
            <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:url('/images/ui/pattern.svg')] [background-size:80px] [mask-image:linear-gradient(90deg,transparent_40%,#000)]" aria-hidden="true"></div>
            <img src="/images/ui/mosque-gold.svg" alt="" aria-hidden="true" class="pointer-events-none absolute -bottom-1 end-0 hidden w-[44%] max-w-md select-none md:block rtl:-scale-x-100">
            <div class="relative flex items-start justify-between gap-3">
                <div><p class="text-xs font-medium uppercase tracking-widest text-white/70">{{ __('Wallet balance') }}</p>
                    <p class="mt-1 text-3xl font-bold tabular sm:text-4xl">{{ \App\Support\Money::format($wallet->availableBalance()) }}</p>
                    <p class="mt-1 text-xs text-white/65"><bdi>{{ $wallet->wallet_no }}</bdi></p>
                    @if(\App\Support\Money::isPositive($wallet->held_balance))<p class="text-xs text-white/70">{{ __('On hold') }}: {{ \App\Support\Money::format($wallet->held_balance) }}</p>@endif</div>
                <span @class(['relative z-10 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold shadow-sm', 'bg-white text-brand-800' => $wallet->status->value === 'active', 'bg-amber-100 text-amber-800' => $wallet->status->value !== 'active'])>@if($wallet->status->value === 'active')<span class="h-2 w-2 rounded-full bg-brand-600"></span>@else<x-icon name="lock" class="h-3.5 w-3.5"/>@endif{{ __($wallet->status->label()) }}</span>
            </div>
            <div class="relative mt-5 grid grid-cols-4 gap-2 text-center text-xs sm:gap-3 sm:text-sm md:w-[58%]">
                @foreach([['app.wallet.topup', 'plus', __('Top up')], ['app.wallet.transfer', 'arrows', __('Send')], ['app.pay', 'qr', __('Pay')], ['app.wallet.bills', 'bolt', __('Bills')]] as [$r, $i, $l])
                    <a href="{{ route($r) }}" wire:navigate class="flex flex-col items-center gap-1.5 rounded-xl border border-white/10 bg-white/10 py-3 font-medium transition hover:bg-white/20"><x-icon :name="$i" class="h-6 w-6"/>{{ $l }}</a>
                @endforeach
            </div>
        </div>
    @endif

    @if($banners->isNotEmpty())
    <div class="grid gap-4 md:grid-cols-2">
        @foreach($banners as $b)
            @php($tone = $loop->odd ? 'blue' : 'green')
            <div @class(['flex items-center gap-4 rounded-xl border px-4 py-3.5', 'border-sky-200 bg-sky-50/80 text-sky-900' => $tone === 'blue', 'border-brand-200 bg-brand-50/80 text-brand-900' => $tone === 'green'])>
                <span @class(['flex h-11 w-11 shrink-0 items-center justify-center rounded-full', 'bg-white text-sky-700' => $tone === 'blue', 'bg-white text-brand-700' => $tone === 'green'])><x-icon :name="$tone === 'blue' ? 'bell' : 'bank'" class="h-6 w-6"/></span>
                <div class="min-w-0"><p class="font-semibold">{{ app()->getLocale() === 'ur' && $b->title_ur ? $b->title_ur : $b->title }}</p>
                    <p class="text-sm opacity-80">{{ app()->getLocale() === 'ur' && $b->body_ur ? $b->body_ur : $b->body }}</p></div>
            </div>
        @endforeach
    </div>
    @endif

    @foreach($actionable as $a)
        <section class="card flex flex-col gap-4 border-s-4 border-s-red-400 p-4 sm:flex-row sm:items-center sm:p-5" aria-label="{{ __('Action needed') }}">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600"><x-icon name="alert-circle" class="h-6 w-6"/></span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-ink">{{ __('Action needed') }}</p>
                <p class="mt-0.5 truncate text-sm font-medium text-neutral-800">{{ $a->product->localizedName() }} · <bdi>{{ $a->application_no }}</bdi></p>
                <p class="text-sm text-neutral-500">
                    @switch($a->status->value)
                        @case('draft') {{ __('Draft – finish and submit your application') }} @break
                        @case('returned') {{ __('Returned – please respond to the objection') }} @break
                        @case('approved') {{ __('Approved – review and acknowledge your Key Fact Statement') }} @break
                        @case('kfs_acknowledged') {{ __('Sign your financing documents') }} @break
                        @case('cooling_off') {{ __('Cooling-off ends :t – you may cancel free of charge until then', ['t' => $a->cooling_off_ends_at?->format('d M H:i')]) }} @break
                    @endswitch
                </p>
            </div>
            <a href="{{ $a->status->value === 'draft' ? route('app.apply', $a->product_id).'?application='.$a->id : route('app.applications.show', $a) }}" wire:navigate class="btn-primary bg-brand-800 px-6 hover:bg-brand-900">{{ __('Open') }}</a>
        </section>
    @endforeach

    <x-card :title="__('My financing')">
        <x-slot:actions><a href="{{ route('app.financing') }}" wire:navigate class="link inline-flex items-center gap-1 text-sm">{{ __('View all') }}<x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></a></x-slot:actions>
        @forelse($loans as $loan)
            @php($next = $loan->nextInstalment())
            @php($total = $loan->instalments->count())
            @php($paidN = $loan->instalments->filter(fn ($i) => $i->status->value === 'paid')->count())
            <a href="{{ route('app.financing.show', $loan) }}" wire:navigate class="mb-3 flex items-center gap-4 rounded-xl border border-neutral-200 p-4 transition last:mb-0 hover:border-brand-300 hover:shadow-sm">
                <x-product-art :category="$loan->product->category" class="hidden h-14 w-14 rounded-full sm:flex"/>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2"><p class="text-base font-semibold text-ink">{{ $loan->product->localizedName() }}</p><x-status :value="$loan->status"/></div>
                    <div class="mt-1 grid grid-cols-2 gap-2 text-sm">
                        <div><p class="text-neutral-500">{{ __('Outstanding') }}</p><p class="font-semibold"><x-money :amount="$loan->totalOutstanding()"/></p></div>
                        @if($next)<div><p class="text-neutral-500">{{ __('Next instalment') }}</p><p class="font-semibold"><x-money :amount="\App\Support\Money::sub(\App\Support\Money::add($next->total_due, $next->late_charge_due), $next->total_paid)"/> · {{ $next->due_date->format('d M') }}</p></div>@endif
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-neutral-100"><div class="h-2 rounded-full bg-brand-600" style="width: {{ $total ? round($paidN / $total * 100) : 0 }}%"></div></div>
                    <div class="mt-1.5 flex justify-between text-xs text-neutral-500"><span>{{ __(':p of :t instalments paid', ['p' => $paidN, 't' => $total]) }}</span><span>{{ __(':n remaining', ['n' => $total - $paidN]) }}</span></div>
                </div>
                <x-icon name="chevron-right" class="hidden h-5 w-5 shrink-0 text-neutral-400 sm:block rtl:rotate-180"/>
            </a>
        @empty
            <x-empty icon="wallet" :title="__('No active financing')" :description="__('Explore Shariah-compliant products for bikes, rickshaws, laptops, solar and more.')">
                <a href="{{ route('app.products') }}" wire:navigate class="btn-primary bg-brand-800 hover:bg-brand-900">{{ __('Browse products') }}</a>
            </x-empty>
        @endforelse
    </x-card>

    @if($inProgress->isNotEmpty())
        <x-card :title="__('Applications in progress')">
            <ul class="divide-y divide-neutral-100">
                @foreach($inProgress as $a)
                    <li class="flex items-center justify-between py-2.5 text-sm"><a href="{{ route('app.applications.show', $a) }}" wire:navigate class="link">{{ $a->product->localizedName() }} · {{ $a->application_no }}</a><x-status :value="$a->status"/></li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-card :title="__('Recent activity')">
        <x-slot:actions><a href="{{ route('app.transactions') }}" wire:navigate class="link inline-flex items-center gap-1 text-sm">{{ __('View all') }}<x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></a></x-slot:actions>
        @if($recent->isEmpty() && $payments->isEmpty())
            <p class="text-sm text-neutral-500">{{ __('No transactions yet.') }}</p>
        @else
            @php($txIcon = ['topup' => 'plus', 'transfer_out' => 'arrows', 'transfer_in' => 'arrow-right', 'bill_payment' => 'document-text', 'merchant_payment' => 'qr', 'instalment_payment' => 'cash', 'disbursement_credit' => 'bank', 'withdrawal' => 'bank'])
            <ul class="-my-2 divide-y divide-neutral-100 text-sm">
                @foreach($recent as $t)
                    <li class="flex items-center gap-3 py-2.5">
                        <span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', 'bg-brand-50 text-brand-700' => $t->isCredit(), 'bg-neutral-100 text-neutral-600' => ! $t->isCredit()])><x-icon :name="$txIcon[$t->type->value] ?? 'arrows'" class="h-4 w-4"/></span>
                        <div class="min-w-0 flex-1"><p class="truncate font-medium text-ink">{{ $t->description ?? __($t->type->label()) }}</p><p class="flex items-center gap-2 text-xs text-neutral-500 sm:hidden">{{ $t->created_at->format('d M Y H:i') }} <x-status :value="$t->status"/></p></div>
                        <span class="hidden w-32 shrink-0 text-xs text-neutral-500 tabular sm:block">{{ $t->created_at->format('d M Y H:i') }}</span>
                        <span class="hidden w-24 shrink-0 sm:block"><x-status :value="$t->status"/></span>
                        <span @class(['w-28 shrink-0 text-end font-semibold tabular', 'text-brand-700' => $t->isCredit(), 'text-ink' => ! $t->isCredit()])>{{ $t->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($t->amount, false) }}</span>
                        @if(Route::has('app.wallet.receipt') && $t->status->value === 'succeeded')<a href="{{ route('app.wallet.receipt', $t) }}" class="shrink-0 rounded-md p-1 text-neutral-400 hover:bg-neutral-100 hover:text-ink" aria-label="{{ __('Receipt') }}"><x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></a>@else<span class="w-6 shrink-0"></span>@endif</li>
                @endforeach
                @foreach($payments as $p)
                    <li class="flex items-center gap-3 py-2.5">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-600"><x-icon name="cash" class="h-4 w-4"/></span>
                        <div class="min-w-0 flex-1"><p class="truncate font-medium text-ink">{{ __('Instalment payment') }} · <bdi>{{ $p->reference }}</bdi></p><p class="flex items-center gap-2 text-xs text-neutral-500 sm:hidden">{{ $p->created_at->format('d M Y H:i') }} <x-status :value="$p->status"/></p></div>
                        <span class="hidden w-32 shrink-0 text-xs text-neutral-500 tabular sm:block">{{ $p->created_at->format('d M Y H:i') }}</span>
                        <span class="hidden w-24 shrink-0 sm:block"><x-status :value="$p->status"/></span>
                        <span class="w-28 shrink-0 text-end font-semibold tabular">{{ \App\Support\Money::format($p->amount, false) }}</span>
                        @if(Route::has('app.receipt') && $p->status->value === 'succeeded')<a href="{{ route('app.receipt', $p) }}" class="shrink-0 rounded-md p-1 text-neutral-400 hover:bg-neutral-100 hover:text-ink" aria-label="{{ __('Receipt') }}"><x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></a>@else<span class="w-6 shrink-0"></span>@endif</li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
