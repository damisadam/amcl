@props(['show', 'title' => null, 'maxWidth' => 'lg'])
@php($w = ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl', '4xl' => 'sm:max-w-4xl'][$maxWidth])
<div x-data="{ open: @entangle($show).live }" x-show="open" x-cloak x-on:keydown.escape.window="open = false" class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-ink/50" x-on:click="open = false"></div>
    <div class="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4">
        <div x-show="open" x-transition class="relative w-full {{ $w }} rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
            @if($title)<div class="flex items-center justify-between border-b border-neutral-100 px-5 py-3.5"><h3 class="font-semibold text-ink">{{ $title }}</h3>
                <button type="button" class="text-neutral-400 hover:text-ink" x-on:click="open = false" aria-label="Close">✕</button></div>@endif
            <div class="p-5">{{ $slot }}</div>
            @isset($footer)<div class="flex justify-end gap-2 border-t border-neutral-100 bg-neutral-50 px-5 py-3 rounded-b-2xl">{{ $footer }}</div>@endisset
        </div>
    </div>
</div>
