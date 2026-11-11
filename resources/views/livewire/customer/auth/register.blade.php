<x-dynamic-component component="customer-auth-shell" :tenant="$tenant">
    <div class="mb-4 flex items-center gap-2 text-xs font-medium text-neutral-500">
        <span @class(['rounded-full px-2 py-0.5', 'bg-brand-700 text-white' => $step === 1, 'bg-brand-100 text-brand-800' => $step > 1])>1 {{ __('Account') }}</span>
        <span class="h-px flex-1 bg-neutral-200"></span>
        <span @class(['rounded-full px-2 py-0.5', 'bg-brand-700 text-white' => $step === 2, 'bg-neutral-100' => $step < 2])>2 {{ __('Verify mobile') }}</span>
        <span class="h-px flex-1 bg-neutral-200"></span>
        <span class="rounded-full bg-neutral-100 px-2 py-0.5">3 {{ __('Profile & KYC') }}</span>
    </div>
    @if($step === 1)
        <h1 class="text-xl font-bold text-ink">{{ __('Create your account') }}</h1>
        <form wire:submit="create" class="mt-4 space-y-4">
            <x-input :label="__('Full name (as on CNIC)')" wire:model="name" required/>
            <x-input :label="__('Mobile number')" wire:model="mobile" placeholder="03001234567" inputmode="tel" required/>
            <x-input :label="__('Email (optional)')" type="email" wire:model="email"/>
            <x-input :label="__('Password')" type="password" wire:model="password" :hint="__('At least 10 characters with upper & lower case, a number and a symbol.')" required/>
            <x-input :label="__('Confirm password')" type="password" wire:model="password_confirmation" required/>
            <x-checkbox wire:model="accept" :label="__('I accept the Terms of Use and Privacy Policy. My data is stored in Pakistan.')"/>
            @error('accept')<p class="field-error">{{ $message }}</p>@enderror
            <button class="btn-primary w-full" wire:loading.attr="disabled">{{ __('Continue') }}</button>
        </form>
        <p class="mt-4 text-center text-sm">{{ __('Already registered?') }} <a href="{{ route('customer.login', $tenant) }}" class="link">{{ __('Sign in') }}</a></p>
    @else
        <h1 class="text-xl font-bold text-ink">{{ __('Verify your mobile') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ __('We sent a 6-digit code by SMS to :m.', ['m' => $mobile]) }}</p>
        @if($demoOtp)<x-alert class="mt-3" :title="__('Simulated SMS')">{{ __('Demo mode – your OTP is') }} <strong class="tabular text-lg tracking-widest">{{ $demoOtp }}</strong></x-alert>@endif
        <form wire:submit="verify" class="mt-4 space-y-4">
            <x-input :label="__('Verification code')" wire:model="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required/>
            <button class="btn-primary w-full">{{ __('Verify & continue') }}</button>
        </form>
        <button wire:click="resend" class="mt-3 w-full text-center text-sm link">{{ __('Resend code') }}</button>
    @endif
</x-dynamic-component>
