<div>
    <x-page-header title="Financial statements" :subtitle="($currentTenant->name ?? '').' · posted vouchers only · PKR'"/>
    <x-card class="mb-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            @if($tab !== 'bs')<x-input type="date" label="From" wire:model.live="from"/>@endif
            <x-input type="date" :label="$tab === 'bs' ? 'As of' : 'To'" wire:model.live="to"/>
            @if(in_array($tab, ['tb', 'pl', 'bs']))<x-select label="Branch" wire:model.live="branch_id" placeholder="Consolidated" :options="$branches"/>@endif
            @if($tab === 'gl')<x-select label="Account" wire:model.live="account_id" :options="$accounts" class="lg:col-span-2"/>@endif
            @if($tab === 'bva')<x-select label="Budget" wire:model.live="budget_id" placeholder="Select" :options="$budgets"/>@endif
            @if(in_array($tab, ['tb', 'pl', 'bs', 'gl']))@can('reports.export')<button wire:click="export" class="btn-secondary">Export CSV</button>@endcan @endif
        </div>
    </x-card>
    <x-tabs :tabs="['tb' => 'Trial balance', 'gl' => 'General ledger', 'pl' => 'Profit & loss', 'bs' => 'Balance sheet', 'cf' => 'Cash flow', 'bva' => 'Budget vs actual', 'targets' => 'Financing targeted vs actual', 'branches' => 'Branch P&L']" :active="$tab"/>

    @if($tab === 'tb')
        <x-card :padding="false" title="Trial balance" subtitle="Cumulative closing balances as of the To date (the From date applies to the P&L, cash flow and GL views).">

            <x-slot:actions>@if($data['balanced'])<x-badge tone="green">Balanced</x-badge>@else<x-badge tone="red">NOT balanced</x-badge>@endif</x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Code</x-th><x-th>Account</x-th><x-th>Type</x-th><x-th align="right">Debit</x-th><x-th align="right">Credit</x-th></x-slot:head>
                @forelse($data['rows'] as $r)
                    <tr><td><a href="{{ route('ops.finance.accounts.ledger', $r['account']) }}" wire:navigate class="link tabular">{{ $r['account']->code }}</a></td><td>{{ $r['account']->name }}</td><td>{{ $r['account']->type->label() }}</td>
                        <td class="text-right"><x-money :amount="$r['debit']" :currency="false"/></td><td class="text-right"><x-money :amount="$r['credit']" :currency="false"/></td></tr>
                @empty
                    <x-empty colspan="5" title="No postings in range"/>
                @endforelse
                <x-slot:foot><tr><td colspan="3" class="px-4 py-3 text-right">Totals</td><td class="px-4 py-3 text-right"><x-money :amount="$data['total_debit']"/></td><td class="px-4 py-3 text-right"><x-money :amount="$data['total_credit']"/></td></tr></x-slot:foot>
            </x-table>
        </x-card>
    @elseif($tab === 'gl')
        @if($data)
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Date</x-th><x-th>Voucher</x-th><x-th>Narration / source</x-th><x-th align="right">Debit</x-th><x-th align="right">Credit</x-th><x-th align="right">Balance</x-th></x-slot:head>
                <tr class="bg-neutral-50"><td colspan="5">Opening balance</td><td class="text-right"><x-money :amount="$data['opening']"/></td></tr>
                @forelse($data['rows'] as $r)
                    <tr><td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($r['line']->voucher_date)->format('d M Y') }}</td>
                        <td><a href="{{ route('ops.finance.journals.show', $r['line']->jv_id) }}" wire:navigate class="link tabular">{{ $r['line']->voucher_no }}</a></td>
                        <td class="max-w-md text-xs">{{ $r['line']->narration }}<p class="text-neutral-500">{{ $r['line']->source_type ? class_basename($r['line']->source_type).' #'.$r['line']->source_id : 'Manual' }}</p></td>
                        <td class="text-right"><x-money :amount="$r['line']->debit" :currency="false"/></td><td class="text-right"><x-money :amount="$r['line']->credit" :currency="false"/></td><td class="text-right"><x-money :amount="$r['balance']" tone="auto"/></td></tr>
                @empty
                    <x-empty colspan="6" title="No postings in range"/>
                @endforelse
                <x-slot:foot><tr><td colspan="5" class="px-4 py-3">Closing balance</td><td class="px-4 py-3 text-right"><x-money :amount="$data['closing']"/></td></tr></x-slot:foot>
            </x-table>
        </x-card>
        @endif
    @elseif($tab === 'pl')
        <x-card :padding="false">
            <x-table>
                <tr class="bg-neutral-50 font-semibold"><td colspan="2">Income</td></tr>
                @foreach($data['income'] as $r)<tr><td class="pl-8">{{ $r['account']->label() }}</td><td class="text-right"><x-money :amount="$r['amount']"/></td></tr>@endforeach
                <tr class="font-semibold"><td>Total income</td><td class="text-right"><x-money :amount="$data['total_income']"/></td></tr>
                <tr class="bg-neutral-50 font-semibold"><td colspan="2">Expenses</td></tr>
                @foreach($data['expenses'] as $r)<tr><td class="pl-8">{{ $r['account']->label() }}</td><td class="text-right"><x-money :amount="$r['amount']"/></td></tr>@endforeach
                <tr class="font-semibold"><td>Total expenses</td><td class="text-right"><x-money :amount="$data['total_expenses']"/></td></tr>
                <x-slot:foot><tr><td class="px-4 py-3">Net profit / (loss)</td><td class="px-4 py-3 text-right"><x-money :amount="$data['net_profit']" tone="auto"/></td></tr></x-slot:foot>
            </x-table>
        </x-card>
    @elseif($tab === 'bs')
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach(['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'] as $k => $title)
                <x-card :title="$title" :padding="false" @class(['lg:row-span-2' => $k === 'asset'])>
                    <x-table>
                        @foreach($data['sections'][$k] as $r)<tr><td>{{ $r['account']?->label() ?? $r['label'] }}</td><td class="text-right"><x-money :amount="$r['amount']" tone="auto"/></td></tr>@endforeach
                        <x-slot:foot><tr><td class="px-4 py-3">Total {{ strtolower($title) }}</td><td class="px-4 py-3 text-right"><x-money :amount="$data['totals'][$k]"/></td></tr></x-slot:foot>
                    </x-table>
                </x-card>
            @endforeach
        </div>
        <x-alert :tone="$data['balanced'] ? 'green' : 'red'" class="mt-6">Assets {{ \App\Support\Money::format($data['totals']['asset']) }} {{ $data['balanced'] ? '=' : '≠' }} Liabilities + equity {{ \App\Support\Money::format($data['liabilities_and_equity']) }}</x-alert>
    @elseif($tab === 'cf')
        <x-card title="Cash flow statement" subtitle="Movement in cash & bank accounts classified by the counterpart account's cash-flow category">
            <x-dl :cols="1" :items="[
                'Opening cash & bank' => \App\Support\Money::format($data['opening_cash']),
                'Net cash from operating activities' => \App\Support\Money::format($data['flows']['operating']),
                'Net cash from investing activities' => \App\Support\Money::format($data['flows']['investing']),
                'Net cash from financing activities' => \App\Support\Money::format($data['flows']['financing']),
                'Net change in cash' => \App\Support\Money::format($data['net_change']),
                'Closing cash & bank' => \App\Support\Money::format($data['closing_cash']),
            ]"/>
            <p class="mt-3 text-xs text-neutral-500">Includes the safeguarded wallet account (customer money); see Wallet reports for the safeguarding check.</p>
        </x-card>
    @elseif($tab === 'bva')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Account</x-th><x-th align="right">Budget</x-th><x-th align="right">Actual</x-th><x-th align="right">Variance</x-th></x-slot:head>
                @forelse($data as $r)<tr><td>{{ $r['account']->label() }}</td><td class="text-right"><x-money :amount="$r['budget']"/></td><td class="text-right"><x-money :amount="$r['actual']"/></td><td class="text-right"><x-money :amount="$r['variance']" tone="auto"/></td></tr>@empty<x-empty colspan="4" title="Select a budget with lines" />@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'targets')
        <x-card title="Financing disbursement – targeted vs actual" subtitle="Target = budget lines on the financing receivable account; actual = confirmed disbursements" :padding="false">
            <x-table>
                <x-slot:head><x-th>Month</x-th><x-th align="right">Target</x-th><x-th align="right">Actual</x-th><x-th align="right">Count</x-th><x-th align="right">Achievement</x-th></x-slot:head>
                @forelse($data as $r)
                    <tr><td>{{ $r['month'] }}</td><td class="text-right">@if($r['target'] !== null)<x-money :amount="$r['target']"/>@else <span class="text-neutral-400">no target</span>@endif</td><td class="text-right"><x-money :amount="$r['actual']"/></td><td class="text-right">{{ $r['count'] }}</td>
                        <td class="text-right">{{ $r['target'] && (float) $r['target'] ? round((float) $r['actual'] * 100 / (float) $r['target']).'%' : '—' }}</td></tr>
                @empty
                    <x-empty colspan="5"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'branches')
        <x-card title="Branch / cost-center P&L" subtitle="Lines carrying a branch dimension" :padding="false">
            <x-table>
                <x-slot:head><x-th>Branch</x-th><x-th align="right">Income</x-th><x-th align="right">Expenses</x-th><x-th align="right">Net</x-th></x-slot:head>
                @foreach($data as $b)<tr><td>{{ $b['branch']->name }}</td><td class="text-right"><x-money :amount="$b['pl']['total_income']"/></td><td class="text-right"><x-money :amount="$b['pl']['total_expenses']"/></td><td class="text-right"><x-money :amount="$b['pl']['net_profit']" tone="auto"/></td></tr>@endforeach
            </x-table>
        </x-card>
    @endif
</div>
