@props(['title' => null, 'subtitle' => null, 'padding' => true, 'icon' => null, 'divider' => true])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if($title || isset($actions))
        <header @class(['flex items-center justify-between gap-3 px-5 pt-3.5', 'border-b border-neutral-100 pb-3.5' => $divider, 'pb-1' => ! $divider])>
            <div class="flex min-w-0 items-center gap-2.5">
                {{-- Icon + subtitle → round chip header (customer refs); icon alone → small inline icon (ops dashboard ref). --}}
                @if($icon && $subtitle)<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon :name="$icon" class="h-5 w-5"/></span>
                @elseif($icon)<x-icon :name="$icon" class="h-5 w-5 text-brand-700"/>@endif
                <div class="min-w-0"><h2 @class(['font-semibold text-ink', 'text-base' => $icon && $subtitle, 'text-[15px]' => ! ($icon && $subtitle)])>{{ $title }}</h2>@if($subtitle)<p class="text-xs text-neutral-500">{{ $subtitle }}</p>@endif</div>
            </div>
            @if(isset($actions))<div class="flex items-center gap-2">{{ $actions }}</div>@endif
        </header>
    @endif
    <div @class(['p-5' => $padding])>{{ $slot }}</div>
</section>
