<div>
    <x-page-header :title="__('Complete your profile')" :subtitle="__('Verify your identity once to use financing and your wallet.')"/>

    @php($order = array_keys($steps))
    @php($idx = array_search($step, $order))
    <ol class="mb-5 flex gap-1 overflow-x-auto pb-1 text-xs">
        @foreach($steps as $key => $label)
            @php($i = array_search($key, $order))
            <li class="shrink-0">
                <button type="button" wire:click="goTo('{{ $key }}')" @class(['flex items-center gap-1.5 rounded-full px-3 py-1.5 font-medium',
                    'bg-brand-700 text-white' => $key === $step, 'bg-brand-100 text-brand-800' => $i < $idx, 'bg-white text-neutral-500 border border-neutral-200' => $i > $idx])>
                    <span class="tabular">{{ $i + 1 }}</span>{{ __($label) }}
                </button>
            </li>
        @endforeach
    </ol>

    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror

    <x-card>
    @if($step === 'otp')
        <h2 class="text-lg font-semibold">{{ __('Verify your mobile') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ __('We will send a 6-digit code by SMS to :m.', ['m' => auth()->user()->mobile]) }}</p>
        @if($demoOtp)<x-alert class="mt-3" :title="__('Simulated SMS')">{{ __('Demo mode – your OTP is') }} <strong class="tabular text-lg tracking-widest">{{ $demoOtp }}</strong></x-alert>@endif
        <form wire:submit="verifyOtp" class="mt-4 space-y-4 sm:max-w-sm">
            <x-input :label="__('Verification code')" wire:model="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"/>
            <div class="flex gap-2"><button class="btn-primary">{{ __('Verify') }}</button>
                <button type="button" wire:click="sendOtp" class="btn-secondary" wire:loading.attr="disabled">{{ __('Send code') }}</button></div>
        </form>

    @elseif($step === 'personal')
        <form wire:submit="savePersonal" class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Personal details') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Enter details exactly as printed on your CNIC.') }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input :label="__('Full name')" wire:model="personal.full_name" required/>
                <x-input :label="__('Father / husband name')" wire:model="personal.father_husband_name" required/>
                <x-input :label="__('CNIC number')" wire:model="personal.cnic" placeholder="35202-1234567-3" :hint="__('Format: 12345-1234567-1')" required/>
                <x-input :label="__('Date of birth')" type="date" wire:model="personal.date_of_birth" required/>
                <x-input :label="__('CNIC issue date')" type="date" wire:model="personal.cnic_issue_date"/>
                <x-input :label="__('CNIC expiry date')" type="date" wire:model="personal.cnic_expiry_date"/>
                <x-select :label="__('Gender')" wire:model="personal.gender" :placeholder="__('Select')" :options="['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')]" required/>
                <x-select :label="__('Marital status')" wire:model="personal.marital_status" :placeholder="__('Select')" :options="['single' => __('Single'), 'married' => __('Married'), 'widowed' => __('Widowed'), 'divorced' => __('Divorced')]"/>
                <x-input :label="__('Email (optional)')" type="email" wire:model="personal.email"/>
            </div>
            <button class="btn-primary" wire:loading.attr="disabled">{{ __('Save & continue') }}</button>
        </form>

    @elseif($step === 'address')
        <form wire:submit="saveAddress" class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Address & income') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-textarea :label="__('Current address')" wire:model="address.current_address" rows="2" required/>
                <div>
                    <x-textarea :label="__('Permanent address')" wire:model="address.permanent_address" rows="2" required/>
                    <button type="button" wire:click="sameAddress" class="mt-1 text-xs link">{{ __('Same as current address') }}</button>
                </div>
                <x-input :label="__('City')" wire:model="address.city" required/>
                <x-select :label="__('Employment type')" wire:model.live="address.employment_type" :placeholder="__('Select')"
                    :options="['salaried' => __('Salaried'), 'self_employed' => __('Self-employed'), 'business' => __('Business owner'), 'unemployed' => __('Not employed')]" required/>
                <x-input :label="__('Occupation')" wire:model="address.occupation"/>
                @if(($address['employment_type'] ?? '') === 'salaried')
                    <x-input :label="__('Employer name')" wire:model="address.employer_name" required/>
                @elseif(in_array($address['employment_type'] ?? '', ['business', 'self_employed']))
                    <x-input :label="__('Business name')" wire:model="address.business_name"/>
                    <x-input :label="__('Business type')" wire:model="address.business_type"/>
                @endif
                <x-input :label="__('Source of income')" wire:model="address.income_source"/>
                <x-input :label="__('Monthly income (PKR)')" type="number" step="1" min="0" wire:model="address.monthly_income" required/>
                <x-input :label="__('Other monthly income (PKR)')" type="number" step="1" min="0" wire:model="address.other_income"/>
                <x-input :label="__('Existing monthly loan instalments (PKR)')" type="number" step="1" min="0" wire:model="address.existing_obligations" :hint="__('Used to calculate your debt burden ratio.')"/>
            </div>
            <div class="flex gap-2"><button type="button" wire:click="goTo('personal')" class="btn-secondary">{{ __('Back') }}</button><button class="btn-primary">{{ __('Save & continue') }}</button></div>
        </form>

    @elseif($step === 'kin')
        <form wire:submit="saveKin" class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Next of kin') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('A close family member we may contact only in an emergency. Guarantors are added later with each application.') }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input :label="__('Name')" wire:model="kin.name" required/>
                <x-select :label="__('Relation')" wire:model="kin.relation" :placeholder="__('Select')" :options="['father' => __('Father'), 'mother' => __('Mother'), 'spouse' => __('Spouse'), 'brother' => __('Brother'), 'sister' => __('Sister'), 'son' => __('Son'), 'daughter' => __('Daughter'), 'other' => __('Other')]" required/>
                <x-input :label="__('Mobile number')" wire:model="kin.mobile" inputmode="tel" required/>
                <x-input :label="__('CNIC (optional)')" wire:model="kin.cnic"/>
                <x-textarea class="sm:col-span-2" :label="__('Address')" wire:model="kin.address" rows="2"/>
            </div>
            <button class="btn-primary">{{ __('Save & continue') }}</button>
        </form>

    @elseif($step === 'documents')
        <form wire:submit="uploadDocuments" class="space-y-5">
            <h2 class="text-lg font-semibold">{{ __('Identity documents') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Take clear photos in good light. Files are stored privately and only used for verification. We never access your contacts or gallery beyond the file you choose.') }}</p>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach(['cnicFront' => [__('CNIC – front'), 'environment'], 'cnicBack' => [__('CNIC – back'), 'environment'], 'selfie' => [__('Live selfie'), 'user']] as $prop => [$label, $capture])
                    <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-neutral-300 p-4 text-center hover:border-brand-500">
                        @if($this->{$prop})
                            <img src="{{ $this->{$prop}->temporaryUrl() }}" class="h-24 w-full rounded object-cover" alt="">
                        @else
                            <x-icon :name="$prop === 'selfie' ? 'user' : 'document'" class="h-8 w-8 text-neutral-400"/>
                        @endif
                        <span class="text-sm font-medium">{{ $label }}</span>
                        <span class="text-xs text-neutral-500">{{ __('Tap to capture or upload') }}</span>
                        <input type="file" accept="image/*" capture="{{ $capture }}" wire:model="{{ $prop }}" class="sr-only">
                        <span wire:loading wire:target="{{ $prop }}" class="text-xs text-brand-700">{{ __('Uploading…') }}</span>
                        @error($prop)<span class="field-error">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
            @if($docs->isNotEmpty())
                <div class="text-sm"><p class="mb-1 font-medium">{{ __('Already uploaded') }}</p>
                    <ul class="space-y-1">@foreach($docs as $d)<li class="flex items-center gap-2"><x-icon name="check" class="h-4 w-4 text-brand-700"/>{{ $d->type?->name ?? $d->title }} <span class="text-xs text-neutral-500">v{{ $d->version }}</span></li>@endforeach</ul></div>
            @endif
            <button class="btn-primary" wire:loading.attr="disabled">{{ __('Upload & continue') }}</button>
        </form>

    @elseif($step === 'consents')
        <form wire:submit="saveConsents" class="space-y-4">
            <h2 class="text-lg font-semibold">{{ __('Your consents') }}</h2>
            <p class="text-sm text-neutral-500">{{ __('Each consent is recorded separately with date, time and device. Items marked * are required to verify your identity.') }}</p>
            <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                @foreach($consentTypes as $t)
                    <label class="flex gap-3 p-3 text-sm">
                        <input type="checkbox" wire:model="consents.{{ $t->value }}" class="mt-1 h-4 w-4 rounded border-neutral-300 text-brand-700">
                        <span><span class="font-medium">{{ __($t->label()) }}@if(in_array($t->value, ['nadra_verification', 'credit_bureau']))<span class="text-red-600"> *</span>@endif</span>
                            <span class="block text-neutral-600 {{ app()->getLocale() === 'ur' ? 'font-urdu' : '' }}">{{ __('consents.'.$t->value) }}</span></span>
                    </label>
                @endforeach
            </div>
            @error('consents')<p class="field-error">{{ $message }}</p>@enderror
            <button class="btn-primary">{{ __('Record consents & continue') }}</button>
        </form>

    @else
        <h2 class="text-lg font-semibold">{{ __('Identity verification') }}</h2>
        <div class="mt-2 flex items-center gap-2 text-sm">{{ __('Status') }}: <x-status :value="$customer->kyc_status"/></div>
        @if($customer->kyc_status->value === 'passed')
            <x-alert tone="green" class="mt-4" :title="__('You are verified')">{{ __('Your account is active.') }} @if($wallet){{ __('Your wallet :no is :s.', ['no' => $wallet->wallet_no, 's' => __($wallet->status->label())]) }}@endif</x-alert>
            <div class="mt-4 flex flex-wrap gap-2"><a href="{{ route('app.products') }}" wire:navigate class="btn-primary">{{ __('Apply for financing') }}</a>
                @if(Route::has('app.wallet') && $wallet)<a href="{{ route('app.wallet.settings') }}" wire:navigate class="btn-secondary">{{ __('Set wallet PIN') }}</a>@endif</div>
        @elseif($customer->kyc_status->value === 'pending_review')
            <x-alert tone="amber" class="mt-4" :title="__('Manual review in progress')">{{ __('Our compliance team is reviewing your verification. You will be notified once complete – usually within one working day.') }}</x-alert>
        @elseif($customer->kyc_status->value === 'failed')
            <x-alert tone="red" class="mt-4" :title="__('Verification unsuccessful')">{{ __('We could not verify your identity online. Please visit a branch with your original CNIC or contact support.') }}</x-alert>
            <a href="{{ route('app.support') }}" wire:navigate class="btn-secondary mt-3">{{ __('Contact support') }}</a>
        @else
            <p class="mt-2 text-sm text-neutral-600">{{ __('We will read your CNIC, verify it with NADRA, match your selfie and screen against sanctions lists. This takes a few seconds.') }}</p>
            @if($this->demo())
                <x-select class="mt-4 sm:max-w-sm" :label="__('Simulation scenario (demo only)')" wire:model="scenario" :options="$scenarios"/>
            @endif
            <button wire:click="runKyc" class="btn-primary mt-4" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="runKyc">{{ __('Verify my identity') }}</span><span wire:loading wire:target="runKyc">{{ __('Verifying…') }}</span>
            </button>
        @endif

        @if($verifications->isNotEmpty())
            <ul class="mt-5 divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm">
                @foreach($verifications as $v)
                    <li class="flex items-center justify-between gap-3 p-3">
                        <span>{{ __($v->step->label()) }} @if($v->step->value !== 'aml_screening' && $v->score)<span class="text-xs text-neutral-500">({{ __('score') }} {{ $v->score }})</span>@endif</span>
                        <x-status :value="$v->step->value === 'aml_screening' && $v->status->value === 'manual_review' ? 'pending_review' : $v->status"/>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-neutral-500">{{ __('Verification services are simulated in this demo environment.') }}</p>
        @endif
    @endif
    </x-card>
</div>
