@props(['tone' => 'amber', 'title' => null, 'icon' => null])
@php($c = ['amber' => 'border-amber-300 bg-amber-50 text-amber-900', 'red' => 'border-red-300 bg-red-50 text-red-800', 'green' => 'border-brand-300 bg-brand-50 text-brand-900', 'blue' => 'border-sky-300 bg-sky-50 text-sky-900', 'gray' => 'border-neutral-300 bg-neutral-50 text-neutral-800'][$tone])
@php($ic = ['amber' => 'text-amber-600', 'red' => 'text-red-600', 'green' => 'text-brand-700', 'blue' => 'text-sky-600', 'gray' => 'text-neutral-500'][$tone])
<div {{ $attributes->merge(['class' => "flex gap-3 rounded-lg border px-4 py-3 text-sm $c"]) }} role="alert">
    @if($icon)<x-icon :name="$icon" class="mt-px h-5 w-5 shrink-0 {{ $ic }}"/>@endif
    <div class="min-w-0 flex-1">
        @if($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div>{{ $slot }}</div>
    </div>
</div>
