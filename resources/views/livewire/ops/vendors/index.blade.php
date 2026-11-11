<div>
    <x-page-header :title="__('Vendors')" :subtitle="__('Asset suppliers (disbursement recipients), operational suppliers and wallet merchants.')">
        <x-slot:actions>@can('vendors.manage')<a href="{{ route('ops.vendors.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New vendor') }}</a>@endcan</x-slot:actions>
    </x-page-header>
    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="__('Active')" :value="$counts['active'] ?? 0"/>
        <x-stat :label="__('Pending approval')" :value="$counts['pending'] ?? 0" tone="amber" :href="route('ops.vendors.index', ['filterStatus' => 'pending'])"/>
        <x-stat :label="__('Inactive')" :value="$counts['inactive'] ?? 0" tone="gray"/>
        <x-stat :label="__('Rejected / blacklisted')" :value="($counts['rejected'] ?? 0) + ($counts['blacklisted'] ?? 0)" tone="red"/>
    </div>
    <x-card :padding="false">
        <div class="flex flex-wrap gap-2 border-b border-neutral-100 p-3">
            <x-input class="w-60" wire:model.live.debounce.400ms="search" :placeholder="__('Name, code, city, merchant code…')"/>
            <x-select wire:model.live="filterStatus" :placeholder="__('All statuses')" :options="\App\Enums\VendorStatus::options()"/>
            <x-select wire:model.live="filterKind" :placeholder="__('All kinds')" :options="['asset' => 'Asset supplier', 'operational' => 'Operational']"/>
            <x-select wire:model.live="filterCategory" :placeholder="__('All categories')" :options="\App\Livewire\Ops\Vendors\VendorCategories::ALL"/>
        </div>
        <x-table>
            <x-slot:head><x-th sort="business_name" :current="$sort" :dir="$dir">{{ __('Vendor') }}</x-th><x-th>{{ __('Category / kind') }}</x-th><x-th>{{ __('City') }}</x-th><x-th>{{ __('Bank') }}</x-th>
                <x-th>{{ __('Items') }}</x-th><x-th>{{ __('Financed cases') }}</x-th><x-th>{{ __('Source') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
            @forelse($vendors as $v)
                <tr wire:key="v{{ $v->id }}">
                    <td><a href="{{ route('ops.vendors.show', $v) }}" wire:navigate class="link">{{ $v->business_name }}</a><div class="text-xs text-neutral-500">{{ $v->code }} @if($v->is_merchant)· <x-badge tone="blue">{{ __('Merchant') }} {{ $v->merchant_code }}</x-badge>@endif</div></td>
                    <td class="text-xs">{{ \App\Livewire\Ops\Vendors\VendorCategories::ALL[$v->category] ?? $v->category }}<div class="text-neutral-500">{{ ucfirst($v->kind) }}</div></td>
                    <td class="text-xs">{{ $v->city }}</td>
                    <td class="text-xs">{{ $v->bank_name }}<div class="text-neutral-500">{{ $v->iban ? '****'.substr($v->iban, -4) : '—' }}</div></td>
                    <td>{{ $v->items_count }}</td><td>{{ $v->applications_count }}</td>
                    <td class="text-xs">{{ $v->source === 'web_request' ? __('Web request') : __('Internal') }}</td>
                    <td><x-status :value="$v->status"/></td>
                </tr>
            @empty
                <x-empty :colspan="8" :title="__('No vendors found')"/>
            @endforelse
        </x-table>
        {{ $vendors->links() }}
    </x-card>
</div>
