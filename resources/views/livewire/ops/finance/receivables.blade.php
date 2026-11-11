<div>
    <x-page-header title="Accounts receivable" subtitle="Financing receivables come from the LMS / DFS sub-ledger and are reconciled to the general ledger."/>
    <x-tabs :tabs="['portfolio' => 'Loan portfolio', 'aging' => 'Aging', 'other' => 'Other receivables']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($tab === 'portfolio')
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-stat label="Principal outstanding (sub-ledger)" :value="\App\Support\Money::format($subledger)"/>
            <x-stat label="Financing receivable (GL, net of deferred profit & charges)" :value="\App\Support\Money::format($gl)" tone="black"/>
            <x-stat label="Difference" :value="\App\Support\Money::format($difference)" :tone="\App\Support\Money::isZero($difference) ? 'green' : 'red'" :hint="\App\Support\Money::isZero($difference) ? 'Sub-ledger agrees with GL' : 'Investigate: write-offs, manual vouchers or timing'"/>
        </div>
        <div class="mb-3 w-64"><x-select wire:model.live="branch" placeholder="All branches" :options="$branches"/></div>
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach(['By product' => $byProduct, 'By branch' => $byBranch] as $title => $rows)
                <x-card :title="$title" :padding="false">
                    <x-table>
                        <x-slot:head><x-th></x-th><x-th align="right">Accounts</x-th><x-th align="right">Principal</x-th><x-th align="right">Profit</x-th><x-th align="right">Charges & late</x-th></x-slot:head>
                        @forelse($rows as $r)
                            <tr><td>{{ $r->k }}</td><td class="text-right tabular">{{ $r->n }}</td><td class="text-right"><x-money :amount="$r->p" :currency="false"/></td><td class="text-right"><x-money :amount="$r->pr" :currency="false"/></td><td class="text-right"><x-money :amount="$r->c" :currency="false"/></td></tr>
                        @empty
                            <x-empty colspan="5" title="No open financing"/>
                        @endforelse
                    </x-table>
                </x-card>
            @endforeach
        </div>
    @elseif($tab === 'aging')
        <x-card title="Days-past-due aging (principal outstanding)" :padding="false">
            <x-table>
                <x-slot:head><x-th>Bucket</x-th><x-th align="right">Accounts</x-th><x-th align="right">Outstanding</x-th></x-slot:head>
                @foreach($aging as $a)<tr><td>{{ $a['bucket'] }}</td><td class="text-right tabular">{{ $a['count'] }}</td><td class="text-right"><x-money :amount="$a['outstanding']"/></td></tr>@endforeach
            </x-table>
        </x-card>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            @if(auth()->user()->hasAnyPermission(['fms.payables.manage', 'fms.treasury.manage']))
            <x-card title="New receivable">
                <form wire:submit="create" class="space-y-3">
                    <x-input label="Party" wire:model="form.party_name"/>
                    <x-select label="Kind" wire:model="form.kind" :options="['staff_advance' => 'Staff advance', 'insurance_claim' => 'Insurance claim', 'partner_due' => 'Amount due from partner', 'other' => 'Other']"/>
                    <x-input label="Amount paid out" wire:model="form.amount" inputmode="decimal"/>
                    <x-input type="date" label="Due date" wire:model="form.due_date"/>
                    <x-textarea label="Notes" wire:model="form.notes" rows="2"/>
                    <button class="btn-primary w-full">Record & post</button>
                </form>
            </x-card>
            @endif
            <x-card title="Other receivables" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Ref</x-th><x-th>Party</x-th><x-th>Due</x-th><x-th align="right">Amount</x-th><x-th align="right">Received</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                    @forelse($others as $o)
                        <tr><td class="tabular">{{ $o->reference }}</td><td>{{ $o->party_name }}<p class="text-xs text-neutral-500">{{ \Illuminate\Support\Str::headline($o->kind) }}</p></td>
                            <td @class(['text-red-600' => $o->due_date->isPast() && $o->status !== 'settled'])>{{ $o->due_date->format('d M Y') }}</td>
                            <td class="text-right"><x-money :amount="$o->amount"/></td><td class="text-right"><x-money :amount="$o->received_amount" :currency="false"/></td><td><x-status :value="$o->status"/></td>
                            <td>@if($o->status !== 'settled' && auth()->user()->hasAnyPermission(['fms.payables.manage', 'fms.treasury.manage']))<button wire:click="askReceive({{ $o->id }})" class="btn-secondary btn-sm">Receive</button>@endif</td></tr>
                    @empty
                        <x-empty colspan="7" title="No other receivables"/>
                    @endforelse
                </x-table>
            </x-card>
        </div>
    @endif
    <x-modal show="showReceive" title="Record receipt">
        <x-input label="Amount received" wire:model="receiveAmount"/>
        <x-slot:footer><button wire:click="$set('showReceive', false)" class="btn-secondary">Cancel</button><button wire:click="receive" class="btn-primary">Post receipt</button></x-slot:footer>
    </x-modal>
</div>
