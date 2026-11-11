@props(['to' => null, 'label' => null])
{{-- Language switch is a POST with CSRF (QA M1): a GET link could be triggered by any page or image tag. --}}
@php($to ??= app()->getLocale() === 'ur' ? 'en' : 'ur')
<form method="POST" action="{{ route('locale', $to) }}" class="inline">
    @csrf
    <button type="submit" {{ $attributes->merge(['class' => $to === 'ur' ? 'font-urdu' : '']) }} lang="{{ $to }}">{{ $label ?? ($to === 'ur' ? 'اردو' : 'English') }}</button>
</form>
