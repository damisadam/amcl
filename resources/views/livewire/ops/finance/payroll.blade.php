<div>
    <x-page-header title="Payroll & HR finance" subtitle="Monthly salary runs with statutory deductions (simplified demo rules), maker-checker approval and GL posting. No live payroll integration.">
        <x-slot:actions>@can('fms.payroll.manage')<x-input type="month" wire:model="period"/><button wire:click="prepare" class="btn-primary">Prepare payroll</button>@endcan</x-slot:actions>
    </x-page-header>
    <x-tabs :tabs="['runs' => 'Payroll runs', 'staff' => 'Staff loan deduction reconciliation']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($tab === 'runs')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Period</x-th><x-th align="right">Gross</x-th><x-th align="right">Income tax</x-th><x-th align="right">PF & EOBI</x-th><x-th align="right">Net pay</x-th><x-th>Prepared by</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                @forelse($runs as $r)
                    <tr><td class="font-medium">{{ $r->period }}</td><td class="text-right"><x-money :amount="$r->total_gross"/></td><td class="text-right"><x-money :amount="$r->total_tax" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$r->total_deductions" :currency="false"/></td><td class="text-right"><x-money :amount="$r->total_net"/></td><td class="text-xs">{{ $r->creator?->name }}</td>
                        <td><x-status :value="$r->status"/></td><td><button wire:click="show({{ $r->id }})" class="link text-xs">{{ $runId === $r->id ? 'Hide' : 'Lines' }}</button></td></tr>
                @empty
                    <x-empty colspan="8" title="No payroll runs"/>
                @endforelse
            </x-table>
        </x-card>
        @if($run)
            <x-card :title="'Payroll '.$run->period.' – employee lines'" class="mt-6" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Employee</x-th><x-th align="right">Basic</x-th><x-th align="right">Allowances</x-th><x-th align="right">Tax</x-th><x-th align="right">PF</x-th><x-th align="right">EOBI</x-th><x-th align="right">Net</x-th></x-slot:head>
                    @foreach($run->lines as $l)
                        <tr><td>{{ $l->employee?->name }}<p class="text-xs text-neutral-500">{{ $l->employee?->designation }}</p></td><td class="text-right"><x-money :amount="$l->basic" :currency="false"/></td>
                            <td class="text-right"><x-money :amount="$l->allowances" :currency="false"/></td><td class="text-right"><x-money :amount="$l->income_tax" :currency="false"/></td>
                            <td class="text-right"><x-money :amount="$l->provident_fund" :currency="false"/></td><td class="text-right"><x-money :amount="$l->eobi" :currency="false"/></td><td class="text-right"><x-money :amount="$l->net"/></td></tr>
                    @endforeach
                </x-table>
            </x-card>
        @endif
    @else
        <x-card :padding="false" title="Salary deductions vs instalments received" :subtitle="$staffCount.' corporate staff on record'">
            <x-table>
                <x-slot:head><x-th>Salary month</x-th><x-th align="right">Deducted by employers</x-th><x-th align="right">Received as repayments</x-th><x-th align="right">Difference</x-th></x-slot:head>
                @forelse($recon as $r)
                    <tr><td>{{ $r['period'] }}</td><td class="text-right"><x-money :amount="$r['deducted']"/></td><td class="text-right"><x-money :amount="$r['received']"/></td>
                        <td class="text-right"><x-money :amount="$r['difference']" tone="auto"/></td></tr>
                @empty
                    <x-empty colspan="4" title="No staff salary records"/>
                @endforelse
            </x-table>
        </x-card>
    @endif
</div>
