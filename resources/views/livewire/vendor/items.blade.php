<div>
    <x-page-header :title="__('Products & items')" :subtitle="__('Items customers can buy from you on financing. Prices are used to calculate the financed amount.')">
        <x-slot:actions><button wire:click="create" class="btn-primary">+ {{ __('Add item') }}</button></x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="border-b border-neutral-100 p-4"><input type="search" wire:model.live.debounce.300ms="search" class="field-input max-w-xs" placeholder="{{ __('Search items…') }}"></div>
        <x-table>
            <x-slot:head><x-th sort="name" :current="$sort" :dir="$dir">{{ __('Item') }}</x-th><x-th>{{ __('SKU') }}</x-th><x-th>{{ __('Category') }}</x-th>
                <x-th sort="price" :current="$sort" :dir="$dir" align="right">{{ __('Price') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
            @forelse($items as $i)
                <tr><td class="font-medium">{{ $i->name }}</td><td class="text-xs">{{ $i->sku ?: '—' }}</td><td class="text-xs">{{ $i->product_category ? ($categories[$i->product_category] ?? $i->product_category) : '—' }}</td>
                    <td class="text-right"><x-money :amount="$i->price"/>@if($i->pending_price !== null)<div class="text-xs text-amber-700">{{ __('Pending approval') }}: <x-money :amount="$i->pending_price"/></div>@endif</td><td><x-badge :tone="$i->is_active ? 'green' : 'gray'">{{ $i->is_active ? __('Active') : __('Inactive') }}</x-badge></td>
                    <td class="whitespace-nowrap text-right"><button wire:click="edit({{ $i->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button>
                        <button wire:click="toggle({{ $i->id }})" class="btn-ghost btn-sm">{{ $i->is_active ? __('Deactivate') : __('Activate') }}</button></td></tr>
            @empty
                <x-empty colspan="6" :title="__('No items yet')" :description="__('Add the items you sell so customers can finance them.')"/>
            @endforelse
        </x-table>
        {{ $items->links() }}
    </x-card>
    <x-modal show="showForm" :title="$editingId ? __('Edit item') : __('Add item')">
        <form wire:submit="save" class="space-y-4" id="item-form">
            <x-input :label="__('Name')" wire:model="form.name" required/>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input :label="__('SKU')" wire:model="form.sku"/>
                <x-input :label="__('Price (PKR)')" type="number" step="0.01" wire:model="form.price" :hint="__('A new or changed price is sent to the institution for approval. Submitted applications keep their agreed price.')" required/>
            </div>
            <x-select :label="__('Category')" wire:model="form.product_category" :placeholder="__('Select…')" :options="$categories"/>
            <x-checkbox wire:model="form.is_active" :label="__('Active')"/>
        </form>
        <x-slot:footer><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button type="submit" form="item-form" class="btn-primary">{{ __('Save') }}</button></x-slot:footer>
    </x-modal>
</div>
