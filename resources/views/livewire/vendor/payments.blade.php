<div>
    <x-page-header :title="__('Payments received')" :subtitle="__('Payouts from the institution to your bank account. Provider references are SIMULATED in this demo – no real money moved.')"/>
    @if($inFlight->isNotEmpty())
        <x-card :title="__('Payouts in progress / failed')" class="mb-6" :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Order') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Note') }}</x-th></x-slot:head>
                @foreach($inFlight as $d)
                    <tr><td>{{ $d->reference }}</td><td><a class="link" wire:navigate href="{{ route('vendor.orders.show', $d->loan_application_id) }}">{{ $d->application?->application_no }}</a></td>
                        <td><x-status :value="$d->status"/></td><td class="text-right"><x-money :amount="$d->net_amount"/></td><td class="text-xs text-neutral-500">{{ $d->failure_reason ?? __('Awaiting bank confirmation') }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
    <x-card :padding="false">
        <div class="flex flex-wrap gap-3 border-b border-neutral-100 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="field-input max-w-xs" placeholder="{{ __('Search payment no or purpose…') }}">
            <select wire:model.live="filterStatus" class="field-input max-w-[200px]"><option value="">{{ __('All statuses') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
        </div>
        <x-table>
            <x-slot:head><x-th sort="id" :current="$sort" :dir="$dir">{{ __('Payment') }}</x-th><x-th>{{ __('Purpose') }}</x-th><x-th>{{ __('Method') }}</x-th><x-th>{{ __('Status') }}</x-th>
                <x-th>{{ __('Provider ref (simulated)') }}</x-th><x-th>{{ __('Reconciliation') }}</x-th><x-th sort="net_amount" :current="$sort" :dir="$dir" align="right">{{ __('Amount') }}</x-th><x-th></x-th></x-slot:head>
            @forelse($payments as $p)
                <tr>
                    <td>{{ $p->payment_no }}<div class="text-xs text-neutral-500">{{ $p->paid_at?->format('d M Y H:i') }}</div></td>
                    <td class="max-w-xs text-xs">{{ $p->purpose }}</td>
                    <td class="text-xs uppercase">{{ $p->method }}</td>
                    <td><x-status :value="$p->status"/>@if($p->failure_reason)<div class="text-[11px] text-red-600">{{ $p->failure_reason }}</div>@endif</td>
                    <td class="font-mono text-[11px]">{{ $p->paymentOrder?->provider_ref ?? '—' }}</td>
                    <td><x-status :value="$p->paymentOrder?->reconciliation_status"/></td>
                    <td class="text-right"><x-money :amount="$p->net_amount"/>@if(\App\Support\Money::isPositive($p->wht_amount))<div class="text-[11px] text-neutral-500">{{ __('WHT') }} <x-money :amount="$p->wht_amount"/></div>@endif</td>
                    <td>@if($p->status->value === 'paid')<a href="{{ route('vendor.payments.receipt', $p) }}" target="_blank" class="link text-xs">{{ __('Receipt') }}</a>@endif</td>
                </tr>
            @empty
                <x-empty colspan="8" :title="__('No payments yet')"/>
            @endforelse
        </x-table>
        {{ $payments->links() }}
    </x-card>
</div>
