@props(['amount', 'currency' => true, 'tone' => null])
@php($neg = \App\Support\Money::lt($amount ?? 0, 0))
<span dir="ltr" {{ $attributes->merge(['class' => 'tabular whitespace-nowrap '.($tone === 'auto' ? ($neg ? 'text-red-600' : 'text-ink') : '')]) }}>{{ \App\Support\Money::format($amount ?? 0, $currency) }}</span>
