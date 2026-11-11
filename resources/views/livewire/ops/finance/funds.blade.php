<div>
    <x-page-header title="Funds & investors" subtitle="Funding sources, utilization against disbursed financing, investor reporting and Shariah-compliant profit distribution.">
        <x-slot:actions>@can('fms.funds.manage')<button wire:click="edit" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>Add funding source</button>@endcan</x-slot:actions>
    </x-page-header>
    <x-tabs :tabs="['sources' => 'Funding sources', 'report' => 'Investor / lender report', 'distribution' => 'Profit distribution']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($tab === 'sources')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Fund</x-th><x-th>Type / structure</x-th><x-th align="right">Committed</x-th><x-th align="right">Utilized</x-th><x-th align="right">Available</x-th><x-th>Investor share</x-th><x-th></x-th></x-slot:head>
                @forelse($funds as $f)
                    <tr><td class="font-medium">{{ $f['fund']->name }}<p class="text-xs text-neutral-500">{{ $f['fund']->code }} · {{ $f['fund']->provider_name }}</p></td>
                        <td class="text-xs">{{ \Illuminate\Support\Str::headline($f['fund']->type) }}<p>{{ ucfirst($f['fund']->structure ?? '') }}</p></td>
                        <td class="text-right"><x-money :amount="$f['fund']->committed_amount"/></td><td class="text-right"><x-money :amount="$f['used']" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$f['available']" tone="auto"/></td><td>{{ $f['fund']->investor_profit_share_pct ? $f['fund']->investor_profit_share_pct.'%' : '—' }}</td>
                        <td class="whitespace-nowrap text-right">@can('fms.funds.manage')<button wire:click="askReceipt({{ $f['fund']->id }})" class="btn-secondary btn-sm">Record receipt</button> <button wire:click="edit({{ $f['fund']->id }})" class="link text-xs">Edit</button>@endcan</td></tr>
                @empty
                    <x-empty colspan="7" title="No funding sources"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'report')
        <div class="mb-4 w-72"><x-select wire:model.live="reportFund" placeholder="Select fund" :options="$fundOptions"/></div>
        @if($report)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat label="Financings" :value="$report['accounts']" :hint="$report['closed'].' closed'"/>
                <x-stat label="Disbursed" :value="\App\Support\Money::format($report['disbursed'])"/>
                <x-stat label="Principal outstanding" :value="\App\Support\Money::format($report['outstanding'])" tone="black"/>
                <x-stat label="PAR 30" :value="$report['par30'].'%'" :tone="$report['par30'] > 5 ? 'red' : 'green'"/>
                <x-stat label="Collections received" :value="\App\Support\Money::format($report['collections'])"/>
                <x-stat label="Profit collected" :value="\App\Support\Money::format(number_format($report['profit_collected'], 2, '.', ''))" tone="amber"/>
            </div>
        @else
            <x-empty title="Select a funding source to see its performance"/>
        @endif
    @else
        @can('fms.funds.manage')
            <x-card title="Calculate distribution" class="mb-6">
                <div class="grid gap-3 sm:grid-cols-3 sm:items-end">
                    <x-select label="Fund" wire:model="distFund" placeholder="Select" :options="$fundOptions"/>
                    <x-select label="Period" wire:model="distPeriod" placeholder="Select" :options="$periods"/>
                    <button wire:click="calculate" class="btn-primary">Calculate</button>
                </div>
                <p class="mt-2 text-xs text-neutral-500">Investor share = fund's profit-sharing ratio × profit income attributed to the fund in the period (Mudarabah-style; not treated as interest expense). Assumption recorded in docs/DECISIONS.md.</p>
            </x-card>
        @endcan
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Fund</x-th><x-th>Period</x-th><x-th align="right">Gross profit</x-th><x-th>Share</x-th><x-th align="right">Investor share</x-th><x-th align="right">WHT</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                @forelse($distributions as $d)
                    <tr><td>{{ $d->fund->name }}</td><td>{{ $d->period->name }}</td><td class="text-right"><x-money :amount="$d->gross_profit"/></td><td>{{ $d->investor_share_pct }}%</td>
                        <td class="text-right"><x-money :amount="$d->investor_share"/></td><td class="text-right"><x-money :amount="$d->wht_amount" :currency="false"/></td><td><x-status :value="$d->status"/></td>
                        <td>@if($d->status === 'calculated')@can('fms.funds.manage')<button wire:click="post({{ $d->id }})" wire:confirm="Post this distribution to the ledger?" class="btn-primary btn-sm">Post</button>@endcan @endif</td></tr>
                @empty
                    <x-empty colspan="8" title="No distributions calculated"/>
                @endforelse
            </x-table>
        </x-card>
    @endif
    <x-modal show="showForm" :title="$fundId ? 'Edit funding source' : 'New funding source'">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Code" wire:model="form.code"/><x-input label="Name" wire:model="form.name"/>
            <x-select label="Type" wire:model="form.type" :options="['own_equity' => 'Own equity', 'investor' => 'Investor fund', 'bank_line' => 'Bank line', 'donor' => 'Donor fund']"/>
            <x-input label="Provider" wire:model="form.provider_name"/>
            <x-input label="Committed amount" wire:model="form.committed_amount"/>
            <x-select label="Structure" wire:model="form.structure" :options="['mudarabah' => 'Mudarabah', 'musharakah' => 'Musharakah', 'wakalah' => 'Wakalah', 'equity' => 'Equity']"/>
            <x-input label="Investor profit share %" wire:model="form.investor_profit_share_pct"/>
            <x-select label="Status" wire:model="form.status" :options="['active' => 'Active', 'closed' => 'Closed']"/>
            <x-input type="date" label="Start" wire:model="form.start_date"/><x-input type="date" label="End" wire:model="form.end_date"/>
            <x-textarea label="Terms" wire:model="form.terms" class="sm:col-span-2"/>
        </div>
        <x-slot:footer><button wire:click="$set('showForm', false)" class="btn-secondary">Cancel</button><button wire:click="save" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>
    <x-modal show="showReceipt" title="Record fund receipt">
        <p class="mb-3 text-sm text-neutral-600">Posts Dr Operating bank / Cr fund liability (or equity / bank line).</p>
        <x-input label="Amount received (PKR)" wire:model="receiptAmount"/>
        <x-slot:footer><button wire:click="$set('showReceipt', false)" class="btn-secondary">Cancel</button><button wire:click="recordReceipt" class="btn-primary">Post receipt</button></x-slot:footer>
    </x-modal>
</div>
