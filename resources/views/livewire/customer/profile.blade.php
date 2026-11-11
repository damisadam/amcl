<div class="space-y-5">
    <x-page-header :title="__('Profile & Settings')" :subtitle="__('Manage your personal information, security, preferences and consents.')"/>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror

    <x-card :title="__('Identity')" icon="user" :subtitle="$locked ? __('Verified details are locked. Request a correction below if something is wrong.') : __('Complete your details during onboarding.')">
        <x-slot:actions>
            @if($locked)<a href="#correction" class="btn-secondary btn-sm"><x-icon name="document-text" class="h-4 w-4"/>{{ __('Request correction') }}</a>
            @else<a href="{{ route('app.onboarding') }}" wire:navigate class="btn-secondary btn-sm">{{ __('Edit in onboarding') }}</a>@endif
        </x-slot:actions>
        @php($rows = [
            [__('Full name') => $c->full_name, __('Father / husband') => $c->father_husband_name, __('KYC status') => null],
            [__('CNIC') => $c->maskedCnic(), __('Mobile') => $c->mobile, __('Member since') => $c->created_at->format('d M Y')],
            [__('Date of birth') => $c->date_of_birth?->format('d M Y'), __('Customer ID (CIF)') => $c->cif_no],
        ])
        <div class="grid gap-y-2 text-sm lg:grid-cols-3 lg:divide-x lg:divide-neutral-200 rtl:lg:divide-x-reverse">
            @foreach($rows as $col)
                <dl class="space-y-2 lg:px-6 lg:first:ps-0">
                    @foreach($col as $label => $value)
                        <div class="grid grid-cols-[9rem_1fr] items-center gap-3"><dt class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ $label }}</dt>
                            <dd class="font-semibold text-ink">@if($label === __('KYC status'))<x-status :value="$c->kyc_status"/>@else<bdi>{{ $value ?: '—' }}</bdi>@endif</dd></div>
                    @endforeach
                </dl>
            @endforeach
        </div>
    </x-card>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-card :title="__('Contact details')" icon="map" :subtitle="__('Keep your current address up to date.')">
            <form wire:submit="saveContact" class="space-y-4">
                <x-input :label="__('City')" wire:model="city"/>
                <x-textarea :label="__('Current address')" wire:model="current_address" rows="2"/>
                <button class="btn-primary">{{ __('Save changes') }}</button>
            </form>
            @if($kins->isNotEmpty())<p class="mt-3 text-sm text-neutral-600">{{ __('Next of kin') }}: {{ $kins->first()->name }} ({{ $kins->first()->relation }}) · <a href="{{ route('app.onboarding') }}" wire:navigate class="link">{{ __('change') }}</a></p>@endif
        </x-card>

        <x-card :title="__('Login email & mobile')" icon="mail" :subtitle="__('Both are used to sign in. Changes are confirmed with a code sent to the new contact.')">
            <x-dl :cols="2" :items="[__('Email') => $c->email, __('Mobile') => $c->mobile]"/>
            @if(! $contactPending)
                <form wire:submit="startContactChange" class="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end">
                    <x-select :label="__('Change')" wire:model.live="contactField" :options="['email' => __('Email'), 'mobile' => __('Mobile number')]"/>
                    <x-input :label="$contactField === 'email' ? __('New email') : __('New mobile number')" :type="$contactField === 'email' ? 'email' : 'tel'" wire:model="newContact"/>
                    <button class="btn-secondary sm:w-fit" wire:loading.attr="disabled">{{ __('Send code') }}</button>
                </form>
            @else
                <form wire:submit="confirmContactChange" class="mt-4 space-y-3">
                    <p class="text-sm">{{ __('Enter the 6-digit code sent to :c.', ['c' => $newContact]) }}</p>
                    @if($demoOtp)<x-alert :title="__('Simulated message')">{{ __('Demo mode – your code is') }} <strong class="tabular tracking-widest"><bdi>{{ $demoOtp }}</bdi></strong></x-alert>@endif
                    <x-input :label="__('Verification code')" wire:model="contactCode" inputmode="numeric" maxlength="6" autocomplete="one-time-code"/>
                    <div class="flex gap-2"><button type="button" wire:click="cancelContactChange" class="btn-secondary">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Confirm change') }}</button></div>
                </form>
            @endif
        </x-card>
    </div>

    @if($locked)
        <x-card id="correction" class="scroll-mt-24" :title="__('Request an identity correction')" icon="document-text" :subtitle="__('If your identity details are incorrect, submit a request and our team will review it.')">
            <form wire:submit="requestChange" class="grid gap-4 sm:grid-cols-3">
                <x-select :label="__('Field')" wire:model.live="changeField" :options="['cnic' => __('CNIC'), 'full_name' => __('Full name'), 'date_of_birth' => __('Date of birth'), 'father_husband_name' => __('Father / husband name')]"/>
                <x-input :label="__('Correct value')" :type="$changeField === 'date_of_birth' ? 'date' : 'text'" wire:model="changeValue"/>
                <x-input :label="__('Reason')" wire:model="changeReason"/>
                <button class="btn-secondary sm:w-fit">{{ __('Submit request') }}</button>
            </form>
            @if($requests->isNotEmpty())
                <ul class="mt-3 divide-y divide-neutral-100 text-sm">@foreach($requests as $r)<li class="flex justify-between py-2"><span>{{ \Illuminate\Support\Str::headline($r->field) }} · {{ $r->created_at->format('d M Y') }}</span><x-status :value="$r->status"/></li>@endforeach</ul>
            @endif
        </x-card>
    @endif

    <div class="grid items-start gap-5 lg:grid-cols-2">
        <div class="space-y-5">
            <x-card :title="__('Language')" icon="globe" :subtitle="__('Choose your preferred language for the portal.')">
                <div class="inline-flex rounded-full border border-neutral-200 bg-white p-1 text-sm">
                    <x-locale-switch to="en" label="English" :class="'rounded-full px-6 py-1.5 font-semibold '.(app()->getLocale() === 'en' ? 'bg-brand-800 text-white' : 'text-neutral-600')"/>
                    <x-locale-switch to="ur" :class="'rounded-full px-6 py-1.5 font-semibold '.(app()->getLocale() === 'ur' ? 'bg-brand-800 text-white' : 'text-neutral-600')"/>
                </div>
            </x-card>
            <x-card :title="__('Security')" icon="lock" :subtitle="__('Manage your account security and active sessions.')">
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('password.change') }}" class="btn-secondary btn-sm"><x-icon name="lock" class="h-4 w-4"/>{{ __('Change password') }}</a>
                    @if(Route::has('app.wallet.settings') && $c->wallet)<a href="{{ route('app.wallet.settings') }}" wire:navigate class="btn-secondary btn-sm"><x-icon name="shield" class="h-4 w-4"/>{{ __('Wallet PIN & limits') }}</a>@endif
                </div>
                <ul class="mt-3 divide-y divide-neutral-100 text-sm">
                    @foreach($devices as $d)
                        <li class="flex justify-between py-2"><span>{{ $d->label }} <span class="text-xs text-neutral-500">· {{ $d->last_seen_at?->diffForHumans() }}</span></span>
                            @if($d->revoked_at)<x-badge>{{ __('Signed out') }}</x-badge>@else<button wire:click="revokeDevice({{ $d->id }})" class="text-xs text-red-600">{{ __('Sign out') }}</button>@endif</li>
                    @endforeach
                </ul>
                <x-alert tone="blue" icon="info" class="mt-3 text-xs">{{ __('This web app never accesses your contacts, gallery or photos. Your data is stored in Pakistan.') }}</x-alert>
            </x-card>
        </div>

        <x-card :title="__('Consents')" icon="shield" :subtitle="__('Required consents cannot be withdrawn while you have an active account.')">
            <ul class="grid gap-x-6 sm:grid-cols-2">
                @foreach(\App\Enums\ConsentType::cases() as $t)
                    @php($a = $active[$t->value])
                    <li class="flex items-center justify-between gap-3 border-b border-neutral-100 py-2.5 text-sm">
                        <div class="min-w-0"><p class="font-medium text-ink">{{ __($t->label()) }}</p><p class="text-xs text-neutral-500">{{ $a ? __('Given :d', ['d' => $a->recorded_at->format('d M Y H:i')]) : __('Not given') }}</p></div>
                        @if($a && ! in_array($t->value, \App\Livewire\Customer\Profile::MANDATORY))<button wire:click="withdrawConsent('{{ $t->value }}')" wire:confirm="{{ __('Withdraw this consent?') }}" class="shrink-0 rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-100">{{ __('Withdraw') }}</button>
                        @elseif(! $a)<button wire:click="grantConsent('{{ $t->value }}')" class="shrink-0 rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-800 ring-1 ring-brand-200 hover:bg-brand-100">{{ __('Give consent') }}</button>@else<x-badge tone="green" class="shrink-0">{{ __('Required') }}</x-badge>@endif</li>
                @endforeach
            </ul>
            <details class="mt-3 text-xs text-neutral-500"><summary class="cursor-pointer">{{ __('Full consent history') }}</summary>
                <ul class="mt-2 space-y-1">@foreach($consents as $x)<li>{{ $x->recorded_at->format('d M Y H:i') }} · {{ __($x->consent_type->label()) }} · {{ $x->granted ? __('granted') : __('declined') }}@if($x->withdrawn_at) · {{ __('withdrawn') }} {{ $x->withdrawn_at->format('d M Y') }}@endif · {{ $x->locale }}</li>@endforeach</ul></details>
    </x-card>
    </div>
</div>
