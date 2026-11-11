<div>
    <x-page-header :title="$order->reference" :back="route('ops.payments.index')" :subtitle="$order->purpose->label().' · '.config('integrations.providers.'.$order->provider.'.name', $order->provider).' · '.ucfirst($order->direction)">
        <x-slot:actions>
            <x-status :value="$order->status"/>
            @if($open)<button wire:click="inquire" class="btn-secondary btn-sm">{{ __('Status inquiry') }}</button>@endif
        </x-slot:actions>
    </x-page-header>
    @if($order->mode === 'demo')<x-alert tone="amber" class="mb-4">{{ __('SIMULATED provider. No real money moved. Scenario: :s', ['s' => config('integrations.payment_scenarios.'.$order->scenario, $order->scenario)]) }}</x-alert>@endif

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card class="lg:col-span-2" :title="__('Order')">
            <x-dl :items="[
                __('Amount') => \App\Support\Money::format($order->amount), __('Status') => $order->status->label(),
                __('Provider reference') => $order->provider_ref, __('Idempotency key') => $order->idempotency_key,
                __('Counterparty') => trim($order->counterparty_name.' '.$order->counterparty_account.' '.$order->counterparty_bank),
                __('Settlement account') => $order->bankAccount?->name, __('Attempts') => $order->attempts, __('Callbacks received') => $order->callback_count,
                __('Failure') => trim(($order->failure_code ?? '').' '.($order->failure_message ?? '')), __('Reconciliation') => \Illuminate\Support\Str::headline($order->reconciliation_status),
                __('Created') => $order->created_at->format('d M Y H:i:s'), __('Settled') => $order->settled_at?->format('d M Y H:i:s'),
                __('Business object') => $payable ? class_basename($payable).' '.($payable->reference ?? $payable->payment_no ?? '#'.$payable->getKey()) : null,
            ]"/>
        </x-card>
        <div class="space-y-4">
            @if($demo)
                <x-card :title="__('Demo tools')" :subtitle="__('Simulate the provider. Business rules still apply.')">
                    <div class="flex flex-wrap gap-2">
                        @if($open)
                            <button wire:click="simulate('succeeded')" class="btn-primary btn-sm">{{ __('Callback: succeeded') }}</button>
                            <button wire:click="simulate('failed')" class="btn-danger btn-sm">{{ __('Callback: failed') }}</button>
                        @endif
                        @if($order->status === \App\Enums\PaymentOrderStatus::Succeeded)
                            <button wire:click="simulate('reversed')" wire:confirm="{{ __('Simulate a provider reversal? Compensating entries will be posted.') }}" class="btn-secondary btn-sm">{{ __('Callback: reversed') }}</button>
                        @endif
                        <button wire:click="duplicate" class="btn-secondary btn-sm">{{ __('Re-send last callback (duplicate)') }}</button>
                    </div>
                </x-card>
            @endif
            <x-card :title="__('Timeline')">
                <ol class="space-y-2 text-xs">
                    @foreach($audit as $a)
                        <li class="flex gap-2"><span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-600"></span><span><strong>{{ $a->action }}</strong> · {{ $a->created_at->format('d M H:i:s') }}@if($a->actor) · {{ $a->actor->name }}@endif</span></li>
                    @endforeach
                </ol>
            </x-card>
        </div>
    </div>

    <x-card class="mt-4" :padding="false" :title="__('Callbacks')">
        <x-table>
            <x-slot:head><x-th>{{ __('Received') }}</x-th><x-th>{{ __('Event id') }}</x-th><x-th>{{ __('Reported status') }}</x-th><x-th align="right">{{ __('Reported amount') }}</x-th><x-th>{{ __('Signature') }}</x-th><x-th>{{ __('Result') }}</x-th></x-slot:head>
            @forelse($callbacks as $cb)
                <tr><td class="text-xs">{{ $cb->received_at->format('d M H:i:s') }}</td><td class="text-xs tabular">{{ $cb->event_id }}</td><td class="text-xs">{{ $cb->reported_status }}</td>
                    <td class="text-right text-xs">{{ $cb->reported_amount !== null ? \App\Support\Money::format($cb->reported_amount) : '—' }}</td>
                    <td class="text-xs">{{ $cb->signature_valid ? __('valid') : __('invalid') }}</td><td><x-status :value="$cb->result"/>@if($cb->error)<div class="text-[11px] text-neutral-500">{{ $cb->error }}</div>@endif</td></tr>
            @empty
                <x-empty :colspan="6" :title="__('No callbacks yet')" :description="__('A pending order waits for the provider callback or a status inquiry.')"/>
            @endforelse
        </x-table>
    </x-card>

    <x-card class="mt-4" :title="__('Integration log (masked)')">
        @forelse($logs as $l)
            <details class="border-b border-neutral-100 py-2 text-xs last:border-0">
                <summary class="cursor-pointer"><strong>{{ $l->operation }}</strong> · <x-status :value="$l->status"/> · {{ $l->duration_ms }} ms · {{ $l->created_at->format('d M H:i:s') }} · {{ __('corr') }} {{ $l->correlation_id }}</summary>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <pre class="overflow-x-auto rounded bg-neutral-50 p-2">{{ json_encode($l->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    <pre class="overflow-x-auto rounded bg-neutral-50 p-2">{{ json_encode($l->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </details>
        @empty
            <p class="text-sm text-neutral-500">{{ __('No provider calls logged for this order.') }}</p>
        @endforelse
    </x-card>
</div>
