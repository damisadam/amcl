<div>
    <x-page-header title="Wallet reports" subtitle="Wallet balances are customer liabilities held in the safeguarded account. External settlement figures are SIMULATED."/>
    @include('livewire.ops.reports._filters')
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Σ wallet balances" :value="\App\Support\Money::format($three['wallet_balances'])"/>
        <x-stat label="Failed transactions" :value="$failed" tone="red"/>
        <x-stat label="Reversed / refunded" :value="$reversed" tone="gray"/>
        <x-stat label="Pending" :value="$pending" tone="amber"/>
    </div>
    <x-card title="Safeguarding three-way check" class="mt-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <x-dl :cols="1" :items="['Safeguarded bank (GL, simulated)' => \App\Support\Money::format($three['safeguarded_bank'])]"/>
            <x-dl :cols="1" :items="['Wallet liability (GL)' => \App\Support\Money::format($three['wallet_liability'])]"/>
            <x-dl :cols="1" :items="['Σ wallet sub-ledger' => \App\Support\Money::format($three['wallet_balances'])]"/>
        </div>
        <div class="mt-3">@if($three['ok'])<x-badge tone="green">All three agree</x-badge>@else<x-badge tone="red">Variance: liability vs wallets {{ \App\Support\Money::format($three['liability_vs_wallets']) }}, bank vs liability {{ \App\Support\Money::format($three['bank_vs_liability']) }}</x-badge>@endif</div>
    </x-card>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Successful volume by type">
            @php($max = $byType->max(fn ($t) => (float) $t['amount']) ?: 1)
            <div class="space-y-2">@forelse($byType as $type => $t)@include('livewire.ops.reports._bar', ['value' => (float) $t['amount'], 'max' => $max, 'label' => \Illuminate\Support\Str::headline($type), 'display' => $t['count'].' · '.\App\Support\Money::format($t['amount'])])@empty<p class="text-sm text-neutral-500">No transactions in range.</p>@endforelse</div>
        </x-card>
        <x-card title="Wallets by status" :padding="false">
            <x-table><x-slot:head><x-th>Owner</x-th><x-th>Status</x-th><x-th align="right">Wallets</x-th><x-th align="right">Balance</x-th><x-th align="right">Held</x-th></x-slot:head>
                @forelse($balances as $b)<tr><td>{{ $b['owner'] }}</td><td><x-status :value="$b['status']"/></td><td class="text-right">{{ $b['count'] }}</td><td class="text-right"><x-money :amount="$b['balance']"/></td><td class="text-right"><x-money :amount="$b['held']" :currency="false"/></td></tr>@empty<x-empty colspan="5"/>@endforelse
            </x-table>
        </x-card>
        <x-card title="Daily flows (successful)" :padding="false">
            <x-table><x-slot:head><x-th>Date</x-th><x-th align="right">Credits</x-th><x-th align="right">Debits</x-th><x-th align="right">Txns</x-th></x-slot:head>
                @forelse($daily as $d)<tr><td>{{ \Carbon\Carbon::parse($d['date'])->format('d M') }}</td><td class="text-right"><x-money :amount="$d['credits']" :currency="false"/></td><td class="text-right"><x-money :amount="$d['debits']" :currency="false"/></td><td class="text-right">{{ $d['count'] }}</td></tr>@empty<x-empty colspan="4"/>@endforelse
            </x-table>
        </x-card>
        <x-card title="External transactions – reconciliation status" :padding="false">
            <x-table><x-slot:head><x-th>Purpose</x-th><x-th>Order status</x-th><x-th>Reconciliation</x-th><x-th align="right">Count</x-th><x-th align="right">Amount</x-th></x-slot:head>
                @forelse($recon as $r)<tr><td>{{ \Illuminate\Support\Str::headline($r['purpose']) }}</td><td><x-status :value="$r['status']"/></td><td><x-status :value="$r['reconciliation']"/></td><td class="text-right">{{ $r['count'] }}</td><td class="text-right"><x-money :amount="$r['amount']"/></td></tr>@empty<x-empty colspan="5"/>@endforelse
            </x-table>
        </x-card>
    </div>
    <x-card title="All transactions by type & status" class="mt-6" :padding="false">
        <x-table><x-slot:head><x-th>Type</x-th><x-th>Status</x-th><x-th align="right">Count</x-th><x-th align="right">Amount</x-th></x-slot:head>
            @forelse($volumes as $v)<tr><td>{{ \Illuminate\Support\Str::headline($v['type']) }}</td><td><x-status :value="$v['status']"/></td><td class="text-right">{{ $v['count'] }}</td><td class="text-right"><x-money :amount="$v['amount']"/></td></tr>@empty<x-empty colspan="4"/>@endforelse
        </x-table>
    </x-card>
</div>
