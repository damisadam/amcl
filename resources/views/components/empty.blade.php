@props(['title' => __('Nothing here yet'), 'description' => null, 'colspan' => null, 'icon' => 'inbox'])
@if($colspan)<tr><td colspan="{{ $colspan }}">@endif
<div class="flex flex-col items-center justify-center px-6 py-10 text-center">
    <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon :name="$icon" class="h-6 w-6"/></div>
    <p class="text-sm font-semibold text-ink">{{ $title }}</p>
    @if($description)<p class="mt-1 max-w-md text-sm text-neutral-500">{{ $description }}</p>@endif
    @if(! $slot->isEmpty())<div class="mt-4">{{ $slot }}</div>@endif
</div>
@if($colspan)</td></tr>@endif
