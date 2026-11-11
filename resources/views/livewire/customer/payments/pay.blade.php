<div class="space-y-5" @if($pending && in_array($pending->status->value, ['pending'])) wire:poll.5s @endif>
    <x-page-header :title="__('Pay Instalment')" :subtitle="__('Make a secure payment towards your financing. Choose your preferred method and confirm to proceed.')"/>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror

    @if($loans->isEmpty())
        <div class="card"><x-empty :title="__('Nothing to pay')" :description="__('You have no active financing.')"/></div>
    @elseif($pending)
        <x-card :title="__('Payment :r', ['r' => $pending->reference])">
            <div class="flex items-center justify-between"><p class="text-2xl font-bold tabular"><x-money :amount="$pending->amount"/></p><x-status :value="$pending->status"/></div>
            @if($pending->status->value === 'pending')
                <div class="mt-4 rounded-xl border-2 border-dashed border-amber-400 bg-amber-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-800">{{ __('SIMULATED – not a live payment instruction') }}</p>
                    @switch($pending->channel->value)
                        @case('qr_iban')
                            <div class="mt-3 flex flex-col items-center gap-2 sm:flex-row sm:items-start">
                                <div class="grid h-32 w-32 grid-cols-8 gap-0.5 rounded bg-white p-2" aria-label="Simulated QR">
                                    @foreach(str_split(substr(hash('sha256', $pending->reference), 0, 64)) as $ch)<span class="{{ hexdec($ch) % 2 ? 'bg-ink' : 'bg-white' }}"></span>@endforeach
                                </div>
                                <div class="text-sm"><p>{{ __('Scan with any Raast-enabled bank app, or pay to the IBAN alias:') }}</p>
                                    <p class="mt-1 font-mono">{{ $collectionIban }}</p><p class="text-neutral-600">{{ __('Reference') }}: <span class="font-mono">{{ $selected?->account_no }}</span></p></div>
                            </div>
                            @break
                        @case('one_bill')
                            <p class="mt-2 text-sm">{{ __('Pay at any bank branch, ATM or banking app under "1Bill – Invoice / Fee" using consumer number:') }}</p>
                            <p class="mt-1 font-mono text-lg">{{ preg_replace('/\D/', '', $selected?->account_no ?? '') }}</p>
                            @break
                        @case('easypaisa') @case('jazzcash')
                            <p class="mt-2 text-sm">{{ __('Approve the payment request in your :w app on :m.', ['w' => __(ucfirst($pending->channel->value)), 'm' => auth()->user()->mobile]) }}</p>
                            @break
                        @default
                            <p class="mt-2 text-sm">{{ __('A Raast request-to-pay was sent. Approve it in your bank app (Meezan, HBL, UBL, MCB, Bank Alfalah…).') }}</p>
                    @endswitch
                    <p class="mt-2 text-xs text-neutral-600">{{ __('Provider reference') }}: {{ $pending->paymentOrder?->provider_ref }}</p>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button wire:click="inquire" class="btn-secondary btn-sm">{{ __('Check status') }}</button>
                    @if($this->demo())
                        <button wire:click="simulate('succeeded')" class="btn-primary btn-sm">{{ __('Simulate: paid') }}</button>
                        <button wire:click="simulate('failed')" class="btn-danger btn-sm">{{ __('Simulate: failed') }}</button>
                    @endif
                </div>
            @elseif($pending->status->value === 'succeeded')
                <x-alert tone="green" class="mt-3">{{ __('Payment received and applied to your instalments.') }}</x-alert>
                <a href="{{ route('app.receipt', $pending) }}" target="_blank" class="btn-secondary btn-sm mt-3">{{ __('View receipt') }}</a>
            @else
                <x-alert tone="red" class="mt-3">{{ $pending->failure_reason ?? __('The payment was not completed.') }}</x-alert>
            @endif
            <button wire:click="newPayment" class="btn-ghost btn-sm mt-3">{{ __('Make another payment') }}</button>
        </x-card>
    @else
        <div class="card p-5 sm:p-6">
            <form wire:submit="pay" class="space-y-5">
                @php($sel = $loans->firstWhere('id', (int) $loan))
                <div>
                    <label for="loan" class="field-label">{{ __('Financing') }}</label>
                    <div class="relative">
                        @if($sel)<x-product-art :category="$sel->product->category" :tile="false" class="pointer-events-none absolute start-3 top-1/2 h-7 w-7 -translate-y-1/2"/>@endif
                        <select id="loan" wire:model.live="loan" class="field-input py-2.5 ps-12 text-[15px]">@foreach($loans as $l)<option value="{{ $l->id }}">{{ $l->account_no }} – {{ $l->product->localizedName() }}</option>@endforeach</select>
                    </div>
                </div>
                @if($next)
                    <div class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                        <x-icon name="calendar" class="h-5 w-5 shrink-0 text-amber-600"/>
                        <p>{{ __('Instalment #:n due :d', ['n' => $next->number, 'd' => $next->due_date->format('d M Y')]) }} <span class="mx-1">·</span> {{ __('Total outstanding') }} <x-money class="font-semibold" :amount="$selected->totalOutstanding()"/></p>
                    </div>
                @endif
                <x-input :label="__('Amount (PKR)')" type="number" step="0.01" wire:model="amount" class="[&_input]:py-2.5 [&_input]:text-lg [&_input]:font-semibold"/>
                <fieldset>
                    <legend class="mb-2 text-[15px] font-semibold text-ink">{{ __('Choose payment method') }}</legend>
                    {{-- Generic channel icons – no third-party logos. --}}
                    @php($meta = ['wallet' => ['wallet', __('Pay from your wallet balance')], 'raast' => ['bolt', __('Pay via your bank using Raast')], 'qr_iban' => ['qr', __('Scan QR or pay via IBAN alias')],
                        'one_bill' => ['building', __('Pay via 1Bill or 1LINK channel')], 'easypaisa' => ['phone', __('Pay using your Easypaisa account')], 'jazzcash' => ['phone', __('Pay using your JazzCash account')]])
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach(\App\Livewire\Customer\Payments\Pay::CHANNELS as $k => $l)
                            @php($disabled = $k === 'wallet' && ! $wallet?->isActive())
                            <label wire:key="ch-{{ $k }}" @class(['flex cursor-pointer items-center gap-4 rounded-xl border px-4 py-3 transition', 'border-brand-600 bg-brand-50 ring-1 ring-brand-600' => $channel === $k, 'border-neutral-200 hover:border-brand-300' => $channel !== $k, 'cursor-not-allowed opacity-50' => $disabled])>
                                <input type="radio" wire:model.live="channel" value="{{ $k }}" @disabled($disabled) class="h-4 w-4 accent-brand-700">
                                <span @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-lg', 'bg-white text-brand-700 ring-1 ring-brand-200' => $channel === $k, 'bg-neutral-50 text-neutral-600' => $channel !== $k])><x-icon :name="$meta[$k][0] ?? 'cash'" class="h-5 w-5"/></span>
                                <span class="min-w-0"><span class="block font-semibold text-ink">{{ __($l) }}</span>
                                    <span class="block text-xs text-neutral-500">@if($k === 'wallet' && $wallet){{ __('Available') }} <bdi>{{ \App\Support\Money::format($wallet->availableBalance()) }}</bdi>@else{{ $meta[$k][1] ?? '' }}@endif</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                @if($channel === 'wallet')
                    <x-input :label="__('Wallet PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="pin" autocomplete="off" icon="lock"/>
                @elseif($this->demo())
                    <x-select :label="__('Simulation scenario (demo only)')" wire:model="scenario" :options="$scenarios"/>
                @endif
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <button class="btn-primary bg-brand-800 px-8 py-3 text-base hover:bg-brand-900" wire:loading.attr="disabled">{{ __('Pay instalment') }}<x-icon name="arrow-right" class="h-4 w-4 rtl:rotate-180"/></button>
                    <p class="flex items-center gap-2 text-xs text-neutral-500"><x-icon name="shield" class="h-6 w-6 shrink-0 text-brand-700"/>{{ __('Payments are processed through the institution\'s regulated payment partners and posted to your financing.') }}</p>
                </div>
            </form>
        </div>
    @endif
</div>
