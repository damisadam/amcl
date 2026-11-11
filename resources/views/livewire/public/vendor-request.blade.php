<div class="mx-auto max-w-3xl px-4 py-10">
    <a href="{{ route('tenant.entry', $tenant) }}" class="mb-6 flex items-center gap-2">
        <x-tenant-logo :tenant="$tenant" size="h-10 w-10"/>
        <span class="text-lg font-semibold text-ink">{{ $tenant->name }}</span>
    </a>
    @if($reference)
        <div class="card p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-100 text-brand-700"><x-icon name="check"/></div>
            <h1 class="mt-3 text-xl font-bold">{{ __('Request received') }}</h1>
            <p class="mt-2 text-neutral-600">{{ __('Your vendor reference is') }} <strong class="font-mono">{{ $reference }}</strong>. {{ __('Our team will verify your documents and contact you. Once approved you will receive vendor portal access.') }}</p>
            <a href="{{ route('tenant.entry', $tenant) }}" class="btn-secondary mt-5">{{ __('Back to :name', ['name' => $tenant->name]) }}</a>
        </div>
    @else
        <h1 class="text-2xl font-bold text-ink">{{ __('Become a vendor') }}</h1>
        <p class="mb-6 mt-1 text-sm text-neutral-600">{{ __('Suppliers of goods (rickshaws, motorbikes, appliances, solar, etc.) can apply to receive payment directly from :name when customers buy on financing.', ['name' => $tenant->name]) }}</p>
        <form wire:submit="submit" class="space-y-6">
            <x-card :title="__('Business')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input :label="__('Business name')" wire:model="form.business_name" required/>
                    <x-select :label="__('Category')" wire:model="form.category" :options="collect($categories)->map(fn ($c) => __($c))->all()"/>
                    <x-input :label="__('Owner name')" wire:model="form.owner_name" required/>
                    <x-input :label="__('Owner CNIC')" wire:model="form.cnic" placeholder="35202-1234567-1" required/>
                    <x-input :label="__('NTN')" wire:model="form.ntn"/>
                    <x-input :label="__('Registration no.')" wire:model="form.registration_no"/>
                    <x-input :label="__('Address')" wire:model="form.address" required/>
                    <x-input :label="__('City')" wire:model="form.city" required/>
                    <x-input :label="__('Phone')" wire:model="form.phone" placeholder="03001234567" required/>
                    <x-input :label="__('Email')" type="email" wire:model="form.email" required/>
                </div>
            </x-card>
            <x-card :title="__('Bank account for payments')">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-input :label="__('Bank')" wire:model="form.bank_name" required/>
                    <x-input :label="__('IBAN')" wire:model="form.iban" placeholder="PK36SCBL0000001123456702" required/>
                    <x-input :label="__('Account title')" wire:model="form.account_title" required/>
                </div>
            </x-card>
            <x-card :title="__('Documents (optional now, required before approval)')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="field-label">{{ __('Registration / licence') }}</label><input type="file" wire:model="registration_doc" accept="image/*,application/pdf" class="text-sm">@error('registration_doc')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <div><label class="field-label">{{ __('Bank letter') }}</label><input type="file" wire:model="bank_letter" accept="image/*,application/pdf" class="text-sm">@error('bank_letter')<p class="field-error">{{ $message }}</p>@enderror</div>
                </div>
            </x-card>
            <x-checkbox wire:model="accept" :label="__('I confirm the information is correct and agree that :name may verify it.', ['name' => $tenant->name])"/>
            @error('accept')<p class="field-error">{{ $message }}</p>@enderror
            <div class="flex justify-end"><button class="btn-primary" wire:loading.attr="disabled">{{ __('Submit application') }}</button></div>
        </form>
    @endif
</div>
