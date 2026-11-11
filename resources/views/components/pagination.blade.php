@if ($paginator->hasPages())
@php($cur = $paginator->currentPage())
@php($last = $paginator->lastPage())
@php($window = collect([1, $last, $cur - 1, $cur, $cur + 1])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values())
<nav class="flex flex-col gap-3 border-t border-neutral-100 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between" role="navigation" aria-label="{{ __('Pagination') }}">
    <p class="text-neutral-500">{{ __('Showing :from to :to of :total results', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</p>
    <div class="flex items-center gap-1.5">
        @php($pg = 'flex h-9 min-w-9 items-center justify-center rounded-lg border px-2 text-sm font-medium transition')
        @if ($paginator->onFirstPage())<span class="{{ $pg }} border-neutral-200 text-neutral-300"><x-icon name="chevron-left" class="h-4 w-4 rtl:rotate-180"/></span>
        @else<button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" class="{{ $pg }} border-neutral-200 bg-white text-neutral-700 hover:border-brand-300" aria-label="{{ __('Previous') }}"><x-icon name="chevron-left" class="h-4 w-4 rtl:rotate-180"/></button>@endif
        @foreach($window as $i => $p)
            @if($i > 0 && $p - $window[$i - 1] > 1)<span class="px-1 text-neutral-400">…</span>@endif
            @if($p === $cur)<span class="{{ $pg }} border-brand-700 bg-brand-700 text-white" aria-current="page">{{ $p }}</span>
            @else<button type="button" wire:click="gotoPage({{ $p }}, '{{ $paginator->getPageName() }}')" class="{{ $pg }} border-neutral-200 bg-white text-neutral-700 hover:border-brand-300">{{ $p }}</button>@endif
        @endforeach
        @if ($paginator->hasMorePages())<button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" class="{{ $pg }} border-neutral-200 bg-white text-neutral-700 hover:border-brand-300" aria-label="{{ __('Next') }}"><x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></button>
        @else<span class="{{ $pg }} border-neutral-200 text-neutral-300"><x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></span>@endif
    </div>
</nav>
@endif
