@props(['title', 'subtitle' => null, 'back' => null, 'eyebrow' => null])
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if($back)<a href="{{ $back }}" wire:navigate class="mb-1.5 inline-flex items-center gap-1 text-xs font-medium text-neutral-500 hover:text-brand-800"><x-icon name="chevron-left" class="h-3.5 w-3.5 rtl:rotate-180"/>{{ __('Back') }}</a>@endif
        @if($eyebrow)<p class="text-sm text-neutral-500">{{ $eyebrow }}</p>@endif
        <h1 class="truncate text-2xl font-bold tracking-tight text-ink sm:text-[1.75rem]">{{ $title }}</h1>
        @if($subtitle)<p class="mt-1 text-sm text-neutral-500 sm:text-[15px]">{{ $subtitle }}</p>@endif
    </div>
    @if(isset($actions))<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endif
</div>
