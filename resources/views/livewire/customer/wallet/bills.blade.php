<div class="space-y-4">
    <x-page-header :title="__('Pay utility bills')" :subtitle="__('Electricity, gas, telephone and water via 1Bill')" :back="route('app.wallet')"/>
    @include('livewire.customer.wallet._header')
    {{-- Money actions are disabled up front when the wallet cannot transact (reason shown in the header). --}}
    <fieldset class="min-w-0 space-y-4 disabled:opacity-60" @disabled($this->blockReason())>
    <x-card>
        <form wire:submit="inquire" class="grid gap-4 sm:grid-cols-3 sm:items-end">
            <x-select :label="__('Company')" wire:model="biller" :options="$billers"/>
            <x-input :label="__('Reference / consumer number')" wire:model="consumerRef" inputmode="numeric"/>
            <button class="btn-secondary" wire:loading.attr="disabled">{{ __('Fetch bill') }}</button>
        </form>
        @if($bill)
            <div class="mt-4 rounded-lg border border-neutral-200 p-4">
                @if(($bill['status'] ?? '') === 'paid')
                    <x-alert tone="green">{{ __('This bill is already paid.') }}</x-alert>
                @else
                    <x-dl :items="[__('Company') => $bill['biller'] ?? '', __('Consumer') => $bill['consumer_name'] ?? '', __('Amount due') => \App\Support\Money::format($bill['amount_due'] ?? 0), __('Due date') => $bill['due_date'] ?? '']"/>
                    <div class="mt-4 space-y-3 sm:max-w-sm">
                        <x-input :label="__('Wallet PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="pin" autocomplete="off"/>
                        @include('livewire.customer.wallet._scenario')
                        <button wire:click="pay" class="btn-primary" wire:loading.attr="disabled">{{ __('Pay :a', ['a' => \App\Support\Money::format($bill['amount_due'] ?? 0)]) }}</button>
                    </div>
                @endif
            </div>
        @endif
        <p class="mt-3 text-xs text-neutral-500">{{ __('Demo tip: any 8–14 digit number returns a simulated bill; numbers ending in 00 are already paid.') }}</p>
    </x-card>
    </fieldset>
    @include('livewire.customer.wallet._result')
    @if($history->isNotEmpty())
        <x-card :title="__('Recent bill payments')">
            <ul class="divide-y divide-neutral-100 text-sm">@foreach($history as $b)<li class="flex justify-between py-2"><span>{{ $b->biller_name }} · {{ $b->consumer_ref }}</span><span class="flex items-center gap-2"><x-money :amount="$b->amount"/><x-status :value="$b->status"/></span></li>@endforeach</ul>
        </x-card>
    @endif
</div>
