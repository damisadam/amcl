@props(['tone' => 'gray'])
@php($c = ['green' => 'bg-brand-100/80 text-brand-800 ring-brand-600/15', 'red' => 'bg-red-50 text-red-700 ring-red-600/20', 'amber' => 'bg-orange-50 text-orange-700 ring-orange-500/30',
    'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20', 'black' => 'bg-ink text-white ring-ink', 'gray' => 'bg-neutral-100 text-neutral-700 ring-neutral-500/20'][$tone] ?? 'bg-neutral-100 text-neutral-700')
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap $c"]) }}>{{ $slot }}</span>
