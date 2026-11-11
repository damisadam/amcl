@props(['label' => null, 'name' => null, 'type' => 'text', 'hint' => null, 'required' => false, 'icon' => null])
@php($model = $attributes->wire('model')->value() ?: $name)
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $model }}" class="field-label">{{ $label }}@if($required)<span class="text-red-600"> *</span>@endif</label>@endif
    @if($icon)<div class="relative"><x-icon :name="$icon" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400"/>@endif
    <input id="{{ $model }}" type="{{ $type }}" name="{{ $name ?? $model }}" {{ $attributes->except('class')->merge(['class' => 'field-input'.($icon ? ' ps-9' : '').($errors->has($model) ? ' border-red-500' : '')]) }}>
    @if($icon)</div>@endif
    @if($hint)<p class="field-hint">{{ $hint }}</p>@endif
    @error($model)<p class="field-error">{{ $message }}</p>@enderror
</div>
