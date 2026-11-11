<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>@include('layouts._head')</head>
<body class="ornament min-h-screen">
<x-demo-banner/>
<x-flash/>
<div class="min-h-screen" style="background: radial-gradient(1200px 600px at 10% -10%, {{ ($currentTenant ?? null)?->primary_color ?? '#15803d' }}22, transparent), radial-gradient(800px 500px at 110% 10%, #0a0a0a14, transparent)">
    {{ $slot }}
</div>
@livewireScripts
</body>
</html>
