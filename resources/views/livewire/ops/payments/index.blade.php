<div wire:poll.30s>
    <x-page-header :title="__('Payment orders')" :subtitle="__('Every external money movement: initiation → provider → callback → business handler. Providers are SIMULATED in this environment.')"/>
    <x-tabs :tabs="['orders' => __('Payment orders'), 'callbacks' => __('Callback inbox')]" :active="$tab"/>

    @if($tab === 'orders')
        <div class="mb-4 grid gap-3 sm:grid-cols-5">
            <x-stat :label="__('Orders')" :value="number_format($sums['count'])"/>
            <x-stat :label="__('Succeeded value')" :value="\App\Support\Money::format($sums['succeeded'])"/>
            <x-stat :label="__('Open / pending')" :value="$sums['open']" tone="amber"/>
            <x-stat :label="__('Failed')" :value="$sums['failed']" tone="red"/>
            <x-stat :label="__('Reconciliation exceptions')" :value="$sums['exceptions']" tone="red"/>
        </div>
        <x-card :padding="false">
            <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-5">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Reference / provider ref / counterparty') }}" class="field-input">
                <select wire:model.live="filterPurpose" class="field-input"><option value="">{{ __('All purposes') }}</option>@foreach($purposes as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <select wire:model.live="filterProvider" class="field-input"><option value="">{{ __('All providers') }}</option>@foreach($providers as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <select wire:model.live="filterStatus" class="field-input"><option value="">{{ __('All statuses') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <select wire:model.live="filterRecon" class="field-input"><option value="">{{ __('Any reconciliation') }}</option><option value="unreconciled">{{ __('Unreconciled') }}</option><option value="matched">{{ __('Matched') }}</option><option value="exception">{{ __('Exception') }}</option></select>
            </div>
            <x-table>
                <x-slot:head>
                    <x-th sort="id" :current="$sort" :dir="$dir">{{ __('Reference') }}</x-th><x-th>{{ __('Purpose') }}</x-th><x-th>{{ __('Provider') }}</x-th><x-th>{{ __('Counterparty') }}</x-th>
                    <x-th sort="status" :current="$sort" :dir="$dir">{{ __('Status') }}</x-th><x-th>{{ __('Recon') }}</x-th><x-th sort="amount" :current="$sort" :dir="$dir" align="right">{{ __('Amount') }}</x-th><x-th sort="created_at" :current="$sort" :dir="$dir">{{ __('Created') }}</x-th>
                </x-slot:head>
                @forelse($orders as $o)
                    <tr wire:key="po{{ $o->id }}">
                        <td><a href="{{ route('ops.payments.show', $o) }}" wire:navigate class="link tabular text-xs">{{ $o->reference }}</a><div class="text-[11px] text-neutral-500">{{ $o->direction }} · {{ __('scenario') }} {{ $o->scenario }}</div></td>
                        <td class="text-xs">{{ $o->purpose->label() }}</td>
                        <td class="text-xs">{{ config("integrations.providers.{$o->provider}.name", $o->provider) }}</td>
                        <td class="max-w-[12rem] truncate text-xs">{{ $o->counterparty_name }}</td>
                        <td><x-status :value="$o->status"/>@if($o->failure_code)<div class="text-[11px] text-red-600">{{ $o->failure_code }}</div>@endif</td>
                        <td><x-status :value="$o->reconciliation_status"/></td>
                        <td class="text-right"><x-money :amount="$o->amount"/></td>
                        <td class="text-xs text-neutral-500">{{ $o->created_at->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <x-empty :colspan="8" :title="__('No payment orders')" :description="__('Orders appear when customers top up, withdraw or repay, and when disbursements or vendor payments are released.')"/>
                @endforelse
            </x-table>
            {{ $orders->links() }}
        </x-card>
    @else
        <x-card :padding="false" :title="__('Provider callback inbox')" :subtitle="__('Each provider event is processed at most once; duplicates and invalid callbacks are recorded, never applied.')">
            <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Event id / provider ref') }}" class="field-input">
                <select wire:model.live="filterProvider" class="field-input"><option value="">{{ __('All providers') }}</option>@foreach($providers as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                <select wire:model.live="filterResult" class="field-input"><option value="">{{ __('All results') }}</option>@foreach(['processed','duplicate','rejected','ignored','error'] as $r)<option value="{{ $r }}">{{ ucfirst($r) }}</option>@endforeach</select>
            </div>
            <x-table>
                <x-slot:head><x-th>{{ __('Received') }}</x-th><x-th>{{ __('Provider') }}</x-th><x-th>{{ __('Event id') }}</x-th><x-th>{{ __('Order') }}</x-th><x-th>{{ __('Reported') }}</x-th><x-th>{{ __('Signature') }}</x-th><x-th>{{ __('Result') }}</x-th></x-slot:head>
                @forelse($callbacks as $cb)
                    <tr wire:key="cb{{ $cb->id }}">
                        <td class="text-xs text-neutral-500">{{ $cb->received_at->format('d M H:i:s') }}</td>
                        <td class="text-xs">{{ $cb->provider }}</td>
                        <td class="max-w-[16rem] truncate text-xs tabular">{{ $cb->event_id }}</td>
                        <td class="text-xs">@if($cb->paymentOrder)<a href="{{ route('ops.payments.show', $cb->paymentOrder) }}" wire:navigate class="link">{{ $cb->paymentOrder->reference }}</a>@else<span class="text-neutral-400">—</span>@endif</td>
                        <td class="text-xs">{{ $cb->reported_status }} · {{ $cb->reported_amount !== null ? \App\Support\Money::format($cb->reported_amount) : '' }}</td>
                        <td>{!! $cb->signature_valid ? '<span class="text-brand-700 text-xs">valid</span>' : '<span class="text-red-600 text-xs">invalid</span>' !!}</td>
                        <td><x-status :value="$cb->result"/>@if($cb->error)<div class="text-[11px] text-neutral-500">{{ $cb->error }}</div>@endif</td>
                    </tr>
                @empty
                    <x-empty :colspan="7" :title="__('No callbacks received yet')"/>
                @endforelse
            </x-table>
            {{ $callbacks->links() }}
        </x-card>
    @endif
</div>
