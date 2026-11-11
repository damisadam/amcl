<div>
    <x-page-header :title="$account->code.' · '.$account->name" :subtitle="$account->type->label().' account · '.($account->isDebitNormal() ? 'debit' : 'credit').'-normal'" :back="route('ops.finance.accounts')">
        <x-slot:actions>
            <x-input type="date" wire:model.live="from"/><x-input type="date" wire:model.live="to"/>
            @can('reports.export')<button wire:click="export" class="btn-secondary">Export CSV</button>@endcan
        </x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <x-table>
            <x-slot:head><x-th>Date</x-th><x-th>Voucher</x-th><x-th>Narration</x-th><x-th align="right">Debit</x-th><x-th align="right">Credit</x-th><x-th align="right">Balance</x-th></x-slot:head>
            <tr class="bg-neutral-50"><td colspan="5" class="font-medium">Opening balance</td><td class="text-right"><x-money :amount="$ledger['opening']" tone="auto"/></td></tr>
            @forelse($ledger['rows'] as $r)
                <tr>
                    <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($r['line']->voucher_date)->format('d M Y') }}</td>
                    <td><a href="{{ route('ops.finance.journals.show', $r['line']->jv_id) }}" wire:navigate class="link tabular">{{ $r['line']->voucher_no }}</a></td>
                    <td class="max-w-md"><p class="line-clamp-2">{{ $r['line']->narration }}</p></td>
                    <td class="text-right">@if((float) $r['line']->debit)<x-money :amount="$r['line']->debit" :currency="false"/>@endif</td>
                    <td class="text-right">@if((float) $r['line']->credit)<x-money :amount="$r['line']->credit" :currency="false"/>@endif</td>
                    <td class="text-right"><x-money :amount="$r['balance']" tone="auto"/></td>
                </tr>
            @empty
                <x-empty colspan="6" title="No postings in this range"/>
            @endforelse
            <x-slot:foot><tr><td colspan="5" class="px-4 py-3">Closing balance</td><td class="px-4 py-3 text-right"><x-money :amount="$ledger['closing']" tone="auto"/></td></tr></x-slot:foot>
        </x-table>
    </x-card>
</div>
