@props(['heading' => null, 'tagline' => null, 'badge' => null, 'width' => 'max-w-md'])
{{-- Branded sign-in frame shared by customer, staff/vendor and platform auth screens (UI redesign B6).
     Left: green shell panel with ornament + mosque (desktop only). Right: the form. --}}
<div class="flex min-h-[calc(100vh-1.5rem)]">
    <aside class="bg-shell relative hidden w-[44%] max-w-xl overflow-hidden text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
        <div class="pointer-events-none absolute inset-0 opacity-60 [background-image:url('/images/ui/pattern-light.svg')] [background-size:96px]" aria-hidden="true"></div>
        <div class="mosque pointer-events-none absolute bottom-0 left-0 h-72 w-full bg-white/[0.06]" aria-hidden="true"></div>
        <div class="relative">{{ $brand ?? '' }}</div>
        <div class="relative max-w-md">
            @if($badge)<span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium ring-1 ring-white/20"><x-icon name="shield" class="h-3.5 w-3.5"/>{{ $badge }}</span>@endif
            @if($heading)<h2 class="mt-4 text-3xl font-extrabold leading-tight tracking-tight">{{ $heading }}</h2>@endif
            @if($tagline)<p class="mt-3 text-white/75">{{ $tagline }}</p>@endif
        </div>
        <p class="relative text-xs text-white/50">{{ __('Shariah-compliant digital financing & wallet') }}</p>
    </aside>
    <main class="relative flex flex-1 flex-col items-center justify-center px-4 py-10">
        <div class="w-full {{ $width }}">{{ $slot }}</div>
        @isset($below)<div class="mt-5 w-full {{ $width }}">{{ $below }}</div>@endisset
    </main>
</div>
