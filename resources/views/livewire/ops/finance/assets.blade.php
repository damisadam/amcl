<div>
    <x-page-header title="Fixed assets" subtitle="Company-owned furniture, IT equipment and vehicles (not customer-financed assets). Straight-line depreciation.">
        <x-slot:actions>
            @can('fms.assets.manage')
                <x-select wire:model="period" placeholder="Period" :options="$periods"/>
                <button wire:click="depreciate" wire:confirm="Post depreciation for the selected period?" class="btn-secondary">Run depreciation</button>
                <button wire:click="$set('showForm', true)" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>Add asset</button>
            @endcan
        </x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($byBranch as $b)
            <x-stat :label="$b->b" :value="\App\Support\Money::format(\App\Support\Money::sub($b->cost, $b->acc))" :hint="$b->n.' assets · cost '.\App\Support\Money::format($b->cost)"/>
        @endforeach
    </div>
    <x-card :padding="false" title="Asset register">
        <x-slot:actions><x-select wire:model.live="filterBranch" placeholder="All branches" :options="$branches"/></x-slot:actions>
        <x-table>
            <x-slot:head><x-th>Code</x-th><x-th>Asset</x-th><x-th>Branch</x-th><x-th>Acquired</x-th><x-th align="right">Cost</x-th><x-th align="right">Acc. depreciation</x-th><x-th align="right">Net book value</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
            @forelse($assets as $a)
                <tr><td class="tabular">{{ $a->asset_code }}</td><td>{{ $a->name }}<p class="text-xs text-neutral-500">{{ \Illuminate\Support\Str::headline($a->category) }} · {{ $a->useful_life_months }} months</p></td>
                    <td class="text-xs">{{ $a->branch?->name }}</td><td>{{ $a->acquired_on->format('d M Y') }}</td><td class="text-right"><x-money :amount="$a->cost"/></td>
                    <td class="text-right"><x-money :amount="$a->accumulated_depreciation" :currency="false"/></td><td class="text-right"><x-money :amount="\App\Support\Money::sub($a->cost, $a->accumulated_depreciation)"/></td>
                    <td><x-status :value="$a->status"/></td>
                    <td>@if($a->status === 'active')@can('fms.assets.manage')<button wire:click="askDispose({{ $a->id }})" class="link text-xs">Dispose</button>@endcan @endif</td></tr>
            @empty
                <x-empty colspan="9" title="No fixed assets registered"/>
            @endforelse
        </x-table>
        {{ $assets->links() }}
    </x-card>
    <x-modal show="showForm" title="Register asset">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Name" wire:model="form.name" class="sm:col-span-2"/>
            <x-select label="Category" wire:model="form.category" :options="['furniture' => 'Furniture', 'it_equipment' => 'IT equipment', 'vehicle' => 'Vehicle', 'building' => 'Building']"/>
            <x-select label="Branch" wire:model="form.branch_id" placeholder="Head office" :options="$branches"/>
            <x-input type="date" label="Acquired on" wire:model="form.acquired_on"/>
            <x-input label="Cost (PKR)" wire:model="form.cost"/>
            <x-input label="Salvage value" wire:model="form.salvage_value"/>
            <x-input label="Useful life (months)" wire:model="form.useful_life_months"/>
        </div>
        <x-slot:footer><button wire:click="$set('showForm', false)" class="btn-secondary">Cancel</button><button wire:click="acquire" class="btn-primary">Capitalise</button></x-slot:footer>
    </x-modal>
    <x-modal show="showDispose" title="Dispose asset">
        <x-input label="Disposal proceeds (PKR)" wire:model="proceeds"/>
        <p class="mt-2 text-xs text-neutral-500">Gain or loss versus net book value is posted automatically.</p>
        <x-slot:footer><button wire:click="$set('showDispose', false)" class="btn-secondary">Cancel</button><button wire:click="dispose" class="btn-danger">Dispose</button></x-slot:footer>
    </x-modal>
</div>
