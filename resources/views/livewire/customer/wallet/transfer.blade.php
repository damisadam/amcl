<div class="space-y-4">
    <x-page-header :title="__('Send money')" :subtitle="__('Instant transfer to another wallet at this institution')" :back="route('app.wallet')"/>
    @include('livewire.customer.wallet._header')
    {{-- Money actions are disabled up front when the wallet cannot transact (reason shown in the header). --}}
    <fieldset class="min-w-0 space-y-4 disabled:opacity-60" @disabled($this->blockReason())>
    <x-card>
        @if(! $confirming)
            <form wire:submit="review" class="space-y-4">
                <x-input :label="__('Recipient mobile number')" wire:model="mobile" inputmode="tel" placeholder="03001234567"/>
                <x-input :label="__('Amount (PKR)')" type="number" step="1" wire:model="amount"/>
                <x-input :label="__('Note (optional)')" wire:model="note" maxlength="100"/>
                <button class="btn-primary">{{ __('Continue') }}</button>
            </form>
        @else
            <div class="space-y-4">
                <div class="rounded-lg bg-neutral-50 p-4 text-sm">
                    <p>{{ __('Send') }} <strong class="tabular">{{ \App\Support\Money::format($amount) }}</strong> {{ __('to') }}</p>
                    <p class="mt-1 text-lg font-semibold">{{ $recipientName }}</p><p class="text-neutral-500">{{ $mobile }}</p>
                    @if($note)<p class="mt-1 text-neutral-600">“{{ $note }}”</p>@endif
                </div>
                <x-input :label="__('Wallet PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="pin" autocomplete="off"/>
                <div class="flex gap-2"><button wire:click="$set('confirming', false)" class="btn-secondary">{{ __('Back') }}</button>
                    <button wire:click="send" class="btn-primary" wire:loading.attr="disabled">{{ __('Confirm & send') }}</button></div>
            </div>
        @endif
    </x-card>
    </fieldset>
    @include('livewire.customer.wallet._result')
</div>
