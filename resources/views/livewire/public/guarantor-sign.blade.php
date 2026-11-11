<div class="mx-auto max-w-3xl px-4 py-8">
    <div class="mb-5 flex items-center gap-3">
        <x-tenant-logo :tenant="$tenant" size="h-10 w-10"/>
        <div><p class="font-semibold text-ink">{{ $tenant->name }}</p><p class="text-xs text-neutral-500">{{ __('Guarantor e-signature') }}</p></div>
    </div>
    @if($done)
        <x-card><x-alert tone="green" :title="__('Signed')">{{ __('Thank you. Your signature on the :doc has been recorded. You can close this page.', ['doc' => $req->documentType->name]) }}</x-alert></x-card>
    @else
        <x-card :title="$req->documentType->name" :subtitle="__('You are signing as guarantor for :name (application :no).', ['name' => $req->application->customer->full_name, 'no' => $req->application->application_no])">
            <p class="mb-2 text-sm text-neutral-600">{{ __('Read the full document below before signing.') }}</p>
            <iframe title="{{ $req->documentType->name }}" sandbox srcdoc="{{ $html }}" class="h-[28rem] w-full rounded-lg border border-neutral-200 bg-white"></iframe>
            <x-checkbox class="mt-4" wire:model.live="read" :label="__('I have read this document and agree to act as guarantor on these terms.')"/>
            <div class="mt-4 space-y-3 border-t border-neutral-100 pt-4">
                @if(! $codeSent)
                    <button wire:click="sendCode" class="btn-primary" @disabled(! $read)>{{ __('Send verification code to :m', ['m' => $mobile]) }}</button>
                @else
                    @if($demoOtp)<x-alert :title="__('Simulated SMS')">{{ __('Demo mode – your OTP is') }} <strong class="tabular tracking-widest">{{ $demoOtp }}</strong></x-alert>@endif
                    <div class="flex flex-wrap items-end gap-2">
                        <x-input class="w-44" :label="__('Verification code')" wire:model="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code"/>
                        <button wire:click="sign" class="btn-primary" wire:loading.attr="disabled">{{ __('Sign document') }}</button>
                        <button wire:click="sendCode" class="btn-ghost btn-sm">{{ __('Resend code') }}</button>
                    </div>
                @endif
                @error('otp')<p class="field-error">{{ $message }}</p>@enderror
                <p class="text-xs text-neutral-500">{{ __('Signed through the institution\'s e-signature provider (simulated in this demo). Your IP address and device are recorded with the signature.') }}</p>
            </div>
        </x-card>
    @endif
</div>
