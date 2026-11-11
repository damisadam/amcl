<div>
    <x-page-header title="Bank & Raast settlement reconciliation" subtitle="Internal payment orders vs SIMULATED bank / provider settlement lines. Unidentified receipts are parked in suspense."/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @can('fms.reconciliation.perform')
        <x-card title="Run reconciliation" class="mb-6">
            <form wire:submit="run" class="grid gap-3 sm:grid-cols-4 sm:items-end">
                <x-select label="Bank account" wire:model="bank_account_id" placeholder="Select" :options="$banks"/>
                <x-input type="date" label="From" wire:model="from"/>
                <x-input type="date" label="To" wire:model="to"/>
                <button class="btn-primary" wire:loading.attr="disabled">Run matching</button>
            </form>
        </x-card>
    @endcan
    <x-tabs :tabs="['batches' => 'Batches', 'suspense' => 'Suspense items']" :active="$tab"/>
    @if($tab === 'batches')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Batch</x-th><x-th>Account</x-th><x-th>Period</x-th><x-th align="right">Matched</x-th><x-th align="right">Exceptions</x-th><x-th align="right">Difference</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($batches as $b)
                    <tr><td><a href="{{ route('ops.finance.reconciliation.show', $b) }}" wire:navigate class="link tabular">{{ $b->reference }}</a><p class="text-xs text-neutral-500">{{ $b->creator?->name }}</p></td>
                        <td class="text-xs">{{ $b->bankAccount?->name }}</td><td class="text-xs">{{ $b->period_start->format('d M') }} – {{ $b->period_end->format('d M Y') }}</td>
                        <td class="text-right tabular">{{ $b->matched_count }}</td><td class="text-right tabular">@if($b->exception_count)<x-badge tone="red">{{ $b->exception_count }}</x-badge>@else 0 @endif</td>
                        <td class="text-right"><x-money :amount="$b->difference" tone="auto"/></td><td><x-status :value="$b->status"/></td></tr>
                @empty
                    <x-empty colspan="7" title="No reconciliation batches yet" description="Run matching for a bank account and date range."/>
                @endforelse
            </x-table>
            {{ $batches->links() }}
        </x-card>
    @else
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>Ref</x-th><x-th>Statement line</x-th><x-th>Reason</x-th><x-th align="right">Amount</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                @forelse($suspense as $s)
                    <tr><td class="tabular">{{ $s->reference }}</td><td class="text-xs">{{ $s->settlementRecord?->provider_ref }} · {{ $s->settlementRecord?->value_date?->format('d M Y') }}</td>
                        <td class="text-xs">{{ $s->reason }}<p class="text-neutral-500">{{ $s->resolution_notes }}</p></td><td class="text-right"><x-money :amount="$s->amount"/></td><td><x-status :value="$s->status"/></td>
                        <td class="text-right">@if($s->status === 'open')@can('fms.reconciliation.perform')<button wire:click="askReclass({{ $s->id }})" class="btn-secondary btn-sm">Reclassify</button>@endcan @endif</td></tr>
                @empty
                    <x-empty colspan="6" title="Suspense is clear"/>
                @endforelse
            </x-table>
        </x-card>
    @endif
    <x-modal show="showReclass" title="Reclassify suspense item">
        <div class="space-y-3"><x-select label="Move to account" wire:model="reclassAccount" placeholder="Select" :options="$accounts"/><x-textarea label="Investigation notes" wire:model="reclassNotes"/></div>
        <x-slot:footer><button wire:click="$set('showReclass', false)" class="btn-secondary">Cancel</button><button wire:click="reclassify" class="btn-primary">Post reclassification</button></x-slot:footer>
    </x-modal>
</div>
