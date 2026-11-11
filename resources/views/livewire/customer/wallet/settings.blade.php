<div class="space-y-4">
    <x-page-header :title="__('Wallet settings')" :back="route('app.wallet')"/>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
    <x-card :title="$w->pin_hash ? __('Change PIN') : __('Set wallet PIN')">
        <form wire:submit="savePin" class="grid gap-4 sm:grid-cols-3 sm:items-end">
            @if($w->pin_hash)<x-input :label="__('Current PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="currentPin"/>@endif
            <x-input :label="__('New PIN (4–6 digits)')" type="password" inputmode="numeric" maxlength="6" wire:model="newPin"/>
            <x-input :label="__('Confirm PIN')" type="password" inputmode="numeric" maxlength="6" wire:model="newPin_confirmation"/>
            <button class="btn-primary sm:col-span-3 sm:w-fit">{{ __('Save PIN') }}</button>
        </form>
    </x-card>
    <x-card :title="__('Security')">
        <div class="flex items-center justify-between"><div><p class="text-sm font-medium">{{ __('Biometric authentication') }}</p><p class="text-xs text-neutral-500">{{ __('Use fingerprint / face unlock on supported devices (simulated in the web demo).') }}</p></div>
            <button wire:click="toggleBiometric" @class(['relative h-6 w-11 rounded-full transition', 'bg-brand-600' => $w->biometric_enabled, 'bg-neutral-300' => ! $w->biometric_enabled])><span @class(['absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition', 'left-5' => $w->biometric_enabled, 'left-0.5' => ! $w->biometric_enabled])></span></button></div>
    </x-card>
    <x-card :title="__('Limits')">
        <x-dl :cols="3" :items="[__('Per transaction') => \App\Support\Money::format($w->per_transaction_limit), __('Monthly') => \App\Support\Money::format($w->monthly_limit), __('Maximum balance') => \App\Support\Money::format($w->max_balance)]"/>
        <form wire:submit="saveLimit" class="mt-4 flex flex-wrap items-end gap-2"><x-input :label="__('Daily spending limit (PKR)')" type="number" wire:model="dailyLimit"/><button class="btn-secondary">{{ __('Update') }}</button></form>
    </x-card>
    <x-card :title="__('Linked bank accounts')">
        <ul class="divide-y divide-neutral-100 text-sm">
            @forelse($accounts as $a)
                <li class="flex items-center justify-between py-2"><div>{{ $a->bank_name }} ****{{ substr($a->iban, -4) }}<span class="block text-xs text-neutral-500">{{ $a->account_title }} @if($a->is_verified)· {{ __('verified') }}@endif</span></div>
                    <div class="flex gap-2">@if($a->is_default)<x-badge tone="green">{{ __('Default') }}</x-badge>@else<button wire:click="makeDefault({{ $a->id }})" class="text-xs link">{{ __('Make default') }}</button>@endif
                        <button wire:click="unlink({{ $a->id }})" wire:confirm="{{ __('Remove this account?') }}" class="text-xs text-red-600">{{ __('Remove') }}</button></div></li>
            @empty<li class="py-3 text-neutral-500">{{ __('No linked accounts.') }}</li>@endforelse
        </ul>
        <form wire:submit="link" class="mt-3 grid gap-3 border-t border-neutral-100 pt-3 sm:grid-cols-3 sm:items-end">
            <x-select :label="__('Bank')" wire:model="newBank" :options="array_combine($banks, $banks)"/>
            <x-input :label="__('IBAN')" wire:model="newIban" placeholder="PK36MEZN0001234567890123"/>
            <button class="btn-secondary">{{ __('Verify & link') }}</button>
        </form>
    </x-card>
    <x-card :title="__('Devices')">
        <ul class="divide-y divide-neutral-100 text-sm">
            @foreach($devices as $d)
                <li class="flex items-center justify-between py-2"><div>{{ $d->label }} @if($d->fingerprint === $currentFp)<x-badge tone="green">{{ __('This device') }}</x-badge>@endif
                    <span class="block text-xs text-neutral-500">{{ __('Last seen') }} {{ $d->last_seen_at?->diffForHumans() }} · {{ $d->ip }}</span></div>
                    @if($d->revoked_at)<x-badge>{{ __('Signed out') }}</x-badge>@elseif($d->fingerprint !== $currentFp)<button wire:click="revokeDevice({{ $d->id }})" class="text-xs text-red-600">{{ __('Sign out') }}</button>@endif</li>
            @endforeach
        </ul>
    </x-card>
</div>
