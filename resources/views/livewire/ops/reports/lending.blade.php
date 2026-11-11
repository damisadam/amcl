<div>
    <x-page-header title="Lending reports" :subtitle="'Period '.$period.' · all figures computed from live records'"/>
    @include('livewire.ops.reports._filters')
    <x-tabs :tabs="['pipeline' => 'Applications & approvals', 'disbursements' => 'Disbursements', 'portfolio' => 'Portfolio', 'quality' => 'PAR & aging', 'collections' => 'Collections', 'field' => 'Field & recovery']" :active="$tab"/>

    @if($tab === 'pipeline')
        <div class="grid gap-4 sm:grid-cols-4">
            <x-stat label="Approved" :value="$data['decisions']['approved']"/>
            <x-stat label="Rejected" :value="$data['decisions']['rejected']" tone="red"/>
            <x-stat label="Approval rate" :value="$data['decisions']['approval_rate'] !== null ? $data['decisions']['approval_rate'].'%' : '—'" tone="black"/>
            <x-stat label="Avg submit → approval" :value="$data['tat']['avg_hours'] !== null ? $data['tat']['avg_hours'].' h' : '—'" :hint="$data['tat']['count'].' approvals'" tone="amber"/>
        </div>
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <x-card title="Applications by status">
                @php($max = collect($data['funnel']['by_status'])->max('count') ?: 1)
                <div class="space-y-2">@forelse($data['funnel']['by_status'] as $s)@include('livewire.ops.reports._bar', ['value' => $s['count'], 'max' => $max, 'label' => \Illuminate\Support\Str::headline($s['status']), 'display' => $s['count'].' · '.\App\Support\Money::format($s['amount'])])@empty<p class="text-sm text-neutral-500">No applications in range.</p>@endforelse</div>
            </x-card>
            <x-card title="By channel & product">
                <div class="mb-4 flex flex-wrap gap-2">@foreach($data['funnel']['by_channel'] as $c => $n)<x-badge>{{ ucfirst($c) }}: {{ $n }}</x-badge>@endforeach</div>
                <x-table>
                    <x-slot:head><x-th>Product</x-th><x-th align="right">Applications</x-th><x-th align="right">Disbursed</x-th><x-th align="right">Conversion</x-th></x-slot:head>
                    @foreach($data['funnel']['by_product'] as $p)<tr><td>{{ $p['product'] }}</td><td class="text-right">{{ $p['count'] }}</td><td class="text-right">{{ $p['disbursed'] }}</td><td class="text-right">{{ $p['count'] ? round($p['disbursed'] * 100 / $p['count']) : 0 }}%</td></tr>@endforeach
                </x-table>
            </x-card>
            <x-card title="Approval turnaround by stage" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Stage</x-th><x-th align="right">Avg hours</x-th><x-th align="right">Approved</x-th><x-th align="right">Returned</x-th><x-th align="right">Rejected</x-th></x-slot:head>
                    @forelse($data['tat']['stages'] as $s)<tr><td>{{ $s['stage'] }}</td><td class="text-right">{{ $s['avg_hours'] }}</td><td class="text-right">{{ $s['approved'] }}</td><td class="text-right">{{ $s['returned'] }}</td><td class="text-right">{{ $s['rejected'] }}</td></tr>@empty<x-empty colspan="5" title="No approval actions in range"/>@endforelse
                </x-table>
            </x-card>
            <x-card title="Rejection reasons">
                @php($max = max($data['decisions']['reasons'] ?: [0]) ?: 1)
                <div class="space-y-2">@forelse($data['decisions']['reasons'] as $code => $n)@include('livewire.ops.reports._bar', ['value' => $n, 'max' => $max, 'label' => \App\Services\Lending\ApplicationService::REJECTION_CODES[$code] ?? $code, 'display' => $n, 'tone' => 'bg-red-500'])@empty<p class="text-sm text-neutral-500">No rejections in range.</p>@endforelse</div>
            </x-card>
        </div>
    @elseif($tab === 'disbursements')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="By month" class="lg:col-span-2">
                @php($max = collect($data['by_month'])->max(fn ($r) => (float) $r['amount']) ?: 1)
                <div class="space-y-2">@forelse($data['by_month'] as $r)@include('livewire.ops.reports._bar', ['value' => (float) $r['amount'], 'max' => $max, 'label' => $r['month'], 'display' => $r['count'].' · '.\App\Support\Money::format($r['amount'])])@empty<p class="text-sm text-neutral-500">No disbursements in range.</p>@endforelse</div>
            </x-card>
            @foreach(['by_product' => ['Product', 'product'], 'by_branch' => ['Branch', 'branch']] as $key => [$title, $col])
                <x-card :title="'By '.strtolower($title)" :padding="false">
                    <x-table><x-slot:head><x-th>{{ $title }}</x-th><x-th align="right">Count</x-th><x-th align="right">Amount</x-th></x-slot:head>
                        @forelse($data[$key] as $r)<tr><td>{{ $r[$col] }}</td><td class="text-right">{{ $r['count'] }}</td><td class="text-right"><x-money :amount="$r['amount']"/></td></tr>@empty<x-empty colspan="3"/>@endforelse
                    </x-table>
                </x-card>
            @endforeach
        </div>
    @elseif($tab === 'portfolio')
        <div class="mb-4 grid gap-4 sm:grid-cols-4">
            <x-stat label="Closed in period" :value="$data['closures']['closed']"/>
            <x-stat label="NOCs issued" :value="$data['closures']['noc_issued']" tone="black"/>
            <x-stat label="Closed awaiting NOC" :value="$data['closures']['awaiting_noc']" tone="amber"/>
            <x-stat label="Written off" :value="$data['closures']['written_off']" tone="red"/>
        </div>
        <x-card title="Open portfolio" :padding="false">
            <x-slot:actions><x-select wire:model.live="groupBy" :options="['product' => 'By product', 'branch' => 'By branch', 'officer' => 'By loan officer']"/></x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Group</x-th><x-th align="right">Accounts</x-th><x-th align="right">Disbursed</x-th><x-th align="right">Principal outstanding</x-th><x-th align="right">Total outstanding</x-th><x-th align="right">PAR 30</x-th></x-slot:head>
                @forelse($data['rows'] as $r)
                    <tr><td>{{ $r['key'] }}</td><td class="text-right">{{ $r['count'] }}</td><td class="text-right"><x-money :amount="$r['disbursed']" :currency="false"/></td><td class="text-right"><x-money :amount="$r['principal']"/></td>
                        <td class="text-right"><x-money :amount="$r['total']" :currency="false"/></td><td class="text-right"><x-money :amount="$r['par30']" :currency="false"/></td></tr>
                @empty
                    <x-empty colspan="6" title="No open financing"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'quality')
        <div class="grid gap-4 sm:grid-cols-4">
            @foreach(['par1' => 'PAR 1', 'par30' => 'PAR 30', 'par60' => 'PAR 60', 'par90' => 'PAR 90'] as $k => $l)
                <x-stat :label="$l" :value="$data[$k]['pct'].'%'" :hint="\App\Support\Money::format($data[$k]['amount'])" :tone="$data[$k]['pct'] > 5 ? 'red' : 'green'"/>
            @endforeach
        </div>
        <x-card title="Days-past-due aging" class="mt-6" :subtitle="$data['count'].' open accounts · '.\App\Support\Money::format($data['total']).' principal outstanding'">
            @php($max = collect($data['buckets'])->max(fn ($b) => (float) $b['outstanding']) ?: 1)
            <div class="space-y-2">@foreach($data['buckets'] as $b)@include('livewire.ops.reports._bar', ['value' => (float) $b['outstanding'], 'max' => $max, 'label' => $b['bucket'].' days', 'display' => $b['count'].' · '.$b['pct'].'%', 'tone' => $b['bucket'] === 'Current' ? 'bg-brand-600' : 'bg-red-500'])@endforeach</div>
        </x-card>
    @elseif($tab === 'collections')
        <div class="grid gap-4 sm:grid-cols-4">
            <x-stat label="Paid early" :value="$data['timeliness']['early']"/>
            <x-stat label="Paid on due date" :value="$data['timeliness']['on_time']" tone="black"/>
            <x-stat label="Paid late" :value="$data['timeliness']['late']" tone="amber"/>
            <x-stat label="Unpaid past due" :value="$data['timeliness']['unpaid_past_due']" tone="red"/>
        </div>
        <x-card title="Collections efficiency by month" class="mt-6" :padding="false">
            <x-table>
                <x-slot:head><x-th>Month</x-th><x-th align="right">Due</x-th><x-th align="right">Paid against due</x-th><x-th align="right">Efficiency</x-th><x-th align="right">Cash collected</x-th><x-th align="right">Payments</x-th></x-slot:head>
                @forelse($data['months'] as $m)
                    <tr><td>{{ $m['month'] }}</td><td class="text-right"><x-money :amount="$m['due']"/></td><td class="text-right"><x-money :amount="$m['paid_against_due']" :currency="false"/></td>
                        <td class="text-right">@if($m['efficiency'] !== null)<x-badge :tone="$m['efficiency'] >= 95 ? 'green' : ($m['efficiency'] >= 80 ? 'amber' : 'red')">{{ $m['efficiency'] }}%</x-badge>@endif</td>
                        <td class="text-right"><x-money :amount="$m['collected']"/></td><td class="text-right">{{ $m['payments'] }}</td></tr>
                @empty
                    <x-empty colspan="6"/>
                @endforelse
            </x-table>
        </x-card>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Field visit outcomes" :padding="false">
                <x-table><x-slot:head><x-th>Type</x-th><x-th>Status</x-th><x-th>Recommendation</x-th><x-th align="right">Visits</x-th></x-slot:head>
                    @forelse($data['visits'] as $v)<tr><td>{{ ucfirst($v['type']) }}</td><td><x-status :value="$v['status']"/></td><td><x-status :value="$v['recommendation']"/></td><td class="text-right">{{ $v['count'] }}</td></tr>@empty<x-empty colspan="4"/>@endforelse
                </x-table>
            </x-card>
            <x-card title="Recovery outcomes">
                @php($max = max($data['recovery']['outcomes'] ?: [0]) ?: 1)
                <div class="space-y-2">@forelse($data['recovery']['outcomes'] as $o => $n)@include('livewire.ops.reports._bar', ['value' => $n, 'max' => $max, 'label' => \Illuminate\Support\Str::headline($o), 'display' => $n])@empty<p class="text-sm text-neutral-500">No recovery contacts in range.</p>@endforelse</div>
                <h3 class="mb-2 mt-5 text-sm font-semibold">Promises to pay</h3>
                <div class="flex flex-wrap gap-2">@forelse($data['recovery']['ptp'] as $p)<x-badge :tone="$p['status'] === 'kept' ? 'green' : ($p['status'] === 'broken' ? 'red' : 'amber')">{{ ucfirst($p['status']) }}: {{ $p['count'] }} · {{ \App\Support\Money::format($p['amount']) }}</x-badge>@empty<span class="text-sm text-neutral-500">None</span>@endforelse</div>
            </x-card>
        </div>
    @endif
</div>
