@extends('documents._layout')
@section('title', 'Payment advice '.$payment->payment_no)
@section('body')
<div class="hdr"><div><h1>{{ $tenant->name }}</h1><div class="muted">{{ $tenant->legal_name }} · {{ $tenant->address }}</div></div>
<div class="muted">Payment advice<br>{{ $payment->payment_no }}<br>{{ $payment->paid_at?->format('d M Y H:i') }}</div></div>
<h2>Paid to</h2>
<p><strong>{{ $vendor->business_name }}</strong> ({{ $vendor->code }})<br>{{ $vendor->account_title }} · {{ $vendor->bank_name }} · IBAN ****{{ substr((string) $vendor->iban, -4) }}</p>
<table>
<tr><th>Purpose</th><td>{{ $payment->purpose }}</td></tr>
<tr><th>Gross amount</th><td class="num">{{ \App\Support\Money::format($payment->amount) }}</td></tr>
<tr><th>Withholding tax</th><td class="num">{{ \App\Support\Money::format($payment->wht_amount) }}</td></tr>
<tr><th>Net paid</th><td class="num"><strong>{{ \App\Support\Money::format($payment->net_amount) }}</strong></td></tr>
<tr><th>Method</th><td>{{ strtoupper($payment->method) }}</td></tr>
<tr><th>Provider reference</th><td>{{ $payment->paymentOrder?->provider_ref }} (SIMULATED)</td></tr>
</table>
@endsection
