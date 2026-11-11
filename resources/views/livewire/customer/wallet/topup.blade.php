<div class="space-y-4">
    <x-page-header :title="__('Top up wallet')" :back="route('app.wallet')"/>
    @include('livewire.customer.wallet._header')
    {{-- Money actions are disabled up front when the wallet cannot transact (reason shown in the header). --}}
    <fieldset class="min-w-0 space-y-4 disabled:opacity-60" @disabled($this->blockReason())>
    <x-card>
        <form wire:submit="submit" class="space-y-4">
            <x-input :label="__('Amount (PKR)')" type="number" step="1" wire:model="amount" :hint="__('Per transaction limit :l', ['l' => \App\Support\Money::format($w->per_transaction_limit)])"/>
            <div class="flex flex-wrap gap-2">@foreach([1000, 5000, 10000, 20000] as $q)<button type="button" wire:click="$set('amount', '{{ $q }}')" class="btn-secondary btn-sm">{{ number_format($q) }}</button>@endforeach</div>
            <x-select :label="__('From')" wire:model.live="source" :options="collect(\App\Livewire\Customer\Wallet\TopUp::SOURCES)->map(fn ($l) => __($l))->all()"/>
            @if(in_array($source, ['raast', 'sponsor_bank']))
                <x-select :label="__('Your bank')" wire:model="bank" :options="array_combine($banks, $banks)"/>
                <p class="text-xs text-neutral-500">{{ __('You will authorise the payment in your bank app (simulated).') }}</p>
            @endif
            @include('livewire.customer.wallet._scenario')
            <button class="btn-primary w-full sm:w-auto" wire:loading.attr="disabled" @disabled(! $w->isActive())>{{ __('Top up') }}</button>
        </form>
    </x-card>
    </fieldset>
    @include('livewire.customer.wallet._result')
</div>
