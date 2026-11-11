<div>
    <x-page-header title="Platform audit events" subtitle="Platform administrator actions and tenant-less events. Tenant business audit is visible only inside each tenant."/>
    <x-card :padding="false">
        <div class="flex flex-wrap gap-3 border-b border-neutral-100 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" class="field-input max-w-xs" placeholder="Action, correlation id, reason…">
            <select wire:model.live="filterTenant" class="field-input max-w-[200px]"><option value="">All tenants</option>@foreach($tenants as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <input type="date" wire:model.live="filterFrom" class="field-input max-w-[170px]"><input type="date" wire:model.live="filterTo" class="field-input max-w-[170px]">
        </div>
        <x-table>
            <x-slot:head><x-th>When</x-th><x-th>Action</x-th><x-th>Actor</x-th><x-th>Tenant</x-th><x-th>IP</x-th><x-th></x-th></x-slot:head>
            @forelse($logs as $l)
                <tr><td class="text-xs whitespace-nowrap">{{ $l->created_at->format('d M Y H:i:s') }}</td><td class="font-medium">{{ $l->action }}</td>
                    <td>{{ $l->actor?->name ?? 'system' }}<div class="text-[11px] text-neutral-500">{{ $l->actor_type }}</div></td><td>{{ $l->tenant?->name ?? '—' }}</td>
                    <td class="text-xs">{{ $l->ip }}</td><td><button wire:click="$set('viewing', {{ $l->id }})" class="link text-xs">Details</button></td></tr>
            @empty
                <x-empty colspan="6" title="No platform events match"/>
            @endforelse
        </x-table>
        {{ $logs->links() }}
    </x-card>
    @if($detail)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 p-4" wire:click.self="$set('viewing', null)">
            <div class="card max-h-[80vh] w-full max-w-2xl overflow-y-auto p-5">
                <div class="mb-3 flex justify-between"><h3 class="font-semibold">{{ $detail->action }}</h3><button wire:click="$set('viewing', null)">✕</button></div>
                <x-dl :items="['Actor' => $detail->actor?->name, 'Tenant' => $detail->tenant?->name, 'Subject' => $detail->auditable_type ? class_basename($detail->auditable_type).' #'.$detail->auditable_id : null, 'Correlation' => $detail->correlation_id, 'Reason' => $detail->reason, 'When' => $detail->created_at]"/>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 text-xs">
                    <div><p class="mb-1 font-semibold">Before</p><pre class="overflow-x-auto rounded bg-neutral-50 p-2">{{ json_encode($detail->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
                    <div><p class="mb-1 font-semibold">After</p><pre class="overflow-x-auto rounded bg-neutral-50 p-2">{{ json_encode($detail->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
                </div>
            </div>
        </div>
    @endif
</div>
