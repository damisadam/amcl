<div>
    <x-page-header title="Treasury & bank management" subtitle="Operating, disbursement, collection and safeguarded accounts. All bank figures in the demo are SIMULATED."/>
    <x-tabs :tabs="['accounts' => 'Bank accounts', 'transfers' => 'Fund transfers', 'settlements' => 'Settlement statements', 'liquidity' => 'Liquidity (30 days)']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror

    @if($tab === 'accounts')
        <div class="grid gap-4 md:grid-cols-2">
            @foreach($banks as $b)
                <x-card>
                    <div class="flex items-start justify-between">
                        <div><p class="font-semibold">{{ $b['bank']->name }}</p><p class="text-xs text-neutral-500">{{ $b['bank']->bank_name }} · {{ $b['bank']->iban }}</p>
                            <p class="mt-1 text-xs">GL: {{ $b['bank']->account?->label() }}</p></div>
                        <x-badge :tone="$b['bank']->purpose === 'safeguarded' ? 'amber' : 'gray'">{{ ucfirst($b['bank']->purpose) }}</x-badge>
                    </div>
                    <p class="mt-3 text-2xl font-bold tabular"><x-money :amount="$b['balance']" tone="auto"/></p>
                    <div class="mt-1 flex items-center justify-between text-xs text-neutral-500">
                        <span>Minimum {{ \App\Support\Money::format($b['bank']->minimum_balance) }} @if($b['below_minimum'])<x-badge tone="red">Below minimum</x-badge>@endif</span>
                        @can('fms.treasury.manage')<button wire:click="editBank({{ $b['bank']->id }})" class="link">Edit</button>@endcan
                    </div>
                    @if($b['bank']->purpose === 'safeguarded')<p class="mt-2 text-xs text-amber-700">Holds customer wallet money – legally separate from company funds; cannot be used for internal transfers.</p>@endif
                </x-card>
            @endforeach
        </div>
    @elseif($tab === 'transfers')
        <div class="grid gap-6 lg:grid-cols-3">
            @can('fms.treasury.manage')
            <x-card title="Request transfer">
                <form wire:submit="requestTransfer" class="space-y-3">
                    <x-select label="From" wire:model="transfer.from" placeholder="Select" :options="$options"/>
                    <x-select label="To" wire:model="transfer.to" placeholder="Select" :options="$options"/>
                    <x-input label="Amount (PKR)" wire:model="transfer.amount" inputmode="decimal"/>
                    <x-textarea label="Purpose" wire:model="transfer.purpose" rows="2"/>
                    <button class="btn-primary w-full">Submit for approval</button>
                </form>
            </x-card>
            @endcan
            <x-card title="Recent transfers" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Ref</x-th><x-th>From → To</x-th><x-th align="right">Amount</x-th><x-th>Requested by</x-th><x-th>Status</x-th></x-slot:head>
                    @forelse($transfers as $t)
                        <tr><td class="tabular">{{ $t->reference }}</td><td class="text-xs">{{ $t->fromAccount?->name }} → {{ $t->toAccount?->name }}<p class="text-neutral-500">{{ $t->purpose }}</p></td>
                            <td class="text-right"><x-money :amount="$t->amount"/></td><td class="text-xs">{{ $t->requester?->name }}</td><td><x-status :value="$t->status"/></td></tr>
                    @empty
                        <x-empty colspan="5" title="No fund transfers yet"/>
                    @endforelse
                </x-table>
            </x-card>
        </div>
    @elseif($tab === 'settlements')
        <x-card :padding="false" title="Settlement statement lines" subtitle="SIMULATED bank / Raast settlement records used for reconciliation">
            <x-slot:actions><x-select wire:model.live="filterBank" placeholder="All accounts" :options="$bankFilter"/></x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Value date</x-th><x-th>Account</x-th><x-th>Provider ref</x-th><x-th>Narration</x-th><x-th>Dr/Cr</x-th><x-th align="right">Amount</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($settlements as $s)
                    <tr><td>{{ $s->value_date->format('d M Y') }}</td><td class="text-xs">{{ $s->bankAccount?->name }}</td><td class="tabular text-xs">{{ $s->provider_ref }}</td>
                        <td class="text-xs">{{ $s->narration }}</td><td><x-badge :tone="$s->direction === 'credit' ? 'green' : 'gray'">{{ ucfirst($s->direction) }}</x-badge></td>
                        <td class="text-right"><x-money :amount="$s->amount"/></td><td><x-status :value="$s->status"/></td></tr>
                @empty
                    <x-empty colspan="7" title="No settlement lines"/>
                @endforelse
            </x-table>
            {{ $settlements->links() }}
        </x-card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-stat label="Available cash (non-safeguarded)" :value="\App\Support\Money::format($p['cash'])" tone="black"/>
            <x-stat label="Instalments due next 30 days" :value="\App\Support\Money::format($p['inflow'])" :hint="'Plus overdue '.\App\Support\Money::format($p['overdue'])"/>
            <x-stat label="Committed disbursements" :value="\App\Support\Money::format($p['disbursements'])" :hint="$p['disbursement_count'].' approved / pending cases'" tone="amber"/>
            <x-stat label="Vendor payments in pipeline" :value="\App\Support\Money::format($p['vendor'])" tone="amber"/>
            <x-stat label="Projected net position" :value="\App\Support\Money::format($p['net'])" :tone="\App\Support\Money::lt($p['net'], 0) ? 'red' : 'green'" hint="Cash + inflows − committed outflows"/>
        </div>
        @if(\App\Support\Money::lt($p['net'], 0))<x-alert tone="red" class="mt-4" title="Liquidity shortfall">Committed outflows exceed projected cash. Consider a fund receipt or fund transfer.</x-alert>@endif
    @endif

    <x-modal show="showBank" title="Edit bank account">
        <div class="space-y-3"><x-input label="Name" wire:model="bank.name"/><x-input label="Minimum balance alert (PKR)" wire:model="bank.minimum_balance"/><x-checkbox wire:model="bank.is_active" label="Active"/></div>
        <x-slot:footer><button wire:click="$set('showBank', false)" class="btn-secondary">Cancel</button><button wire:click="saveBank" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>
</div>
