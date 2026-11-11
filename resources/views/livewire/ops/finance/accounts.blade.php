<div>
    <x-page-header title="Chart of accounts" subtitle="This institution's own chart (independent of every other tenant). Balances are in each account's natural direction.">
        <x-slot:actions>
            <x-input type="date" wire:model.live="asOf"/>
            @can('fms.coa.manage')<button wire:click="newAccount" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>Add account</button>@endcan
        </x-slot:actions>
    </x-page-header>
    <x-tabs :tabs="['accounts' => 'Accounts', 'cost_centers' => 'Cost centers']" :active="$tab"/>
    @if($tab === 'accounts')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Code / name</x-th><x-th>Type</x-th><x-th>System role</x-th><x-th>Cash-flow</x-th><x-th align="right">Balance</x-th><x-th></x-th></x-slot:head>
                @foreach($tree as $row)
                    @php($a = $row['account'])
                    <tr wire:key="acc-{{ $a->id }}" @class(['bg-neutral-50 font-semibold' => ! $a->is_postable, 'opacity-50' => ! $a->is_active])>
                        <td style="padding-left: {{ 1 + $row['depth'] * 1.5 }}rem">
                            @if($a->is_postable)<a href="{{ route('ops.finance.accounts.ledger', $a) }}" wire:navigate class="link tabular">{{ $a->code }}</a>@else<span class="tabular">{{ $a->code }}</span>@endif
                            {{ $a->name }} @unless($a->is_active)<x-badge>Inactive</x-badge>@endunless
                        </td>
                        <td>{{ $a->type->label() }}</td>
                        <td class="text-xs text-neutral-500">{{ $a->system_role }}</td>
                        <td class="text-xs">{{ $a->cash_flow_category }}</td>
                        <td class="text-right"><x-money :amount="$balances[$a->id] ?? 0" tone="auto"/></td>
                        <td class="text-right whitespace-nowrap">
                            @can('fms.coa.manage')
                                <button wire:click="editAccount({{ $a->id }})" class="link text-xs">Edit</button>
                                @unless($a->is_postable)<button wire:click="newAccount({{ $a->id }})" class="link ml-2 text-xs">+ Child</button>@endunless
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @else
        <x-card :padding="false">
            <x-slot:actions>@can('fms.coa.manage')<button wire:click="editCc" class="btn-secondary btn-sm">Add cost center</button>@endcan</x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Code</x-th><x-th>Name</x-th><x-th>Business line</x-th><x-th>Branch</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                @forelse($costCenters as $c)
                    <tr><td class="tabular">{{ $c->code }}</td><td>{{ $c->name }}</td><td>{{ $c->business_line }}</td><td>{{ $c->branch?->name }}</td>
                        <td><x-status :value="$c->is_active ? 'active' : 'inactive'"/></td>
                        <td class="text-right">@can('fms.coa.manage')<button wire:click="editCc({{ $c->id }})" class="link text-xs">Edit</button>@endcan</td></tr>
                @empty
                    <x-empty colspan="6" title="No cost centers"/>
                @endforelse
            </x-table>
        </x-card>
    @endif

    <x-modal show="showAccount" :title="$editingId ? 'Edit account' : 'New account'">
        @error('action')<x-alert tone="red" class="mb-3">{{ $message }}</x-alert>@enderror
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Code" wire:model="form.code" required/>
            <x-input label="Name" wire:model="form.name" required/>
            <x-select label="Type" wire:model="form.type" :options="$types"/>
            <x-select label="Parent" wire:model="form.parent_id" placeholder="— top level —" :options="$parents"/>
            <x-select label="Cash-flow category" wire:model="form.cash_flow_category" placeholder="None" :options="['operating' => 'Operating', 'investing' => 'Investing', 'financing' => 'Financing']"/>
            <x-input label="Description" wire:model="form.description"/>
            <x-checkbox wire:model="form.is_postable" label="Postable (leaf account)"/>
            <x-checkbox wire:model="form.is_active" label="Active"/>
        </div>
        <x-slot:footer><button wire:click="$set('showAccount', false)" class="btn-secondary">Cancel</button><button wire:click="saveAccount" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>

    <x-modal show="showCc" title="Cost center">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Code" wire:model="cc.code"/>
            <x-input label="Name" wire:model="cc.name"/>
            <x-select label="Business line" wire:model="cc.business_line" placeholder="—" :options="['lending' => 'Lending', 'wallet' => 'Wallet', 'corporate' => 'Corporate']"/>
            <x-select label="Branch" wire:model="cc.branch_id" placeholder="—" :options="$branches"/>
            <x-checkbox wire:model="cc.is_active" label="Active"/>
        </div>
        <x-slot:footer><button wire:click="$set('showCc', false)" class="btn-secondary">Cancel</button><button wire:click="saveCc" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>
</div>
