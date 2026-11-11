<div>
    <x-page-header title="Provision tenant" subtitle="Creates the tenant with default configuration, roles, chart of accounts, posting rules, fiscal years, approval workflows, demo-mode integrations and an initial administrator." :back="route('platform.tenants.index')"/>
    <form wire:submit="save" class="space-y-6">
        <x-card title="Institution">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Code (URL / org code)" wire:model="form.code" hint="Lowercase letters, digits, dashes. Customer entry: /t/{code}" required/>
                <x-input label="Display name" wire:model="form.name" required/>
                <x-input label="Legal name" wire:model="form.legal_name"/>
                <x-input label="Registration no." wire:model="form.registration_no"/>
                <x-input label="NBFC / SECP licence reference" wire:model="form.license_ref" hint="Use a clearly fictional placeholder for demo tenants."/>
                <x-input label="NTN" wire:model="form.ntn"/>
                <x-input label="Address" wire:model="form.address"/>
                <x-input label="City" wire:model="form.city" required/>
            </div>
        </x-card>
        <x-card title="Contacts & branding">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Primary contact name" wire:model="form.primary_contact_name"/>
                <x-input label="Primary contact email" type="email" wire:model="form.primary_contact_email"/>
                <x-input label="Primary contact phone" wire:model="form.primary_contact_phone"/>
                <x-input label="Support / grievance phone" wire:model="form.support_phone"/>
                <x-input label="Support / grievance email" type="email" wire:model="form.support_email"/>
                <div><label class="field-label">Brand colour</label><div class="flex gap-2"><input type="color" wire:model.live="form.primary_color" class="h-10 w-14 rounded border border-neutral-300"><input wire:model.live="form.primary_color" class="field-input"></div>
                    @error('form.primary_color')<p class="field-error">{{ $message }}</p>@enderror</div>
                <x-input label="Tagline (English)" wire:model="form.tagline"/>
                <x-input label="Tagline (Urdu)" wire:model="form.tagline_ur" dir="rtl"/>
                <x-select label="Default language" wire:model="form.default_locale" :options="['en' => 'English', 'ur' => 'Urdu']"/>
                <x-checkbox wire:model="form.is_demo" label="Demo tenant (fictional institution, simulated integrations)" class="mt-6"/>
            </div>
        </x-card>
        <x-card title="Wallet safeguarding & feature entitlements">
            <x-select label="Wallet safeguarding model" wire:model="form.wallet_safeguarding_model" :options="['tenant_safeguarded' => 'Tenant-owned safeguarded account', 'platform_pooled' => 'Platform (AMCL) pooled safeguarded account']"/>
            <x-alert class="mt-2" tone="amber">The legal ownership of safeguarded wallet funds is unconfirmed in the source documents (see DECISIONS D-001). Movements are simulated; confirm before production.</x-alert>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach(\App\Models\Tenant::FEATURES as $key => $label)
                    <x-checkbox wire:model="features" value="{{ $key }}" :label="$label"/>
                @endforeach
            </div>
        </x-card>
        <x-card title="Initial tenant administrator">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-input label="Name" wire:model="admin_name" required/>
                <x-input label="Email" type="email" wire:model="admin_email" required/>
                <x-input label="Temporary password" wire:model="admin_password" hint="Must be changed at first sign-in." required/>
            </div>
        </x-card>
        @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
        <div class="flex justify-end gap-2"><a href="{{ route('platform.tenants.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
            <button class="btn-primary" wire:loading.attr="disabled"><span wire:loading.remove wire:target="save">Provision tenant</span><span wire:loading wire:target="save">Provisioning…</span></button></div>
    </form>
</div>
