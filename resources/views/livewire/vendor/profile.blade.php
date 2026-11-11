<div>
    <x-page-header :title="__('Vendor profile')" :subtitle="$vendor->business_name.' · '.$vendor->code"/>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Business')">
            <x-dl :items="[__('Business name') => $vendor->business_name, __('Category') => \Illuminate\Support\Str::headline($vendor->category), __('Registration no.') => $vendor->registration_no,
                __('NTN') => $vendor->ntn, __('Status') => $vendor->status->label(), __('Approved') => $vendor->approved_at?->format('d M Y'),
                __('Payment method') => strtoupper($vendor->preferred_payment_method), __('Merchant code') => $vendor->merchant_code]"/>
            <p class="mt-3 text-xs text-neutral-500">{{ __('To change registered business details, contact the institution.') }}</p>
        </x-card>
        <x-card :title="__('Contact details')">
            <form wire:submit="saveContact" class="space-y-3">
                <x-input :label="__('Owner / contact name')" wire:model="contact.owner_name"/>
                <div class="grid gap-3 sm:grid-cols-2"><x-input :label="__('Phone')" wire:model="contact.phone"/><x-input :label="__('Email')" type="email" wire:model="contact.email"/></div>
                <x-input :label="__('Address')" wire:model="contact.address"/>
                <x-input :label="__('City')" wire:model="contact.city"/>
                <div class="flex justify-end"><button class="btn-primary">{{ __('Save') }}</button></div>
            </form>
        </x-card>
        <x-card :title="__('Bank account for payouts')" class="lg:col-span-2">
            <x-dl :cols="3" :items="[__('Bank') => $vendor->bank_name, __('Account title') => $vendor->account_title, __('IBAN') => $vendor->iban ? '****'.substr($vendor->iban, -4) : null]"/>
            <x-alert class="mt-4" tone="blue">{{ __('For your protection, bank details are changed only by the institution after verifying a bank letter. Submit a change request below and upload the new bank letter under Documents.') }}</x-alert>
            @if($bankRequested)<x-alert class="mt-3" tone="green">{{ __('Your request has been sent. Payouts continue to the current account until the institution updates it.') }}</x-alert>@endif
            <form wire:submit="requestBankChange" class="mt-4 grid gap-3 sm:grid-cols-3">
                <x-input :label="__('New bank name')" wire:model="bank.bank_name"/>
                <x-input :label="__('New IBAN')" wire:model="bank.iban" placeholder="PK36SCBL0000001123456702"/>
                <x-input :label="__('Account title')" wire:model="bank.account_title"/>
                <x-textarea :label="__('Reason for change')" wire:model="bank.reason" class="sm:col-span-3"/>
                <div class="sm:col-span-3 flex justify-end"><button class="btn-secondary" wire:confirm="{{ __('Send this bank change request to the institution?') }}">{{ __('Request bank change') }}</button></div>
            </form>
        </x-card>
    </div>
</div>
