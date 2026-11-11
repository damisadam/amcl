<div class="flex min-h-screen items-center justify-center p-4">
    <form wire:submit="save" class="card w-full max-w-md space-y-4 p-6">
        <h1 class="text-xl font-bold">{{ __('Change password') }}</h1>
        @if($forced)<x-alert>{{ __('Your password has expired or was reset by an administrator. Choose a new password to continue.') }}</x-alert>@endif
        <x-input :label="__('Current password')" type="password" wire:model="current" required/>
        <x-input :label="__('New password')" type="password" wire:model="password" :hint="__('At least 10 characters with upper & lower case, a number and a symbol.')" required/>
        <x-input :label="__('Confirm new password')" type="password" wire:model="password_confirmation" required/>
        <div class="flex gap-2"><button class="btn-primary flex-1">{{ __('Update password') }}</button>
            @unless($forced)<a href="{{ url()->previous() }}" class="btn-secondary">{{ __('Cancel') }}</a>@endunless</div>
    </form>
</div>
