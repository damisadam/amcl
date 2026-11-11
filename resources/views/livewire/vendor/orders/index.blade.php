<div>
    <x-page-header :title="__('Financing orders')" :subtitle="__('Customers buying your items on financing. Credit decisions are made by the institution.')">
        <x-slot:actions><a href="{{ route('vendor.originate') }}" wire:navigate class="btn-primary">+ {{ __('New customer request') }}</a></x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="flex flex-wrap gap-3 border-b border-neutral-100 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="field-input max-w-xs" placeholder="{{ __('Search customer, reference, item…') }}">
            <select wire:model.live="filterStatus" class="field-input max-w-[220px]"><option value="">{{ __('All statuses') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
        </div>
        <x-table>
            <x-slot:head><x-th sort="id" :current="$sort" :dir="$dir">{{ __('Reference') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Item') }}</x-th>
                <x-th sort="requested_amount" :current="$sort" :dir="$dir" align="right">{{ __('Financed') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Payout') }}</x-th></x-slot:head>
            @forelse($orders as $o)
                @php($d = $o->disbursements->sortByDesc('id')->first())
                <tr>
                    <td><a href="{{ route('vendor.orders.show', $o) }}" wire:navigate class="link">{{ $o->application_no }}</a><div class="text-xs text-neutral-500">{{ $o->created_at->format('d M Y') }}</div></td>
                    <td>{{ $o->customer->full_name }}<div class="text-xs text-neutral-500">{{ $o->customer->maskedCnic() }}</div></td>
                    <td class="text-sm">{{ $o->asset_description }}<div class="text-xs text-neutral-500">{{ $o->product->name }}</div></td>
                    <td class="text-right"><x-money :amount="$o->requested_amount"/></td>
                    <td><x-badge :tone="\App\Livewire\Vendor\Concerns\VendorOrderStatus::tone($o->status)">{{ \App\Livewire\Vendor\Concerns\VendorOrderStatus::label($o->status) }}</x-badge></td>
                    <td>@if($d)<x-status :value="$d->status"/>@else<span class="text-xs text-neutral-400">—</span>@endif</td>
                </tr>
            @empty
                <x-empty colspan="6" :title="__('No financing orders')" :description="__('Requests you start for customers will appear here.')"/>
            @endforelse
        </x-table>
        {{ $orders->links() }}
    </x-card>
</div>
