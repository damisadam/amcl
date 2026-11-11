@props(['label'])
<label {{ $attributes->only('class')->merge(['class' => 'flex items-start gap-2.5 text-sm text-neutral-800']) }}>
    <input type="checkbox" {{ $attributes->except('class')->merge(['class' => 'mt-0.5 h-4 w-4 rounded border-neutral-300 text-brand-700 focus:ring-brand-600']) }}>
    <span>{{ $label }}{{ $slot }}</span>
</label>
