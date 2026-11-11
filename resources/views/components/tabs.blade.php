@props(['tabs' => [], 'active' => null, 'model' => 'tab'])
{{-- Segmented tab bar (UI redesign): scrolls horizontally on small screens instead of clipping. --}}
<div {{ $attributes->merge(['class' => 'mb-5 overflow-x-auto rounded-xl border border-neutral-200/80 bg-white p-1 shadow-[0_1px_2px_rgba(16,24,40,0.04)] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden']) }}>
    <nav class="flex min-w-max gap-1 text-sm" role="tablist">
        @foreach($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $active === $key ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', '{{ $key }}')" @class(['whitespace-nowrap rounded-lg px-3.5 py-2 font-medium transition', 'bg-brand-700 text-white shadow-sm' => $active === $key, 'text-neutral-600 hover:bg-brand-50 hover:text-brand-800' => $active !== $key])>{{ $label }}</button>
        @endforeach
    </nav>
</div>
