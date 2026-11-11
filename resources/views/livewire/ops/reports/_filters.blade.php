<x-card class="mb-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <x-input type="date" label="From" wire:model.live="from"/>
        <x-input type="date" label="To" wire:model.live="to"/>
        @isset($branches)<x-select label="Branch" wire:model.live="branch_id" placeholder="All branches" :options="$branches"/>@endisset
        @isset($products)<x-select label="Product" wire:model.live="product_id" placeholder="All products" :options="$products"/>@endisset
        @can('reports.export')<button wire:click="export" class="btn-secondary">Export CSV</button>@endcan
    </div>
</x-card>
