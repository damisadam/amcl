<div class="grid gap-3 sm:grid-cols-3">
    <x-input class="sm:col-span-2" :label="__('Business name')" wire:model="form.business_name" required/>
    <x-select :label="__('Category')" wire:model="form.category" :options="\App\Livewire\Ops\Vendors\VendorCategories::ALL"/>
    <x-input :label="__('Owner name')" wire:model="form.owner_name"/>
    <x-input :label="__('Owner CNIC')" wire:model="form.cnic" placeholder="12345-1234567-1"/>
    <x-select :label="__('Vendor kind')" wire:model="form.kind" :options="['asset' => 'Asset supplier (receives financing disbursements)', 'operational' => 'Operational supplier']"/>
    <x-input :label="__('NTN')" wire:model="form.ntn"/>
    <x-input :label="__('Registration / licence no')" wire:model="form.registration_no"/>
    <x-input :label="__('Phone')" wire:model="form.phone"/>
    <x-input :label="__('Email')" type="email" wire:model="form.email"/>
    <x-input :label="__('City')" wire:model="form.city"/>
    <x-input :label="__('Address')" wire:model="form.address"/>
    <x-input :label="__('Bank')" wire:model="form.bank_name"/>
    <x-input :label="__('IBAN')" wire:model="form.iban" placeholder="PK36SCBL0000001123456702"/>
    <x-input :label="__('Account title')" wire:model="form.account_title"/>
    <x-select :label="__('Payment method')" wire:model="form.preferred_payment_method" :options="['raast' => 'Raast', 'bank_transfer' => 'Bank transfer (IBFT)', 'cheque' => 'Cheque']"/>
    <x-input :label="__('Markup share %')" type="number" step="0.01" wire:model="form.markup_share_pct"/>
    <x-input :label="__('Merchant code (wallet QR)')" wire:model="form.merchant_code"/>
    <x-checkbox class="pt-6" wire:model="form.is_merchant" :label="__('Accepts wallet QR payments (merchant)')"/>
    <x-textarea class="sm:col-span-3" :label="__('Pricing / markup arrangement notes')" wire:model="form.pricing_notes"/>
</div>
