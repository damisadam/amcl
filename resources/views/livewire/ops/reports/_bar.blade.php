{{-- Simple horizontal bar: $value, $max, $label, $display --}}
@php($w = $max > 0 ? max(2, round($value * 100 / $max)) : 0)
<div class="flex items-center gap-3 text-sm">
    <span class="w-40 shrink-0 truncate text-neutral-700" title="{{ $label }}">{{ $label }}</span>
    <div class="h-3 flex-1 rounded-full bg-neutral-100"><div class="h-3 rounded-full {{ $tone ?? 'bg-brand-600' }}" style="width: {{ $w }}%"></div></div>
    <span class="w-36 shrink-0 text-right tabular">{{ $display }}</span>
</div>
