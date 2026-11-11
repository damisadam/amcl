<div>
    <x-card title="Item prices awaiting approval" subtitle="Vendor price changes only take effect once approved; submitted applications keep their agreed price." :padding="false">
        <x-table>
            <x-slot:head><x-th>Item</x-th><x-th align="right">Current</x-th><x-th align="right">Requested</x-th><x-th>Requested</x-th><x-th></x-th></x-slot:head>
            @forelse($items as $i)
                <tr>
                    <td>{{ $i->name }}@unless($vendorId)<div class="text-xs text-neutral-500">{{ $i->vendor?->business_name }}</div>@endunless @if(! $i->is_active)<x-badge tone="amber">New item</x-badge>@endif</td>
                    <td class="text-right"><x-money :amount="$i->price"/></td>
                    <td class="text-right font-semibold"><x-money :amount="$i->pending_price"/></td>
                    <td class="text-xs text-neutral-500">{{ $i->pending_price_requested_at?->format('d M Y H:i') }}</td>
                    <td class="text-right whitespace-nowrap">
                        @if(auth()->user()->hasPermission('vendors.manage'))
                            <button wire:click="approve({{ $i->id }})" class="btn-primary btn-sm">Approve</button>
                            <button wire:click="reject({{ $i->id }})" class="btn-secondary btn-sm">Reject</button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-empty colspan="5" title="No pending price changes"/>
            @endforelse
        </x-table>
    </x-card>
</div>
