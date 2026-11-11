@props(['category' => null, 'tile' => true])
{{-- Flat illustration per product category (ref: docs/ui-references/financing-products.png). Pure SVG – no stock photos. --}}
@php($category = $category instanceof \BackedEnum ? $category->value : $category)
@php($g = '#15803d')
@php($d = '#0f3d26')
@php($l = '#dcfce7')
@php($n = '#9ca3af')
<span {{ $attributes->merge(['class' => ($tile ? 'flex items-center justify-center rounded-xl bg-gradient-to-br from-brand-50 to-white ring-1 ring-brand-100 ' : 'inline-flex ').'shrink-0']) }} aria-hidden="true">
<svg viewBox="0 0 64 64" class="h-4/5 w-4/5" xmlns="http://www.w3.org/2000/svg" fill="none" stroke-linecap="round" stroke-linejoin="round">
@switch($category)
    @case('motorbike')
        <circle cx="14" cy="44" r="9" stroke="{{ $d }}" stroke-width="4"/><circle cx="50" cy="44" r="9" stroke="{{ $d }}" stroke-width="4"/>
        <path d="M14 44 26 31h14l10 13" stroke="{{ $n }}" stroke-width="3"/><path d="M28 31h15l-3 8H30z" fill="{{ $g }}"/>
        <rect x="21" y="25" width="14" height="5" rx="2.5" fill="{{ $d }}"/><path d="M44 31 47 20M43 20h7" stroke="{{ $d }}" stroke-width="3"/>
        @break
    @case('rickshaw')
        <path d="M8 20a6 6 0 0 1 6-6h24a6 6 0 0 1 6 6v22H8z" fill="{{ $g }}"/><path d="M6 15h40" stroke="{{ $d }}" stroke-width="4"/>
        <rect x="13" y="21" width="12" height="10" rx="1.5" fill="{{ $l }}"/><rect x="28" y="21" width="11" height="10" rx="1.5" fill="{{ $l }}"/>
        <path d="M44 28h7l5 14H44z" fill="#facc15"/><circle cx="17" cy="46" r="6" fill="{{ $d }}"/><circle cx="51" cy="46" r="6" fill="{{ $d }}"/><circle cx="17" cy="46" r="2" fill="#fff"/><circle cx="51" cy="46" r="2" fill="#fff"/>
        @break
    @case('laptop')
        <rect x="12" y="13" width="40" height="27" rx="2.5" fill="{{ $d }}"/><rect x="15" y="16" width="34" height="21" rx="1" fill="{{ $g }}"/>
        <path d="M15 37 35 16h14v6L27 37z" fill="#22c55e" opacity=".55"/><path d="M5 42h54l-4 7H9z" fill="{{ $n }}"/><path d="M27 42h10" stroke="#6b7280" stroke-width="2"/>
        @break
    @case('scooty')
        <circle cx="15" cy="46" r="7" stroke="{{ $d }}" stroke-width="4"/><circle cx="49" cy="46" r="7" stroke="{{ $d }}" stroke-width="4"/>
        <path d="M14 45c2-10 9-13 18-12h11l6 12z" fill="#dc2626"/><rect x="20" y="27" width="15" height="5" rx="2.5" fill="{{ $d }}"/>
        <path d="M44 34 40 15M35 15h9" stroke="{{ $d }}" stroke-width="3"/>
        @break
    @case('solar')
        <circle cx="12" cy="12" r="6" fill="#f59e0b"/><path d="M8 42 17 16h38l-9 26z" fill="#1e3a5f"/>
        <path d="M13 29h38M11 35h38M24 16l-6 26M36 16l-5 26M47 16l-4 26" stroke="#93c5fd" stroke-width="1.2"/><path d="M32 42v10M23 53h18" stroke="{{ $n }}" stroke-width="3"/>
        @break
    @case('home_appliances')
        <rect x="8" y="7" width="22" height="50" rx="3" fill="#e5e7eb" stroke="{{ $n }}" stroke-width="2"/><path d="M8 25h22M25 13v7M25 30v9" stroke="{{ $n }}" stroke-width="2"/>
        <rect x="34" y="24" width="23" height="33" rx="3" fill="#f3f4f6" stroke="{{ $n }}" stroke-width="2"/><circle cx="45.5" cy="42" r="8" fill="#bfdbfe" stroke="{{ $d }}" stroke-width="2.5"/><path d="M38 29h6" stroke="{{ $g }}" stroke-width="2.5"/>
        @break
    @case('karobar_sme')
        <rect x="10" y="22" width="44" height="32" fill="#fef3c7"/><path d="M6 23h52l-5-12H11z" fill="{{ $g }}"/><path d="M19 11l-3 12M32 11v12M45 11l3 12" stroke="#fff" stroke-width="3"/>
        <rect x="27" y="34" width="11" height="20" fill="{{ $d }}"/><rect x="14" y="30" width="9" height="9" fill="#93c5fd"/><rect x="42" y="30" width="9" height="9" fill="#93c5fd"/><path d="M4 55h56" stroke="{{ $n }}" stroke-width="2.5"/>
        @break
    @case('school_fees')
        <rect x="11" y="44" width="42" height="8" rx="1.5" fill="{{ $g }}"/><rect x="14" y="36" width="37" height="8" rx="1.5" fill="#f59e0b"/><rect x="11" y="28" width="40" height="8" rx="1.5" fill="#7c2d12"/>
        <path d="M32 6 56 14 32 22 8 14z" fill="{{ $d }}"/><path d="M18 17v6c4 3 24 3 28 0v-6" fill="{{ $d }}"/><path d="M52 15v9" stroke="#f59e0b" stroke-width="2"/>
        @break
    @default
        <path d="M32 8 54 20v24L32 56 10 44V20z" fill="{{ $l }}" stroke="{{ $g }}" stroke-width="3"/><path d="M10 20l22 12 22-12M32 32v24" stroke="{{ $g }}" stroke-width="3"/>
@endswitch
</svg>
</span>
