@extends('documents._layout')
@section('title', $type->name.' – '.$app->application_no)
@section('body')
@php($t = $kfs?->terms ?? [])
<div class="hdr"><div><h1>{{ $app->tenant->name }}</h1><div class="muted">{{ $app->tenant->legal_name }} · {{ $app->tenant->license_ref }}</div></div>
<div class="muted">{{ $type->name }}<br>Ref {{ $app->application_no }}<br>{{ ($kfs?->generated_at ?? $app->approved_at ?? $app->created_at)?->format('d M Y') }}</div></div>
<h2>Parties</h2>
<p><strong>Financier:</strong> {{ $app->tenant->legal_name ?? $app->tenant->name }}<br>
<strong>Customer:</strong> {{ $app->customer->full_name }} (CNIC {{ $app->customer->maskedCnic() }}, CIF {{ $app->customer->cif_no }})<br>
<strong>Signer:</strong> {{ $signer }}</p>
<h2>Financing terms ({{ $t['structure'] ?? '' }})</h2>
<table>
<tr><th>Product</th><td>{{ $t['product'] ?? $app->product->name }}</td><th>Asset</th><td>{{ $t['asset'] ?? $app->asset_description }}</td></tr>
<tr><th>Financed amount</th><td class="num">{{ \App\Support\Money::format($t['financed_amount'] ?? $app->financedAmount()) }}</td><th>Disclosed profit rate</th><td>{{ $t['profit_rate'] ?? '' }}% p.a. ({{ $t['profit_method'] ?? '' }})</td></tr>
<tr><th>Tenure</th><td>{{ $t['tenure'] ?? $app->tenure }} {{ ($t['frequency'] ?? 'monthly') === 'weekly' ? 'weeks' : 'months' }}</td><th>Instalment</th><td class="num">{{ \App\Support\Money::format($t['instalment'] ?? 0) }}</td></tr>
<tr><th>Total payable</th><td class="num">{{ \App\Support\Money::format($t['total_payable'] ?? 0) }}</td><th>APR</th><td>{{ $t['apr'] ?? '' }}%</td></tr>
</table>
<h2>Key terms</h2>
<ol>
@if(($t['structure'] ?? '') === 'Murabaha')
<li>The Financier purchases the asset from the vendor and sells it to the Customer at the disclosed cost plus profit. Ownership and risk pass to the Customer on delivery.</li>
@else
<li>The financing is structured as {{ $t['structure'] ?? 'disclosed' }} in accordance with the Financier's Shariah-approved product terms.</li>
@endif
<li>The Customer shall pay instalments on the due dates in the repayment schedule through any channel offered by the Financier.</li>
<li>{{ $t['late_payment'] ?? '' }}</li>
<li>The Customer may cancel within the {{ $t['cooling_off_hours'] ?? 24 }}-hour cooling-off period without any markup or charge.</li>
<li>Grievances: {{ $t['grievance'] ?? '' }}</li>
</ol>
<div class="sig"><div>For {{ $app->tenant->name }}</div><div>{{ $signer }} (e-signed via simulated NIFT eSign)</div></div>
@endsection
