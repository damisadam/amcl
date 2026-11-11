<div>
    <x-page-header title="Queues & jobs" subtitle="Failed job review and safe retry">
        <x-slot:actions><a href="{{ url('/horizon') }}" target="_blank" class="btn-dark">Open Horizon ↗</a></x-slot:actions>
    </x-page-header>
    <div class="grid gap-3 sm:grid-cols-3">
        <x-stat label="Queue connection" :value="strtoupper($queue)" tone="black"/>
        <x-stat label="Redis" :value="$redis['ok'] ? 'Online' : 'Offline'" :tone="$redis['ok'] ? 'green' : 'red'" :hint="$redis['message']"/>
        <x-stat label="Reverb (real-time)" :value="$reverb['ok'] ? 'Configured' : 'Disabled'" :tone="$reverb['ok'] ? 'green' : 'amber'" :hint="$reverb['message']"/>
    </div>
    <x-alert class="mt-4" tone="blue" title="Safe retry">Financial jobs are idempotent: provider callbacks are de-duplicated by event id and ledger/GL postings by idempotency key, so retrying a failed job cannot credit a wallet or post a voucher twice. Investigate the exception before retrying; Redis is never the system of record.</x-alert>
    <x-card class="mt-4" title="Failed jobs" :padding="false">
        <div class="border-b border-neutral-100 p-4"><input type="search" wire:model.live.debounce.300ms="search" class="field-input max-w-sm" placeholder="Search queue, exception or uuid…"></div>
        <x-table>
            <x-slot:head><x-th>Failed at</x-th><x-th>Job</x-th><x-th>Queue</x-th><x-th>Exception</x-th><x-th></x-th></x-slot:head>
            @forelse($jobs as $j)
                <tr><td class="text-xs whitespace-nowrap">{{ $j->failed_at }}</td><td class="text-sm">{{ class_basename($j->job) }}<div class="font-mono text-[11px] text-neutral-500">{{ $j->uuid }}</div></td>
                    <td>{{ $j->queue }}</td><td class="max-w-md text-xs text-red-700">{{ $j->exception }}</td>
                    <td class="whitespace-nowrap text-right"><button wire:click="retry('{{ $j->uuid }}')" class="btn-secondary btn-sm">Retry</button>
                        <button wire:click="forget('{{ $j->uuid }}')" wire:confirm="Remove this failed job permanently?" class="btn-ghost btn-sm text-red-600">Forget</button></td></tr>
            @empty
                <x-empty colspan="5" title="No failed jobs" description="All queued work completed successfully."/>
            @endforelse
        </x-table>
    </x-card>
</div>
