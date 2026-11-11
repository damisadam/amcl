@props(['label' => null, 'hint' => null, 'rows' => 3, 'required' => false])
@php($model = $attributes->wire('model')->value() ?: $attributes->get('name'))
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $model }}" class="field-label">{{ $label }}@if($required)<span class="text-red-600"> *</span>@endif</label>@endif
    <textarea id="{{ $model }}" rows="{{ $rows }}" {{ $attributes->except('class')->merge(['class' => 'field-input'.($errors->has($model) ? ' border-red-500' : '')]) }}></textarea>
    @if($hint)<p class="field-hint">{{ $hint }}</p>@endif
    @error($model)<p class="field-error">{{ $message }}</p>@enderror
</div>
