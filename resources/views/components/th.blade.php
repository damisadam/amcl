@props(['sort' => null, 'current' => null, 'dir' => 'asc', 'align' => 'left'])
<th {{ $attributes->merge(['class' => $align === 'right' ? 'text-right!' : '']) }}>
    @if($sort)
        <button type="button" wire:click="sortBy('{{ $sort }}')" class="inline-flex items-center gap-1 uppercase hover:text-ink">
            {{ $slot }}@if($current === $sort)<span>{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif
        </button>
    @else {{ $slot }} @endif
</th>
