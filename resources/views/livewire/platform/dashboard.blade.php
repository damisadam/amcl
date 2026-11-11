<div wire:poll.60s>
    <x-page-header title="Platform overview" subtitle="Operational health across institutions. Counts only – tenant customer and financial data is not visible here."/>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <x-stat tinted label="Active tenants" :value="$byStatus['active'] ?? 0" tone="green" icon="users" :href="route('platform.tenants.index')"/>
        <x-stat tinted label="Suspended" :value="$byStatus['suspended'] ?? 0" tone="amber" icon="pause"/>
        <x-stat tinted label="Inactive" :value="$byStatus['inactive'] ?? 0" tone="gray" icon="inbox"/>
        <x-stat tinted label="Failed jobs" :value="$failedJobs" :tone="$failedJobs ? 'red' : 'green'" icon="x-circle" :href="route('platform.queues')"/>
        <x-stat tinted label="Redis" :value="$redis['ok'] ? 'Online' : 'Offline'" :tone="$redis['ok'] ? 'green' : 'red'" icon="database" hint="Demo {{ $modeCounts['demo'] ?? 0 }} · Live {{ $modeCounts['live'] ?? 0 }} provider configs"/>
    </div>

    <x-card class="mt-6" title="Tenant health" subtitle="Counts only" :padding="false" :divider="false">
        <x-slot:actions>@if(auth()->user()->hasPermission('platform.tenants.manage'))<a href="{{ route('platform.tenants.create') }}" class="btn-primary btn-sm" wire:navigate><x-icon name="plus" class="h-4 w-4"/>Provision tenant</a>@endif</x-slot:actions>
        <x-table dense inset class="mt-2">
            <x-slot:head><x-th>Institution</x-th><x-th>Status</x-th><x-th align="right">Staff users</x-th><x-th align="right">Customers</x-th><x-th align="right">Active loans</x-th>
                <x-th align="right">Pending payments</x-th><x-th align="right">Integration errors 24h</x-th><x-th>Last activity</x-th></x-slot:head>
            @forelse($health as $h)
                <tr>
                    <td><a class="link" href="{{ route('platform.tenants.show', $h['tenant']) }}" wire:navigate>{{ $h['tenant']->name }}</a>
                        <div class="text-xs text-neutral-500">{{ $h['tenant']->code }} @if($h['tenant']->is_demo)· demo @endif</div></td>
                    <td><x-status :value="$h['tenant']->status"/></td>
                    <td class="text-right tabular">{{ $h['users'] }}</td>
                    <td class="text-right tabular">{{ $h['customers'] }}</td>
                    <td class="text-right tabular">{{ $h['active_loans'] }}</td>
                    <td class="text-right tabular">{{ $h['pending_payments'] }}</td>
                    <td class="text-right tabular {{ $h['integration_errors_24h'] ? 'text-red-600 font-semibold' : '' }}">{{ $h['integration_errors_24h'] }}</td>
                    <td class="text-xs text-neutral-500">{{ $h['last_activity'] ? \Carbon\Carbon::parse($h['last_activity'])->diffForHumans() : '—' }}</td>
                </tr>
            @empty
                <x-empty colspan="8" title="No tenants yet" description="Provision the first institution to get started."/>
            @endforelse
        </x-table>
    </x-card>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-card title="Feature entitlements" :padding="false" :divider="false">
            <div class="relative mx-4 mb-4 mt-2 overflow-x-auto rounded-lg border border-neutral-200">
                <table class="table-base table-dense">
                    <thead><tr><th>Tenant</th>@foreach(\App\Models\Tenant::FEATURES as $k => $label)<th class="text-center!" title="{{ $label }}">{{ strtoupper(str_replace('_', ' ', $k)) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($tenants as $t)
                        <tr><td class="whitespace-nowrap">{{ $t->code }}</td>
                            @foreach(array_keys(\App\Models\Tenant::FEATURES) as $k)<td class="text-center">@if($t->hasFeature($k))<span class="inline-block h-2.5 w-2.5 rounded-full bg-brand-600" title="Enabled"></span><span class="sr-only">Enabled</span>@else<span class="inline-block h-2.5 w-2.5 rounded-full border border-neutral-300" title="Disabled"></span><span class="sr-only">Disabled</span>@endif</td>@endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
        <x-card title="Integration health (24h)" :padding="false" :divider="false">
            <x-slot:actions><a href="{{ route('platform.health') }}" class="link text-sm" wire:navigate>Details</a></x-slot:actions>
            <x-table dense inset class="mt-2">
                <x-slot:head><x-th>Provider</x-th><x-th align="right">Calls</x-th><x-th align="right">Success</x-th><x-th align="right">Fail</x-th><x-th align="right">Timeout</x-th><x-th align="right">Avg ms</x-th></x-slot:head>
                @forelse($integrations as $r)
                    <tr><td>{{ config('integrations.providers.'.$r['provider'].'.name', $r['provider']) }}</td><td class="text-right tabular">{{ $r['total'] }}</td>
                        <td class="text-right tabular text-brand-700">{{ $r['success_pct'] }}%</td><td class="text-right tabular {{ $r['failure_pct'] > 0 ? 'text-red-600' : '' }}">{{ $r['failure_pct'] }}%</td>
                        <td class="text-right tabular">{{ $r['timeout_pct'] }}%</td><td class="text-right tabular">{{ (int) $r['avg_ms'] }}</td></tr>
                @empty
                    <x-empty colspan="6" title="No integration calls in the last 24 hours"/>
                @endforelse
            </x-table>
        </x-card>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
    <x-card title="Platform administrators" subtitle="Two-step verification is mandatory for every platform account" :padding="false" :divider="false">
        <x-table dense inset class="mt-2">
            <x-slot:head><x-th>Name</x-th><x-th>Role</x-th><x-th>Status</x-th><x-th>Two-step verification</x-th><x-th>Last sign-in</x-th></x-slot:head>
            @foreach($platformUsers as $pu)
                <tr><td>{{ $pu['user']->name }}<div class="text-xs text-neutral-500">{{ $pu['user']->email }}</div></td>
                    <td class="text-xs">{{ $pu['user']->roleNames() }}</td>
                    <td><x-status :value="$pu['user']->status"/></td>
                    <td>@if($pu['mfa'])<x-badge tone="green">Enabled</x-badge>@else<x-badge tone="red">Not set up</x-badge>@endif</td>
                    <td class="text-xs text-neutral-500">{{ $pu['last_login']?->format('d M Y H:i') ?? 'Never' }}</td></tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="Recent platform audit events" :divider="false">
        <x-slot:actions><a href="{{ route('platform.audit') }}" class="link text-sm" wire:navigate>All events</a></x-slot:actions>
        <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 px-3 text-[13px]">
            @forelse($audit as $a)
                <li class="flex items-center justify-between gap-3 py-1.5"><span class="min-w-0 truncate"><span class="font-medium text-ink">{{ $a->action }}</span> <span class="text-neutral-500">by {{ $a->actor?->name ?? 'system' }}</span></span>
                    <span class="shrink-0 text-xs text-neutral-500">{{ $a->created_at->diffForHumans() }}</span></li>
            @empty
                <li class="py-4 text-center text-neutral-500">No platform events yet.</li>
            @endforelse
        </ul>
    </x-card>
    </div>
</div>
