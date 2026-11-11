<div>
    <x-page-header title="Budgets & forecasting" subtitle="Annual budgets by account and period; budget vs actual from posted ledger figures.">
        <x-slot:actions>@can('fms.budgets.manage')<button wire:click="create" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>New budget</button>@endcan</x-slot:actions>
    </x-page-header>
    <x-tabs :tabs="['budgets' => 'Budgets', 'forecast' => 'Forecast (3 months)']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($tab === 'budgets')
        @if($editing)
            <x-card :title="$budgetId ? 'Edit budget' : 'New budget'" class="mb-6">
                <div class="grid gap-3 sm:grid-cols-3"><x-select label="Fiscal year" wire:model.live="fiscalYear" :options="$years"/><x-input label="Name" wire:model="name" class="sm:col-span-2"/></div>
                <div class="relative mt-4 overflow-x-auto"><table class="table-base">
                    <thead><tr><th>Account</th><th>Period</th><th>Cost center</th><th class="text-right">Amount</th><th></th></tr></thead>
                    <tbody>
                    @foreach($lines as $i => $l)
                        <tr wire:key="bl-{{ $i }}">
                            <td><select wire:model="lines.{{ $i }}.account_id" class="field-input"><option value="">Select</option>@foreach($accounts as $id => $lab)<option value="{{ $id }}">{{ $lab }}</option>@endforeach</select></td>
                            <td><select wire:model="lines.{{ $i }}.accounting_period_id" class="field-input"><option value="">Whole year (spread evenly)</option>@foreach($periods as $id => $lab)<option value="{{ $id }}">{{ $lab }}</option>@endforeach</select></td>
                            <td><select wire:model="lines.{{ $i }}.cost_center_id" class="field-input"><option value="">—</option>@foreach($costCenters as $id => $lab)<option value="{{ $id }}">{{ $lab }}</option>@endforeach</select></td>
                            <td><input wire:model="lines.{{ $i }}.amount" class="field-input w-36 text-right"></td>
                            <td><button wire:click="removeLine({{ $i }})" class="text-xs text-red-600">Remove</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div class="mt-3 flex justify-between"><button wire:click="addLine" class="btn-secondary btn-sm">Add line</button>
                    <div class="flex gap-2"><button wire:click="$set('editing', false)" class="btn-secondary">Cancel</button><button wire:click="save" class="btn-primary">Save budget</button></div></div>
            </x-card>
        @endif
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Budget</x-th><x-th>Fiscal year</x-th><x-th align="right">Total</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                @forelse($budgets as $b)
                    <tr><td class="font-medium">{{ $b->name }}</td><td>{{ $b->fiscalYear->name }}</td><td class="text-right"><x-money :amount="$b->lines_sum_amount ?? 0"/></td><td><x-status :value="$b->status"/></td>
                        <td class="whitespace-nowrap text-right"><button wire:click="$set('viewId', {{ $b->id }})" class="link text-xs">Budget vs actual</button>
                            @can('fms.budgets.manage')@if($b->status === 'draft')<button wire:click="edit({{ $b->id }})" class="link ml-2 text-xs">Edit</button>
                                <button wire:click="approve({{ $b->id }})" class="btn-secondary btn-sm ml-2">Approve</button>@endif @endcan</td></tr>
                @empty
                    <x-empty colspan="5" title="No budgets yet"/>
                @endforelse
            </x-table>
        </x-card>
        @if($bva !== null)
            <x-card :title="'Budget vs actual – '.$viewBudget->name" subtitle="Actual = posted ledger balance for the budgeted periods to date" class="mt-6" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Account</x-th><x-th align="right">Budget</x-th><x-th align="right">Actual</x-th><x-th align="right">Variance</x-th><x-th align="right">Used</x-th></x-slot:head>
                    @forelse($bva as $r)
                        @php($pct = (float) $r['budget'] ? round((float) $r['actual'] * 100 / (float) $r['budget']) : null)
                        @php($over = $r['account']->type->value === 'expense' ? \App\Support\Money::lt($r['variance'], 0) : \App\Support\Money::gt($r['variance'], 0))
                        <tr><td>{{ $r['account']->label() }}</td><td class="text-right"><x-money :amount="$r['budget']"/></td><td class="text-right"><x-money :amount="$r['actual']"/></td>
                            <td class="text-right"><span @class(['text-red-600 font-semibold' => $over, 'text-brand-700' => ! $over])><x-money :amount="$r['variance']"/></span></td>
                            <td class="text-right">{{ $pct !== null ? $pct.'%' : '—' }}</td></tr>
                    @empty
                        <x-empty colspan="5" title="This budget has no lines"/>
                    @endforelse
                </x-table>
            </x-card>
        @endif
    @else
        <x-card title="Projected inflows & outflows" subtitle="Collections from repayment schedules; disbursements from the approval pipeline" :padding="false">
            <x-table>
                <x-slot:head><x-th>Month</x-th><x-th align="right">Scheduled collections</x-th><x-th align="right">Expected disbursements</x-th><x-th align="right">Net</x-th></x-slot:head>
                @foreach($forecast as $f)
                    <tr><td>{{ $f['month'] }}</td><td class="text-right"><x-money :amount="$f['collections']"/></td><td class="text-right"><x-money :amount="$f['disbursements']"/></td>
                        <td class="text-right"><x-money :amount="\App\Support\Money::sub($f['collections'], $f['disbursements'])" tone="auto"/></td></tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
</div>
