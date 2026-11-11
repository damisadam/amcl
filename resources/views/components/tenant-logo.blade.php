@props(['tenant' => null, 'size' => 'h-9 w-9'])
<img src="{{ \App\Support\TenantLogo::for($tenant) }}" alt="{{ ($tenant?->name ?? 'Institution') }} logo" {{ $attributes->merge(['class' => $size.' shrink-0 object-contain']) }}>
