@if(config('integrations.demo_mode'))
@php([$lead, $rest] = array_pad(explode(' – ', __('DEMO ENVIRONMENT – all banks, NADRA, Tasdeeq, Raast, wallets and e-signatures are SIMULATED. No real money moves.'), 2), 2, null))
<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-2 bg-amber-400 px-3 py-1 text-center text-xs text-ink']) }} role="note">
    <x-icon name="exclamation" class="hidden h-4 w-4 sm:block"/>
    <p><strong class="font-bold">{{ $lead }}</strong>@if($rest) – <span class="font-medium">{{ $rest }}</span>@endif</p>
</div>
@endif
