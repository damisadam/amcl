<x-dynamic-component component="customer-auth-shell" :tenant="$tenant">
    <h1 class="text-xl font-bold text-ink">{{ __('Reset password') }}</h1>
    @if($step === 1)
        <form wire:submit="send" class="mt-4 space-y-4">
            <x-input :label="__('Registered mobile number')" wire:model="mobile" inputmode="tel" required/>
            <button class="btn-primary w-full">{{ __('Send code') }}</button>
        </form>
    @else
        <p class="mt-1 text-sm text-neutral-500">{{ __('If the number is registered, a code has been sent.') }}</p>
        @if($demoOtp)<x-alert class="mt-3" :title="__('Simulated SMS')">{{ __('Demo mode – your OTP is') }} <strong class="tabular text-lg tracking-widest">{{ $demoOtp }}</strong></x-alert>@endif
        <form wire:submit="resetPassword" class="mt-4 space-y-4">
            <x-input :label="__('Verification code')" wire:model="otp" inputmode="numeric" maxlength="6" required/>
            <x-input :label="__('New password')" type="password" wire:model="password" required/>
            <x-input :label="__('Confirm new password')" type="password" wire:model="password_confirmation" required/>
            <button class="btn-primary w-full">{{ __('Update password') }}</button>
        </form>
    @endif
    <p class="mt-4 text-center text-sm"><a href="{{ route('customer.login', $tenant) }}" class="link">{{ __('Back to sign in') }}</a></p>
</x-dynamic-component>
