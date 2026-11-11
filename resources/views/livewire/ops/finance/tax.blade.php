<div>
    <x-page-header title="Tax management" subtitle="Withholding tax on vendor payments, investor profit distributions and salaries – derived from the general ledger. FBR schedules are SIMULATED formats.">
        <x-slot:actions><x-input type="date" wire:model.live="from"/><x-input type="date" wire:model.live="to"/>@can('reports.export')<button wire:click="export" class="btn-secondary">Export vendor WHT schedule</button>@endcan</x-slot:actions>
    </x-page-header>
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="WHT withheld" :value="\App\Support\Money::format($withheld)"/>
        <x-stat label="Deposited / adjusted" :value="\App\Support\Money::format($deposited)" tone="black"/>
        <x-stat label="Payable to FBR" :value="\App\Support\Money::format(\App\Support\Money::sub($withheld, $deposited))" tone="amber"/>
    </div>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="WHT payable movements by month & source" :padding="false">
            <x-table>
                <x-slot:head><x-th>Month</x-th><x-th>Source event</x-th><x-th align="right">Withheld</x-th><x-th align="right">Deposited</x-th></x-slot:head>
                @forelse($movements as $m)
                    <tr><td>{{ $m->m }}</td><td>{{ \Illuminate\Support\Str::headline($m->e) }}</td><td class="text-right"><x-money :amount="$m->withheld"/></td><td class="text-right"><x-money :amount="$m->deposited"/></td></tr>
                @empty
                    <x-empty colspan="4" title="No WHT postings in this range"/>
                @endforelse
            </x-table>
        </x-card>
        <x-card title="Vendor WHT schedule" :padding="false">
            <x-table>
                <x-slot:head><x-th>Date</x-th><x-th>Vendor / NTN</x-th><x-th>Bill</x-th><x-th align="right">Gross</x-th><x-th align="right">WHT</x-th></x-slot:head>
                @forelse($schedule as $b)
                    <tr><td>{{ $b->bill_date->format('d M Y') }}</td><td>{{ $b->vendor->business_name }}<p class="text-xs text-neutral-500">{{ $b->vendor->ntn ?? '—' }}</p></td><td class="tabular">{{ $b->bill_no }}</td>
                        <td class="text-right"><x-money :amount="$b->amount"/></td><td class="text-right"><x-money :amount="$b->wht_amount"/></td></tr>
                @empty
                    <x-empty colspan="5" title="No vendor bills with WHT"/>
                @endforelse
            </x-table>
        </x-card>
    </div>
    <x-alert tone="amber" class="mt-6">Sales tax / FED on fee income and the final FBR return formats are not configured in this release – see docs/KNOWN_LIMITATIONS.md.</x-alert>
</div>
