<div class="space-y-5">
    <x-page-header :title="__('My Wallet')" :subtitle="__('Manage your wallet, make payments and view your transaction history.')"/>
    @if(! $w)
        <div class="card"><x-empty :title="__('No wallet yet')" :description="__('Your wallet is created automatically once your identity is verified.')"><a href="{{ route('app.onboarding') }}" wire:navigate class="btn-primary btn-sm">{{ __('Complete verification') }}</a></x-empty></div>
    @else
        {{-- Wallet card (ref: docs/ui-references/customer-wallet.png) --}}
        <div class="relative overflow-hidden rounded-2xl p-5 text-white shadow-lg sm:p-6" style="background: linear-gradient(135deg, #052414 0%, #0d4229 55%, {{ $currentTenant->primary_color ?? '#15803d' }} 160%)">
            <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:url('/images/ui/pattern.svg')] [background-size:80px] [mask-image:linear-gradient(90deg,transparent_45%,#000)]" aria-hidden="true"></div>
            <img src="/images/ui/wallet-gold.svg" alt="" aria-hidden="true" class="pointer-events-none absolute bottom-2 end-4 hidden w-44 select-none lg:block">
            <div class="relative flex flex-wrap justify-between gap-4 lg:pe-52">
                <div><p class="text-xs font-medium uppercase tracking-widest text-white/75">{{ __('Available balance') }}</p>
                    <p class="mt-1 text-3xl font-bold tabular sm:text-4xl">{{ \App\Support\Money::format($w->availableBalance()) }}</p>
                    <p class="mt-1 text-sm text-white/75">{{ __('Ledger balance') }}: <span class="font-semibold text-white">{{ \App\Support\Money::format($w->balance) }}</span> <span class="mx-2 text-white/40">|</span> {{ __('On hold') }}: {{ \App\Support\Money::format($w->held_balance) }}</p></div>
                <div class="text-end"><span @class(['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold shadow-sm', 'bg-white text-brand-800' => $w->status->value === 'active', 'bg-amber-100 text-amber-800' => $w->status->value !== 'active'])>@if($w->status->value === 'active')<span class="h-2 w-2 rounded-full bg-brand-600"></span>@else<x-icon name="lock" class="h-3.5 w-3.5"/>@endif{{ __($w->status->label()) }}</span>
                    <p class="mt-3 text-sm text-white/75"><bdi>{{ $w->wallet_no }}</bdi></p></div>
            </div>
            <div class="relative mt-5 grid grid-cols-3 gap-2 text-center text-xs sm:grid-cols-6 sm:text-sm lg:me-52">
                @php($blocked = $this->blockReason())
                @foreach([['app.wallet.topup','plus',__('Top up'),true],['app.wallet.transfer','arrows',__('Send'),true],['app.wallet.withdraw','bank',__('Withdraw'),true],['app.wallet.bills','document-text',__('Bills'),true],['app.wallet.merchant','qr',__('Pay merchant'),true],['app.wallet.settings','cog',__('Settings'),false]] as [$r,$i,$l,$money])
                    @if($money && $blocked)
                        <span class="flex cursor-not-allowed flex-col items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 py-3 opacity-40" title="{{ $blocked }}" aria-disabled="true"><x-icon :name="$i" class="h-6 w-6"/>{{ $l }}</span>
                    @else
                        <a href="{{ route($r) }}" wire:navigate class="flex flex-col items-center gap-1.5 rounded-xl border border-white/15 bg-white/10 py-3 font-medium transition hover:bg-white/20"><x-icon :name="$i" class="h-6 w-6"/>{{ $l }}</a>
                    @endif
                @endforeach
            </div>
        </div>
        @if($blocked && ! $missing)
            @php([$actionLabel, $actionUrl] = $this->blockAction())
            <x-alert :title="__('Payments are unavailable')"><p>{{ $blocked }}</p><a href="{{ $actionUrl }}" wire:navigate class="mt-1 inline-block font-semibold underline">{{ $actionLabel }}</a></x-alert>
        @endif
        @if($missing)
            <x-alert :title="__('Wallet not active yet')"><ul class="list-disc pl-5">@foreach($missing as $m)<li>{{ __($m) }}</li>@endforeach</ul></x-alert>
        @elseif($w->status->value === 'pending')
            <button wire:click="activate" class="btn-primary">{{ __('Activate wallet') }}</button>
        @endif
        @if(! $w->pin_hash)<x-alert tone="blue">{{ __('Set a wallet PIN to authorise payments.') }} <a href="{{ route('app.wallet.settings') }}" wire:navigate class="font-semibold underline">{{ __('Set PIN') }}</a></x-alert>@endif

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach([[__('Daily limit'), $w->daily_limit, __('Used today').': '.\App\Support\Money::format($usedToday), 'database', 'bg-brand-50 text-brand-700'],
                      [__('Per transaction'), $w->per_transaction_limit, null, 'bolt', 'bg-indigo-50 text-indigo-600'],
                      [__('Monthly limit'), $w->monthly_limit, __('Used this month').': '.\App\Support\Money::format($usedMonth), 'calendar', 'bg-brand-50 text-brand-700'],
                      [__('Maximum balance'), $w->max_balance, null, 'chart', 'bg-indigo-50 text-indigo-600']] as [$lbl, $amt, $sub, $ic, $chip])
                <div class="card @container flex items-center gap-4 p-4">
                    <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl @[13rem]:flex {{ $chip }}"><x-icon :name="$ic" class="h-6 w-6"/></span>
                    <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500">{{ $lbl }}</p><p class="mt-0.5 whitespace-nowrap font-bold text-ink tabular @[13rem]:text-lg">{{ \App\Support\Money::format($amt) }}</p>@if($sub)<p class="text-xs text-neutral-500">{{ $sub }}</p>@endif</div>
                </div>
            @endforeach
        </div>

        @php($pct = \App\Support\Money::isPositive($w->monthly_limit) ? min(100, (int) round((float) $usedMonth / (float) $w->monthly_limit * 100)) : 0)
        <div class="card flex flex-wrap items-center gap-x-5 gap-y-2 px-5 py-4" aria-label="{{ __('Monthly limit usage') }}">
            <span class="text-sm font-semibold text-ink">{{ __('Monthly limit usage') }}</span>
            <div class="h-2.5 min-w-40 flex-1 overflow-hidden rounded-full bg-neutral-100"><div @class(['h-full rounded-full', 'bg-brand-600' => $pct < 80, 'bg-amber-500' => $pct >= 80 && $pct < 100, 'bg-red-600' => $pct >= 100]) style="width: {{ $pct }}%"></div></div>
            <span class="text-sm text-neutral-600 tabular"><bdi>{{ \App\Support\Money::format($usedMonth) }} / {{ \App\Support\Money::format($w->monthly_limit) }}</bdi></span>
            <x-badge :tone="$pct >= 100 ? 'red' : ($pct >= 80 ? 'amber' : 'green')"><bdi>{{ $pct }}%</bdi></x-badge>
        </div>

        @if($pending->isNotEmpty())
            <x-card :title="__('Pending transactions')">
                @foreach($pending as $txn)@include('livewire.customer.wallet._result', ['txn' => $txn])@endforeach
            </x-card>
        @endif

        @php($txIcon = ['topup' => 'plus', 'transfer_out' => 'arrow-right', 'transfer_in' => 'arrow-right', 'bill_payment' => 'document-text', 'merchant_payment' => 'qr', 'merchant_receipt' => 'qr', 'instalment_payment' => 'cash', 'disbursement_credit' => 'bank', 'withdrawal' => 'bank', 'refund' => 'refresh', 'reversal' => 'refresh'])
        <x-card :title="__('Recent transactions')" :padding="false" :divider="false">
            <x-slot:actions><a href="{{ route('app.transactions') }}" wire:navigate class="link inline-flex items-center gap-1 text-sm">{{ __('Full history') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></a></x-slot:actions>
            @if($recent->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-neutral-500">{{ __('No transactions yet. Top up to get started.') }}</p>
            @else
                <x-table dense inset class="mt-2">
                    <x-slot:head><x-th>{{ __('Description') }}</x-th><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Date & time') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                    @foreach($recent as $t)
                        <tr wire:key="t-{{ $t->id }}">
                            <td><div class="flex items-center gap-3"><span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full', 'bg-brand-50 text-brand-700' => $t->isCredit(), 'bg-neutral-100 text-neutral-600' => ! $t->isCredit()])><x-icon :name="$txIcon[$t->type->value] ?? 'arrows'" @class(['h-4 w-4', 'rtl:rotate-180' => str_starts_with($t->type->value, 'transfer')])/></span><span class="font-medium text-ink">{{ $t->description ?? __($t->type->label()) }}</span></div></td>
                            <td class="text-neutral-500 tabular"><bdi>{{ $t->reference }}</bdi></td>
                            <td class="text-neutral-500 tabular">{{ $t->created_at->format('d M Y H:i') }}</td>
                            <td @class(['text-end font-semibold tabular', 'text-brand-700' => $t->isCredit(), 'text-red-600' => ! $t->isCredit()])><bdi>{{ $t->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($t->amount, false) }}</bdi></td>
                            <td><x-status :value="$t->status"/></td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
        </x-card>
        <div class="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-3 text-sm text-brand-900"><x-icon name="shield" class="h-6 w-6 shrink-0 text-brand-700"/>{{ __('Your wallet balance is held in a safeguarded account with the sponsor bank. Internal transfers move only the wallet ledger. (Demo: no real funds.)') }}</div>
    @endif
</div>
