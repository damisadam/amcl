<x-dynamic-component component="customer-auth-shell" :tenant="$tenant">
    <h1 class="text-xl font-bold text-ink">{{ __('Welcome back') }}</h1>
    <p class="mb-4 text-sm text-neutral-500">{{ __('Sign in with your mobile number or email.') }}</p>
    <form wire:submit="submit" class="space-y-4">
        <x-input :label="__('Mobile number or email')" wire:model="login" placeholder="03001234567" autocomplete="username" required/>
        <x-input :label="__('Password')" type="password" wire:model="password" autocomplete="current-password" required/>
        <button class="btn-primary w-full py-2.5" wire:loading.attr="disabled">{{ __('Sign in') }}</button>
    </form>
    <div class="mt-4 flex justify-between text-sm"><a href="{{ route('customer.password', $tenant) }}" class="link">{{ __('Forgot password?') }}</a>
        <a href="{{ route('customer.register', $tenant) }}" class="link">{{ __('Create account') }}</a></div>
</x-dynamic-component>
