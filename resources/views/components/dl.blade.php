@props(['items' => [], 'cols' => 2])
<dl {{ $attributes->merge(['class' => 'grid gap-x-6 gap-y-3 text-sm '.($cols == 3 ? 'sm:grid-cols-3' : ($cols == 1 ? '' : 'sm:grid-cols-2'))]) }}>
    @foreach($items as $label => $value)
        <div class="min-w-0"><dt class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500">{{ $label }}</dt><dd class="mt-0.5 break-words font-medium text-ink">{!! $value === null || $value === '' ? '<span class="text-neutral-400">—</span>' : e($value) !!}</dd></div>
    @endforeach
    {{ $slot }}
</dl>
