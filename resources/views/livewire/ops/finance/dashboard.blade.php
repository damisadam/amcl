<div wire:poll.60s>
    <x-page-header title="Finance overview" subtitle="Live figures from posted ledger entries. Bank balances are SIMULATED settlement accounts.">
        <x-slot:actions>
            @can('fms.journals.create')<a href="{{ route('ops.finance.journals.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>New journal voucher</a>@endcan
            @can('reports.fms')<a href="{{ route('ops.reports.financial') }}" wire:navigate class="btn-secondary">Financial statements</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Income (month to date)" :value="\App\Support\Money::format($pl['total_income'])" tone="green" :hint="'Last month: '.\App\Support\Money::format($plLast['total_income']).' · profit & fees recognised on collection'"/>
        <x-stat label="Expenses (month to date)" :value="\App\Support\Money::format($pl['total_expenses'])" tone="black"/>
        <x-stat label="Net profit (MTD)" :value="\App\Support\Money::format($pl['net_profit'])" :tone="\App\Support\Money::lt($pl['net_profit'], 0) ? 'red' : 'green'"/>
        <x-stat label="Charity fund balance" :value="\App\Support\Money::format($charity)" hint="Late-payment amounts – not income" tone="amber" :href="route('ops.finance.islamic')"/>
        <x-stat label="Financing receivable (GL, net)" :value="\App\Support\Money::format($receivable)" :href="route('ops.finance.receivables')" :hint="'Gross contract value '.\App\Support\Money::format($receivableGross).' less deferred profit & charges'"/>
        <x-stat label="Other receivables" :value="\App\Support\Money::format($otherReceivable)" tone="blue"/>
        <x-stat label="Vendor payables (GL)" :value="\App\Support\Money::format($payable)" :hint="$paymentsAwaiting.' payment(s) awaiting approval/release'" tone="amber" :href="route('ops.finance.payables')"/>
        <x-stat label="Reconciliation exceptions" :value="$reconExceptions" :hint="$ordersUnreconciled.' settled orders not yet matched · suspense '.\App\Support\Money::format($suspense)" :tone="$reconExceptions ? 'red' : 'green'" :href="route('ops.finance.reconciliation.index')"/>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-card title="Cash & bank (simulated accounts)" class="lg:col-span-2" :padding="false">
            <x-table>
                <x-slot:head><x-th>Account</x-th><x-th>Purpose</x-th><x-th>IBAN</x-th><x-th align="right">GL balance</x-th><x-th align="right">Minimum</x-th></x-slot:head>
                @forelse($banks as $b)
                    <tr>
                        <td class="font-medium">{{ $b['bank']->name }}<div class="text-xs text-neutral-500">{{ $b['bank']->bank_name }}</div></td>
                        <td><x-badge>{{ ucfirst($b['bank']->purpose) }}</x-badge></td>
                        <td class="tabular text-xs">{{ $b['bank']->iban }}</td>
                        <td class="text-right"><x-money :amount="$b['balance']" tone="auto"/>@if($b['below_minimum'])<div><x-badge tone="red">Below minimum</x-badge></div>@endif</td>
                        <td class="text-right"><x-money :amount="$b['bank']->minimum_balance"/></td>
                    </tr>
                @empty
                    <x-empty colspan="5" title="No bank accounts configured"/>
                @endforelse
            </x-table>
        </x-card>

        <x-card title="Wallet safeguarding check">
            <x-dl :cols="1" :items="[
                'Safeguarded bank (GL)' => \App\Support\Money::format($three['safeguarded_bank']),
                'Wallet liability (GL)' => \App\Support\Money::format($three['wallet_liability']),
                'Σ customer & merchant wallet balances' => \App\Support\Money::format($three['wallet_balances']),
            ]"/>
            <div class="mt-4">
                @if($three['ok'])
                    <x-alert tone="green">Balanced – the safeguarded account, wallet liability and wallet sub-ledger agree.</x-alert>
                @else
                    <x-alert tone="red" title="Variance detected">Liability vs wallets: {{ \App\Support\Money::format($three['liability_vs_wallets']) }} · Bank vs liability: {{ \App\Support\Money::format($three['bank_vs_liability']) }}</x-alert>
                @endif
            </div>
        </x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-card title="Journal vouchers">
            <div class="space-y-2 text-sm">
                @foreach(\App\Enums\JournalStatus::cases() as $s)
                    <a href="{{ route('ops.finance.journals.index', ['filterStatus' => $s->value]) }}" wire:navigate class="flex items-center justify-between rounded-md px-2 py-1 hover:bg-neutral-50">
                        <x-status :value="$s"/><span class="tabular font-semibold">{{ $jv[$s->value] ?? 0 }}</span></a>
                @endforeach
            </div>
        </x-card>
        <x-card title="Period close">
            @if($period)
                <p class="text-sm">Current period <strong>{{ $period->name }}</strong> <x-status :value="$period->status"/></p>
            @else
                <x-alert tone="red">No accounting period covers today. Create the fiscal year.</x-alert>
            @endif
            <p class="mt-2 text-sm text-neutral-600">{{ $openPast }} earlier period(s) still open.</p>
            <a href="{{ route('ops.finance.periods') }}" wire:navigate class="link mt-3 inline-block text-sm">Manage periods →</a>
        </x-card>
        <x-card title="Fund utilization">
            <div class="space-y-3">
                @forelse($funds as $f)
                    @php($committed = (float) $f['fund']->committed_amount ?: 1)
                    @php($used = max(0, $committed - (float) $f['available']))
                    <div>
                        <div class="flex justify-between text-xs"><span class="font-medium">{{ $f['fund']->name }}</span><span class="tabular">{{ round($used * 100 / $committed) }}%</span></div>
                        <div class="mt-1 h-2 rounded-full bg-neutral-100"><div class="h-2 rounded-full bg-brand-600" style="width: {{ min(100, round($used * 100 / $committed)) }}%"></div></div>
                        <p class="mt-0.5 text-[11px] text-neutral-500">Available {{ \App\Support\Money::format($f['available']) }} of {{ \App\Support\Money::format($f['fund']->committed_amount) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500">No funding sources yet.</p>
                @endforelse
            </div>
        </x-card>
    </div>
</div>
