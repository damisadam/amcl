@props(['tone' => 'dark'])
{{-- "English | اردو" switch from the reference top bars. Current language is emphasised. --}}
@php($on = $tone === 'dark' ? 'font-semibold text-white' : 'font-semibold text-brand-800')
@php($off = $tone === 'dark' ? 'text-white/70 hover:text-white' : 'text-neutral-500 hover:text-ink')
<div {{ $attributes->merge(['class' => 'flex items-center gap-2 text-sm']) }}>
    <x-locale-switch to="en" label="English" :class="'px-1 '.(app()->getLocale() === 'en' ? $on : $off)"/>
    <span class="{{ $tone === 'dark' ? 'text-white/30' : 'text-neutral-300' }}">|</span>
    <x-locale-switch to="ur" :class="'px-1 '.(app()->getLocale() === 'ur' ? $on : $off)"/>
</div>
