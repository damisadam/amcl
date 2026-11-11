@extends('documents._layout', ['locale' => $locale ?? 'en'])
@section('title', 'Key Fact Statement '.$app->application_no)
@section('body')
<div class="hdr"><div><h1>{{ $app->tenant->name }}</h1><div class="muted">{{ $app->tenant->legal_name }}</div></div><div class="muted">KFS v{{ $kfs->version }}<br>{{ $app->application_no }}<br>{{ $kfs->generated_at->format('d M Y') }}</div></div>
@include('livewire.customer.applications._kfs', ['t' => $kfs->terms, 'loc' => $locale ?? 'en'])
@if($kfs->acknowledged_at)<p class="muted">Acknowledged {{ $kfs->acknowledged_at->format('d M Y H:i') }} ({{ $kfs->acknowledged_locale }})</p>@endif
@endsection
