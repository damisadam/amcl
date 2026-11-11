<div>
    <x-page-header title="Regulatory reports" subtitle="SECP / SBP-style returns and Raast Annexure-A summary generated from live records."/>
    <x-alert class="mb-4" title="Simulated formats">{{ $disclaimer }}</x-alert>
    @include('livewire.ops.reports._filters')
    <x-tabs :tabs="['quality' => 'Portfolio quality', 'products' => 'Product-wise portfolio', 'outreach' => 'Outreach', 'raast' => 'Raast Annexure-A', 'complaints' => 'Complaints']" :active="$tab"/>
    @if($tab === 'quality')
        <x-card :padding="false" title="Classification & provisioning (as at today)">
            <x-table>
                <x-slot:head><x-th>Classification</x-th><x-th align="right">Accounts</x-th><x-th align="right">Outstanding</x-th><x-th align="right">Rate</x-th><x-th align="right">Required</x-th><x-th align="right">Held</x-th></x-slot:head>
                @foreach($quality as $q)<tr><td><x-status :value="$q['classification']"/></td><td class="text-right">{{ $q['count'] }}</td><td class="text-right"><x-money :amount="$q['outstanding']"/></td><td class="text-right">{{ $q['rate'] }}%</td><td class="text-right"><x-money :amount="$q['required']"/></td><td class="text-right"><x-money :amount="$q['held']"/></td></tr>@endforeach
            </x-table>
        </x-card>
    @elseif($tab === 'products')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Product</x-th><x-th>Structure</x-th><x-th align="right">Accounts</x-th><x-th align="right">Disbursed</x-th><x-th align="right">Outstanding</x-th></x-slot:head>
                @forelse($productRows as $p)<tr><td>{{ $p['product'] }}</td><td>{{ \Illuminate\Support\Str::headline($p['structure']) }}</td><td class="text-right">{{ $p['accounts'] }}</td><td class="text-right"><x-money :amount="$p['disbursed']"/></td><td class="text-right"><x-money :amount="$p['outstanding']"/></td></tr>@empty<x-empty colspan="5"/>@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'outreach')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="By gender" :padding="false"><x-table><x-slot:head><x-th>Gender</x-th><x-th align="right">Borrowers</x-th><x-th align="right">Disbursed</x-th></x-slot:head>
                @forelse($outreach['gender'] as $g)<tr><td>{{ ucfirst($g['gender']) }}</td><td class="text-right">{{ $g['borrowers'] }}</td><td class="text-right"><x-money :amount="$g['disbursed']"/></td></tr>@empty<x-empty colspan="3"/>@endforelse</x-table></x-card>
            <x-card title="By city" :padding="false"><x-table><x-slot:head><x-th>City</x-th><x-th align="right">Borrowers</x-th><x-th align="right">Disbursed</x-th></x-slot:head>
                @forelse($outreach['city'] as $c)<tr><td>{{ $c['city'] }}</td><td class="text-right">{{ $c['borrowers'] }}</td><td class="text-right"><x-money :amount="$c['disbursed']"/></td></tr>@empty<x-empty colspan="3"/>@endforelse</x-table></x-card>
        </div>
    @elseif($tab === 'raast')
        <x-card :padding="false" title="Raast transactions via sponsor bank (SIMULATED rail)">
            <x-table>
                <x-slot:head><x-th>Month</x-th><x-th>Purpose</x-th><x-th>Direction</x-th><x-th align="right">Count</x-th><x-th align="right">Value</x-th></x-slot:head>
                @forelse($raast as $r)<tr><td>{{ $r['month'] }}</td><td>{{ \Illuminate\Support\Str::headline($r['purpose']) }}</td><td>{{ ucfirst($r['direction']) }}</td><td class="text-right">{{ $r['count'] }}</td><td class="text-right"><x-money :amount="$r['value']"/></td></tr>@empty<x-empty colspan="5" title="No settled Raast transactions in range"/>@endforelse
            </x-table>
        </x-card>
    @else
        <div class="grid gap-4 sm:grid-cols-4">
            <x-stat label="Received" :value="$complaints['received']"/>
            <x-stat label="Resolved" :value="$complaints['resolved']" tone="black"/>
            <x-stat label="Resolved within SLA" :value="$complaints['resolved'] ? round($complaints['within_sla'] * 100 / $complaints['resolved']).'%' : '—'"/>
            <x-stat label="Open" :value="$complaints['open']" tone="amber"/>
        </div>
        <x-card title="By category" class="mt-6"><div class="flex flex-wrap gap-2">@forelse($complaints['by_category'] as $c => $n)<x-badge>{{ \Illuminate\Support\Str::headline($c) }}: {{ $n }}</x-badge>@empty<span class="text-sm text-neutral-500">None</span>@endforelse</div></x-card>
    @endif
</div>
