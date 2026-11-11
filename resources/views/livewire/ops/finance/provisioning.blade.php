<div>
    <x-page-header title="Provisioning & write-off" subtitle="Classification by days past due with tenant-configured provisioning rates (SECP prudential-style; confirm official rates).">
        <x-slot:actions>@can('fms.provisioning.manage')<button wire:click="run" wire:confirm="Run provisioning as of today and post movements?" class="btn-primary">Run provisioning</button>@endcan</x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <x-card title="Portfolio classification" :padding="false">
        <x-table>
            <x-slot:head><x-th>Classification</x-th><x-th align="right">Accounts</x-th><x-th align="right">Principal outstanding</x-th><x-th align="right">Rate</x-th><x-th align="right">Required</x-th><x-th align="right">Held (last run)</x-th></x-slot:head>
            @foreach($summary as $s)
                <tr><td><x-status :value="$s['classification']"/></td><td class="text-right tabular">{{ $s['count'] }}</td><td class="text-right"><x-money :amount="$s['outstanding']"/></td>
                    <td class="text-right">{{ $s['rate'] }}%</td><td class="text-right"><x-money :amount="$s['required']"/></td><td class="text-right"><x-money :amount="$s['held']"/></td></tr>
            @endforeach
        </x-table>
    </x-card>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Provisioning runs" :padding="false">
            <x-table>
                <x-slot:head><x-th>As of</x-th><x-th align="right">Loans</x-th><x-th align="right">Required</x-th><x-th align="right">Movement posted</x-th></x-slot:head>
                @forelse($history as $h)
                    <tr><td>{{ \Carbon\Carbon::parse($h->as_of)->format('d M Y') }}</td><td class="text-right tabular">{{ $h->n }}</td><td class="text-right"><x-money :amount="$h->req"/></td><td class="text-right"><x-money :amount="$h->mov" tone="auto"/></td></tr>
                @empty
                    <x-empty colspan="4" title="No provisioning runs yet"/>
                @endforelse
            </x-table>
        </x-card>
        <x-card title="Loss-classified financing" subtitle="Eligible for a write-off proposal" :padding="false">
            <x-table>
                <x-slot:head><x-th>Account</x-th><x-th>Customer</x-th><x-th align="right">DPD</x-th><x-th align="right">Principal</x-th><x-th></x-th></x-slot:head>
                @forelse($lossLoans as $l)
                    <tr><td class="tabular">{{ $l->account_no }}</td><td>{{ $l->customer->full_name }}</td><td class="text-right">{{ $l->dpd }}</td><td class="text-right"><x-money :amount="$l->principal_outstanding"/></td>
                        <td>@can('fms.provisioning.manage')<button wire:click="askWriteOff({{ $l->id }})" class="btn-danger btn-sm">Propose write-off</button>@endcan</td></tr>
                @empty
                    <x-empty colspan="5" title="No loss-classified accounts"/>
                @endforelse
            </x-table>
        </x-card>
    </div>
    <x-card title="Write-off requests" class="mt-6" :padding="false">
        <x-table>
            <x-slot:head><x-th>Ref</x-th><x-th>Account</x-th><x-th align="right">Amount</x-th><x-th>Reason</x-th><x-th>Requested by</x-th><x-th>Status</x-th></x-slot:head>
            @forelse($writeOffs as $w)
                <tr><td class="tabular">{{ $w->reference }}</td><td>{{ $w->loanAccount?->account_no }}</td><td class="text-right"><x-money :amount="$w->amount"/></td><td class="max-w-xs text-xs">{{ $w->reason }}</td><td class="text-xs">{{ $w->requester?->name }}</td><td><x-status :value="$w->status"/></td></tr>
            @empty
                <x-empty colspan="6" title="No write-off requests"/>
            @endforelse
        </x-table>
    </x-card>
    <x-modal show="showWriteOff" title="Propose write-off">
        <x-alert>The proposal goes through the write-off approval hierarchy (Risk → Finance → Tenant Admin). On approval the provision is utilized and any excess is expensed.</x-alert>
        <x-textarea label="Justification" wire:model="reason" class="mt-3"/>
        <x-slot:footer><button wire:click="$set('showWriteOff', false)" class="btn-secondary">Cancel</button><button wire:click="requestWriteOff" class="btn-danger">Submit</button></x-slot:footer>
    </x-modal>
</div>
