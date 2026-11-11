<div class="space-y-4">
    <x-page-header :title="__('Withdraw to bank')" :subtitle="__('Sent via Raast to your own bank account')" :back="route('app.wallet')"/>
    @include('livewire.customer.wallet._header')
    {{-- Money actions are disabled up front when the wallet cannot transact (reason shown in the header). --}}
    <fieldset class="min-w-0 space-y-4 disabled:opacity-60" @disabled($this->blockReason())>
    @if($adding || $accounts->isEmpty())
        <x-card :title="__('Link a bank account')">
            <form wire:submit="link" class="grid gap-4 sm:grid-cols-2">
                <x-select :label="__('Bank')" wire:model="newBank" :options="array_combine($banks, $banks)"/>
                <x-input :label="__('IBAN')" wire:model="newIban" placeholder="PK36MEZN0001234567890123"/>
                <p class="text-xs text-neutral-500 sm:col-span-2">{{ __('We fetch the account title via Raast to confirm it is in your name (simulated).') }}</p>
                <div class="flex gap-2 sm:col-span-2"><button class="btn-primary">{{ __('Verify & link') }}</button>@if($accounts->isNotEmpty())<button type="button" wire:click="$set('adding', false)" class="btn-secondary">{{ __('Cancel') }}</button>@endif</div>
            </form>
        </x-card>
    @else
        <x-card>
            <form wire:submit="submit" class="space-y-4">
                <x-select :label="__('To account')" wire:model="account" :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->bank_name.' ****'.substr($a->iban, -4).' – '.$a->account_title])->all()"/>
                <button type="button" wire:click="$set('adding', true)" class="text-xs link">+ {{ __('Link another account') }}</button>
                <x-input :label="__('Amount (PKR)')" type="number" step="1" wire:model="amount"/>
                <x-input :label="__('Wallet PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="pin" autocomplete="off"/>
                @include('livewire.customer.wallet._scenario')
                <button class="btn-primary" wire:loading.attr="disabled" @disabled(! $w->isActive())>{{ __('Withdraw') }}</button>
            </form>
        </x-card>
    @endif
    </fieldset>
    @include('livewire.customer.wallet._result')
</div>
