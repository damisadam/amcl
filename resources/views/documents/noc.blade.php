@extends('documents._layout')
@section('title', 'NOC '.$loan->noc_no)
@section('body')
<div class="hdr"><div><h1>{{ $tenant->name }}</h1><div class="muted">{{ $tenant->legal_name }} · {{ $tenant->address }}</div></div>
<div class="muted">NOC No. {{ $loan->noc_no }}<br>{{ $loan->noc_issued_at?->format('d M Y') }}</div></div>
<h2>No Objection Certificate</h2>
<p>This is to certify that <strong>{{ $loan->customer->full_name }}</strong> (CNIC {{ $loan->customer->maskedCnic() }}) has fully repaid financing account
<strong>{{ $loan->account_no }}</strong> ({{ $loan->product->name }}, financed amount {{ \App\Support\Money::format($loan->principal) }}, disbursed {{ $loan->disbursed_at->format('d M Y') }}).</p>
<p>All instalments have been received and no amount remains outstanding. {{ $tenant->name }} has no objection to the release of any lien or title held against the financed asset.</p>
<table><tr><th>Total paid</th><td class="num">{{ \App\Support\Money::format($loan->total_paid) }}</td><th>Closed on</th><td>{{ $loan->closed_at?->format('d M Y') }}</td></tr></table>
<div class="sig"><div>Authorised signatory</div><div>Operations</div></div>
@endsection
