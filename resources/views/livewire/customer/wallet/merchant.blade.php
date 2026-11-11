<div class="space-y-4">
    <x-page-header :title="__('Pay a merchant')" :subtitle="__('Scan the merchant QR or enter its merchant code')" :back="route('app.wallet')"/>
    @include('livewire.customer.wallet._header')
    {{-- Money actions are disabled up front when the wallet cannot transact (reason shown in the header). --}}
    <fieldset class="min-w-0 space-y-4 disabled:opacity-60" @disabled($this->blockReason())>
    <x-card>
        <form wire:submit="pay" class="space-y-4">
            <div class="flex items-end gap-2"><x-input class="flex-1" :label="__('Merchant code')" wire:model="code" wire:blur="lookup" placeholder="MRC-…"/>
                <button type="button" wire:click="lookup" class="btn-secondary">{{ __('Find') }}</button></div>
            @if($merchantName)<p class="text-sm">{{ __('Paying') }} <strong>{{ $merchantName }}</strong></p>@endif
            <x-input :label="__('Amount (PKR)')" type="number" step="1" wire:model="amount"/>
            <x-input :label="__('Wallet PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="pin" autocomplete="off"/>
            @include('livewire.customer.wallet._scenario')
            <button class="btn-primary" wire:loading.attr="disabled" @disabled(! $w->isActive())>{{ __('Pay') }}</button>
        </form>
    </x-card>
    </fieldset>
    @include('livewire.customer.wallet._result')
    @if($merchants->isNotEmpty())
        <x-card :title="__('Merchants near you (demo)')">
            <div class="grid gap-2 sm:grid-cols-2">@foreach($merchants as $m)
                <button wire:click="pick('{{ $m->merchant_code }}')" class="flex items-center gap-2 rounded-lg border border-neutral-200 p-2 text-left text-sm hover:border-brand-400"><x-icon name="qr" class="h-4 w-4 text-brand-700"/><span>{{ $m->business_name }}<span class="block text-xs text-neutral-500">{{ $m->merchant_code }} · {{ $m->city }}</span></span></button>
            @endforeach</div>
        </x-card>
    @endif
</div>
