{{-- Result panel for the last wallet transaction created on this screen. --}}
@if($txn)
    <div class="card p-4" @if(in_array($txn->status->value, ['pending', 'processing'])) wire:poll.4s @endif>
        <div class="flex items-center justify-between"><div><p class="text-sm font-semibold">{{ $txn->description ?? __($txn->type->label()) }}</p><p class="text-xs text-neutral-500">{{ $txn->reference }}</p></div>
            <div class="text-right"><p class="font-bold tabular"><x-money :amount="$txn->amount"/></p><x-status :value="$txn->status"/></div></div>
        @if($txn->failure_reason)<p class="mt-2 text-sm text-red-600">{{ $txn->failure_reason }}</p>@endif
        @if(in_array($txn->status->value, ['pending', 'processing']) && $txn->payment_order_id)
            <p class="mt-2 text-xs text-neutral-500">{{ __('Waiting for confirmation from :p (simulated).', ['p' => strtoupper((string) $txn->provider)]) }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button wire:click="checkStatus({{ $txn->id }})" class="btn-secondary btn-sm">{{ __('Check status') }}</button>
                @if($this->demo())
                    <button wire:click="simulateCallback({{ $txn->id }}, 'succeeded')" class="btn-primary btn-sm">{{ __('Simulate success') }}</button>
                    <button wire:click="simulateCallback({{ $txn->id }}, 'failed')" class="btn-danger btn-sm">{{ __('Simulate failure') }}</button>
                @endif
            </div>
        @elseif($txn->status->value === 'succeeded')
            <a href="{{ route('app.wallet.receipt', $txn) }}" target="_blank" class="mt-2 inline-block text-xs link">{{ __('View receipt') }}</a>
        @endif
    </div>
@endif
