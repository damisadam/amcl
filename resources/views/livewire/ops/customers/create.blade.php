<div>
    <x-page-header :title="__('Onboard customer')" :subtitle="__('Branch-assisted onboarding. Identity is verified through the same e-KYC stack as the customer app.')" :back="route('ops.customers.index')"/>
    <form wire:submit="save" class="space-y-6">
        @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
        <x-card :title="__('Personal details')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input label="Full name (as on CNIC)" wire:model="form.full_name" required/>
                <x-input label="Father / husband name" wire:model="form.father_husband_name"/>
                <x-input label="CNIC" wire:model="form.cnic" placeholder="35202-1234567-1" required/>
                <x-input label="CNIC expiry" type="date" wire:model="form.cnic_expiry_date"/>
                <x-input label="Date of birth" type="date" wire:model="form.date_of_birth" required/>
                <x-select label="Gender" wire:model="form.gender" placeholder="Select" :options="['male' => 'Male', 'female' => 'Female', 'other' => 'Other']" required/>
                <x-select label="Marital status" wire:model="form.marital_status" placeholder="Select" :options="['single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'divorced' => 'Divorced']"/>
                <x-select label="Client type" wire:model="form.client_type" :options="['individual' => 'Individual', 'corporate' => 'Corporate', 'vendor_referred' => 'Vendor referred', 'staff' => 'Staff (corporate payroll)']"/>
                <x-select label="Preferred language" wire:model="form.preferred_locale" :options="['en' => 'English', 'ur' => 'Urdu']"/>
            </div>
        </x-card>
        <x-card :title="__('Contact & address')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input label="Mobile" wire:model="form.mobile" placeholder="03001234567" required/>
                <x-input label="Email" type="email" wire:model="form.email"/>
                <x-input label="City" wire:model="form.city" required/>
                <x-input class="sm:col-span-2 lg:col-span-3" label="Current address" wire:model="form.current_address" required/>
                <x-input class="sm:col-span-2 lg:col-span-3" label="Permanent address" wire:model="form.permanent_address"/>
                <x-select label="Branch" wire:model="form.branch_id" :options="$branches" required/>
            </div>
        </x-card>
        <x-card :title="__('Employment & income')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-select label="Employment type" wire:model.live="form.employment_type" placeholder="Select" :options="['salaried' => 'Salaried', 'self_employed' => 'Self-employed', 'business' => 'Business owner', 'unemployed' => 'Not employed']" required/>
                <x-input label="Occupation" wire:model="form.occupation"/>
                @if(in_array($form['employment_type'], ['business', 'self_employed']))
                    <x-input label="Business name" wire:model="form.business_name"/><x-input label="Business type" wire:model="form.business_type"/>
                @else
                    <x-input label="Employer" wire:model="form.employer_name"/>
                @endif
                <x-input label="Income source" wire:model="form.income_source"/>
                <x-input label="Monthly income (PKR)" type="number" step="0.01" wire:model="form.monthly_income" required/>
                <x-input label="Other monthly income (PKR)" type="number" step="0.01" wire:model="form.other_income"/>
                <x-input label="Existing monthly obligations (PKR)" type="number" step="0.01" wire:model="form.existing_obligations"/>
            </div>
        </x-card>
        <x-card :title="__('Next of kin')">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-input label="Name" wire:model="nok.name"/><x-input label="Relation" wire:model="nok.relation"/>
                <x-input label="Mobile" wire:model="nok.mobile"/><x-input label="CNIC" wire:model="nok.cnic"/>
            </div>
        </x-card>
        <x-card :title="__('Identity documents')" :subtitle="__('JPG/PNG/WEBP up to 5 MB. Stored privately; CNIC images are visible only to users with sensitive-data access.')">
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach(['cnicFront' => 'CNIC front', 'cnicBack' => 'CNIC back', 'photo' => 'Photograph / selfie'] as $prop => $label)
                    <div><label class="field-label">{{ $label }}</label><input type="file" accept="image/*" wire:model="{{ $prop }}" class="field-input">
                        <div wire:loading wire:target="{{ $prop }}" class="field-hint">Uploading…</div>@error($prop)<p class="field-error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
        </x-card>
        <x-card :title="__('Consents captured in branch')" :subtitle="__('Each consent is recorded separately with timestamp (DFS §1.7). Read the text to the customer.')">
            <div class="space-y-3">
                @foreach(\App\Enums\ConsentType::cases() as $ct)
                    <x-checkbox wire:model="consents.{{ $ct->value }}" :label="$ct->label().' – '.__('consents.'.$ct->value)"/>
                @endforeach
                @error('consents.terms_of_use')<p class="field-error">Terms of use consent is required.</p>@enderror
            </div>
            <div class="mt-4 flex flex-wrap items-end gap-4 border-t border-neutral-100 pt-4">
                <x-checkbox wire:model="runKyc" label="Run e-KYC now (requires CNIC front and NADRA consent)"/>
                @if(\App\Services\Payments\Simulation::enabled())<x-select label="Simulation scenario" wire:model="kycScenario" :options="config('integrations.kyc_scenarios')"/>@endif
            </div>
        </x-card>
        <div class="flex justify-end gap-2"><a href="{{ route('ops.customers.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button class="btn-primary" wire:loading.attr="disabled">Create customer</button></div>
    </form>
</div>
