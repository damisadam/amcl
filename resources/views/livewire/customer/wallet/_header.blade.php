<div class="mb-4 flex items-center justify-between rounded-2xl px-5 py-4 text-white shadow-md" style="background: linear-gradient(135deg, #052414 0%, #0d4229 60%, {{ $currentTenant->primary_color ?? '#15803d' }} 170%)">
    <div><p class="text-[11px] font-medium uppercase tracking-widest text-white/70">{{ __('Available balance') }}</p><p class="mt-0.5 text-2xl font-bold tabular">{{ \App\Support\Money::format($w->availableBalance()) }}</p></div>
    <a href="{{ route('app.wallet') }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-white/25 px-3 py-1.5 text-xs font-medium hover:bg-white/10">{{ __('Wallet home') }}<x-icon name="chevron-right" class="h-3.5 w-3.5 rtl:rotate-180"/></a>
</div>
@if($reason = $this->blockReason())
    @php([$actionLabel, $actionUrl] = $this->blockAction())
    <x-alert tone="amber" class="mb-4" :title="__('Payments are unavailable')">
        <p>{{ $reason }}</p>
        <a href="{{ $actionUrl }}" wire:navigate class="mt-2 inline-block font-semibold underline">{{ $actionLabel }}</a>
    </x-alert>
@endif
@error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
