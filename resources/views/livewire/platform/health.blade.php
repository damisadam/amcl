<div wire:poll.60s>
    <x-page-header title="Integration health" subtitle="Simulated provider calls by tenant. Payloads and credentials are never shown here.">
        <x-slot:actions><select wire:model.live="hours" class="field-input"><option value="1">Last hour</option><option value="6">Last 6 hours</option><option value="24">Last 24 hours</option><option value="168">Last 7 days</option></select></x-slot:actions>
    </x-page-header>
    <x-card title="Calls by tenant and provider" :padding="false">
        <x-table>
            <x-slot:head><x-th>Tenant</x-th><x-th>Provider</x-th><x-th align="right">Calls</x-th><x-th align="right">Success</x-th><x-th align="right">Failure</x-th><x-th align="right">Timeout</x-th><x-th align="right">Pending</x-th><x-th align="right">Avg latency</x-th><x-th>Mode</x-th></x-slot:head>
            @forelse($rows as $r)
                <tr><td>{{ $tenants[$r['tenant_id']]->name ?? 'Platform' }}</td><td>{{ $providers[$r['provider']]['name'] ?? $r['provider'] }}</td>
                    <td class="text-right tabular">{{ $r['total'] }}</td><td class="text-right tabular text-brand-700">{{ $r['success_pct'] }}%</td>
                    <td class="text-right tabular {{ $r['failure_pct'] ? 'text-red-600 font-semibold' : '' }}">{{ $r['failure_pct'] }}%</td><td class="text-right tabular">{{ $r['timeout_pct'] }}%</td>
                    <td class="text-right tabular">{{ $r['pending'] }}</td><td class="text-right tabular">{{ (int) $r['avg_ms'] }} ms</td>
                    <td>@if($r['live'])<x-badge tone="red">live {{ $r['live'] }}</x-badge>@endif @if($r['demo'])<x-badge tone="amber">demo {{ $r['demo'] }}</x-badge>@endif</td></tr>
            @empty
                <x-empty colspan="9" title="No calls in this window"/>
            @endforelse
        </x-table>
    </x-card>
    <x-card class="mt-6" title="Recent errors" :padding="false">
        <x-table>
            <x-slot:head><x-th>When</x-th><x-th>Tenant</x-th><x-th>Provider</x-th><x-th>Operation</x-th><x-th>Status</x-th><x-th>Error code</x-th><x-th>Correlation</x-th></x-slot:head>
            @forelse($errors as $e)
                <tr><td class="text-xs">{{ \Carbon\Carbon::parse($e->created_at)->format('d M H:i') }}</td><td>{{ $e->tenant ?? '—' }}</td><td>{{ $e->provider }}</td><td>{{ $e->operation }}</td>
                    <td><x-status :value="$e->status"/></td><td class="font-mono text-xs">{{ $e->error_code }}</td><td class="font-mono text-[11px] text-neutral-500">{{ $e->correlation_id }}</td></tr>
            @empty
                <x-empty colspan="7" title="No integration errors"/>
            @endforelse
        </x-table>
    </x-card>
    <x-card class="mt-6" title="Demo / live configuration matrix">
        <div class="relative overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tenant</th>@foreach($providers as $key => $p)<th class="text-center!" title="{{ $p['name'] }}">{{ str_replace('_', ' ', $key) }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($tenants as $t)
                    <tr><td class="whitespace-nowrap">{{ $t->code }}</td>
                        @foreach(array_keys($providers) as $key)
                            @php($m = $matrix[$t->id][$key] ?? null)
                            <td class="text-center text-xs">@if(! $m)<span class="text-neutral-300">—</span>@elseif(! $m['enabled'])<span class="text-neutral-400">off</span>@elseif($m['mode'] === 'live')<span class="font-semibold text-red-600">LIVE</span>@else<span class="text-amber-700">demo</span>@endif</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</div>
