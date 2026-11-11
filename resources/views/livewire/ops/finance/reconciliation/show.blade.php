<div>
    <x-page-header :title="'Reconciliation '.$batch->reference" :subtitle="$batch->bankAccount->name.' · '.$batch->period_start->format('d M').' – '.$batch->period_end->format('d M Y').' · SIMULATED statement'" :back="route('ops.finance.reconciliation.index')">
        <x-slot:actions>
            <x-status :value="$batch->status"/>
            @if($editable)@can('fms.reconciliation.perform')<button wire:click="submit" class="btn-primary">Submit for approval</button>@endcan @endif
        </x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat label="Internal total" :value="\App\Support\Money::format($batch->internal_total)"/>
        <x-stat label="Statement total" :value="\App\Support\Money::format($batch->external_total)" tone="black"/>
        <x-stat label="Difference" :value="\App\Support\Money::format($batch->difference)" :tone="\App\Support\Money::isZero($batch->difference) ? 'green' : 'red'"/>
        <x-stat label="Matched" :value="$batch->matched_count" tone="green"/>
        <x-stat label="Open exceptions" :value="$batch->exception_count" :tone="$batch->exception_count ? 'red' : 'green'"/>
    </div>
    @if($pending)
        <x-card title="Approval" class="mt-6">
            <p class="text-sm">Awaiting {{ $pending->currentStage?->name }}.</p>
            @if($canDecide)
                <x-textarea label="Comment" wire:model="comment" rows="2" class="mt-2"/>
                <div class="mt-2 flex gap-2"><button wire:click="decide('approve')" class="btn-primary btn-sm">Approve</button><button wire:click="decide('reject')" class="btn-danger btn-sm">Send back</button></div>
            @endif
        </x-card>
    @endif
    @if($editable && $openInternal->isNotEmpty() && $openExternal->isNotEmpty())
        @can('fms.reconciliation.perform')
        <x-card title="Manual match" class="mt-6">
            <div class="grid gap-3 sm:grid-cols-4 sm:items-end">
                <x-select label="Internal record (no statement line)" wire:model="internalId" placeholder="Select" :options="$openInternal->mapWithKeys(fn ($i) => [$i->id => $i->paymentOrder?->reference.' · '.\App\Support\Money::format($i->internal_amount)])->all()"/>
                <x-select label="Statement line (no internal record)" wire:model="externalId" placeholder="Select" :options="$openExternal->mapWithKeys(fn ($i) => [$i->id => $i->settlementRecord?->provider_ref.' · '.\App\Support\Money::format($i->external_amount)])->all()"/>
                <x-input label="Notes" wire:model="notes"/>
                <button wire:click="manualMatch" class="btn-secondary">Match</button>
            </div>
        </x-card>
        @endcan
    @endif
    <x-card class="mt-6" :padding="false">
        <x-slot:actions><x-select wire:model.live="filter" :options="['exceptions' => 'Exceptions', 'matched' => 'Matched', 'all' => 'All items']"/></x-slot:actions>
        <x-table>
            <x-slot:head><x-th>Result</x-th><x-th>Internal (payment order)</x-th><x-th>Statement line</x-th><x-th align="right">Internal</x-th><x-th align="right">Statement</x-th><x-th>Resolution</x-th><x-th></x-th></x-slot:head>
            @forelse($items as $i)
                <tr wire:key="ri-{{ $i->id }}">
                    <td><x-status :value="$i->result"/></td>
                    <td class="text-xs">@if($i->paymentOrder){{ $i->paymentOrder->reference }}<p class="text-neutral-500">{{ $i->paymentOrder->purpose->label() }} · {{ $i->paymentOrder->provider_ref }}</p>@else — @endif</td>
                    <td class="text-xs">@if($i->settlementRecord){{ $i->settlementRecord->provider_ref }}<p class="text-neutral-500">{{ $i->settlementRecord->narration }}</p>@else — @endif</td>
                    <td class="text-right">@if($i->internal_amount !== null)<x-money :amount="$i->internal_amount"/>@endif</td>
                    <td class="text-right">@if($i->external_amount !== null)<x-money :amount="$i->external_amount"/>@endif</td>
                    <td class="text-xs">@if($i->resolution)<x-badge tone="green">{{ \Illuminate\Support\Str::headline($i->resolution) }}</x-badge><p class="text-neutral-500">{{ $i->notes }} {{ $i->resolver?->name }}</p>@endif</td>
                    <td class="whitespace-nowrap text-right">
                        @if($editable && ! $i->resolution && ! in_array($i->result, ['matched', 'manual_match']))
                            @can('fms.reconciliation.perform')
                                @if($i->result === 'missing_internal' && $i->settlementRecord?->direction === 'credit')<button wire:click="toSuspense({{ $i->id }})" wire:confirm="Park this unidentified receipt in suspense?" class="btn-secondary btn-sm">To suspense</button>@endif
                                <button wire:click="askAnnotate({{ $i->id }})" class="link ml-2 text-xs">Annotate</button>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <x-empty colspan="7" title="Nothing to show"/>
            @endforelse
        </x-table>
    </x-card>
    <x-modal show="showAnnotate" title="Explain exception">
        <div class="space-y-3">
            <x-select label="Resolution" wire:model="resolution" :options="['timing_difference' => 'Timing difference', 'bank_query_raised' => 'Bank query raised', 'written_back' => 'Written back', 'ignored' => 'Ignore (not applicable)']"/>
            <x-textarea label="Notes" wire:model="notes"/>
        </div>
        <x-slot:footer><button wire:click="$set('showAnnotate', false)" class="btn-secondary">Cancel</button><button wire:click="annotate" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>
</div>
