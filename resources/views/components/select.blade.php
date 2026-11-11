@props(['label' => null, 'options' => [], 'placeholder' => null, 'hint' => null, 'required' => false])
@php($model = $attributes->wire('model')->value() ?: $attributes->get('name'))
<div {{ $attributes->only('class') }}>
    @if($label)<label for="{{ $model }}" class="field-label">{{ $label }}@if($required)<span class="text-red-600"> *</span>@endif</label>@endif
    <select id="{{ $model }}" {{ $attributes->except('class')->merge(['class' => 'field-input'.($errors->has($model) ? ' border-red-500' : '')]) }}>
        @if($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach
        {{ $slot }}
    </select>
    @if($hint)<p class="field-hint">{{ $hint }}</p>@endif
    @error($model)<p class="field-error">{{ $message }}</p>@enderror
</div>
