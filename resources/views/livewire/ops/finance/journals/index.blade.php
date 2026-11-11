<div>
    <x-page-header title="Journal vouchers" subtitle="System postings from LMS, DFS, Wallet and AP, plus manual vouchers under maker-checker control.">
        <x-slot:actions>
            @can('reports.export')<button wire:click="export" class="btn-secondary">Export CSV</button>@endcan
            @can('fms.journals.create')<a href="{{ route('ops.finance.journals.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>New voucher</a>@endcan
        </x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="grid gap-3 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-6">
            <x-input wire:model.live.debounce.400ms="search" placeholder="Voucher no, narration, event…" class="lg:col-span-2"/>
            <x-select wire:model.live="filterStatus" placeholder="All statuses" :options="$statuses"/>
            <x-select wire:model.live="filterOrigin" placeholder="All origins" :options="['manual' => 'Manual', 'system' => 'System']"/>
            <x-select wire:model.live="filterModule" placeholder="All modules" :options="$modules"/>
            <div class="flex min-w-0 flex-wrap gap-2"><x-input type="date" wire:model.live="filterFrom" class="min-w-[9rem] flex-1"/><x-input type="date" wire:model.live="filterTo" class="min-w-[9rem] flex-1"/></div>
        </div>
        <x-table>
            <x-slot:head>
                <x-th sort="voucher_no" :current="$sort" :dir="$dir">Voucher</x-th><x-th sort="voucher_date" :current="$sort" :dir="$dir">Date</x-th>
                <x-th>Narration</x-th><x-th>Source</x-th><x-th sort="total_debit" :current="$sort" :dir="$dir" align="right">Amount</x-th><x-th>Status</x-th>
            </x-slot:head>
            @forelse($vouchers as $jv)
                <tr wire:key="jv-{{ $jv->id }}">
                    <td><a href="{{ route('ops.finance.journals.show', $jv) }}" wire:navigate class="link tabular">{{ $jv->voucher_no }}</a></td>
                    <td class="whitespace-nowrap">{{ $jv->voucher_date->format('d M Y') }}</td>
                    <td class="max-w-md"><p class="line-clamp-2">{{ $jv->narration }}</p><p class="text-xs text-neutral-500">by {{ $jv->creator?->name ?? 'System' }}</p></td>
                    <td><x-badge :tone="$jv->origin === 'manual' ? 'blue' : 'gray'">{{ ucfirst($jv->origin) }}</x-badge>
                        <div class="mt-1 text-xs text-neutral-500">{{ strtoupper($jv->source_module ?? '') }} {{ $jv->event_type }}</div></td>
                    <td class="text-right"><x-money :amount="$jv->total_debit"/></td>
                    <td><x-status :value="$jv->status"/></td>
                </tr>
            @empty
                <x-empty colspan="6" title="No vouchers match these filters"/>
            @endforelse
        </x-table>
        {{ $vouchers->links() }}
    </x-card>
</div>
