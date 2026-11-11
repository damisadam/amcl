@extends('documents._layout')
@section('title', 'Receipt '.($kind === 'loan' ? $p->receipt_no : $p->reference))
@section('body')
<div class="hdr"><div><h1>{{ $tenant->name }}</h1><div class="muted">{{ $tenant->legal_name }} · {{ $tenant->address }}</div></div>
<div class="muted">{{ $kind === 'loan' ? 'Receipt '.$p->receipt_no : 'Transaction '.$p->reference }}<br>{{ ($kind === 'loan' ? $p->received_at : $p->completed_at ?? $p->created_at)?->format('d M Y H:i') }}</div></div>
@if($kind === 'loan')
<h2>Instalment payment receipt</h2>
<table>
<tr><th>Customer</th><td>{{ $p->customer->full_name }} (CIF {{ $p->customer->cif_no }})</td></tr>
<tr><th>Financing account</th><td>{{ $p->loanAccount->account_no }} – {{ $p->loanAccount->product->name }}</td></tr>
<tr><th>Amount received</th><td class="num"><strong>{{ \App\Support\Money::format($p->amount) }}</strong></td></tr>
<tr><th>Channel</th><td>{{ $p->channel->label() }} @if($p->external_ref) – ref {{ $p->external_ref }}@endif</td></tr>
<tr><th>Payment reference</th><td>{{ $p->reference }}</td></tr>
<tr><th>Status</th><td>{{ $p->status->label() }}@if($p->status->value === 'reversed') – {{ $p->reversal_reason }}@endif</td></tr>
</table>
<h2>Allocation</h2>
<table><tr><th>Instalment</th><th>Component</th><th class="num">Amount</th></tr>
@foreach($p->allocations as $a)<tr><td>#{{ $a->instalment->number }} ({{ $a->instalment->due_date->format('d M Y') }})</td><td>{{ \Illuminate\Support\Str::headline($a->component === 'late_charge' ? 'late payment (charity)' : $a->component) }}</td><td class="num">{{ \App\Support\Money::format($a->amount) }}</td></tr>@endforeach
@if(\App\Support\Money::isPositive($p->unallocated_amount))<tr><td colspan="2">Excess held as advance</td><td class="num">{{ \App\Support\Money::format($p->unallocated_amount) }}</td></tr>@endif
</table>
<p class="muted">Outstanding after this payment: {{ \App\Support\Money::format($p->loanAccount->totalOutstanding()) }}</p>
@else
<h2>Wallet transaction receipt</h2>
<table>
<tr><th>Wallet</th><td>{{ $p->wallet->wallet_no }} – {{ $p->wallet->title }}</td></tr>
<tr><th>Type</th><td>{{ $p->type->label() }} ({{ $p->direction }})</td></tr>
<tr><th>Description</th><td>{{ $p->description }}</td></tr>
<tr><th>Amount</th><td class="num"><strong>{{ \App\Support\Money::format($p->amount) }}</strong></td></tr>
@if(\App\Support\Money::isPositive($p->fee))<tr><th>Fee</th><td class="num">{{ \App\Support\Money::format($p->fee) }}</td></tr>@endif
<tr><th>Status</th><td>{{ $p->status->label() }}</td></tr>
@if($p->paymentOrder)<tr><th>Provider reference</th><td>{{ $p->paymentOrder->provider_ref }} ({{ strtoupper($p->paymentOrder->provider) }}, simulated)</td></tr>@endif
</table>
@endif
@endsection
