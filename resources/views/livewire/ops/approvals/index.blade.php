<div wire:poll.30s>
    <x-page-header title="Approval tasks" subtitle="Loan approval hierarchy and finance maker-checker approvals assigned to your roles."/>
    <x-tabs :active="$tab" :tabs="['mine' => 'My tasks ('.$counts['mine'].')', 'overdue' => 'Overdue ('.$counts['overdue'].')', 'all' => 'All pending (view)', 'completed' => 'Completed by me']"/>
    <x-card :padding="false">
        <div class="border-b border-neutral-100 p-4 sm:w-72"><x-select wire:model.live="filterType" :options="$types" placeholder="All approval types"/></div>
        <x-table>
            <x-slot:head><x-th>Type</x-th><x-th>Subject</x-th><x-th align="right">Amount</x-th><x-th>Requested by</x-th><x-th>Stage</x-th><x-th>Waiting</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
            @forelse($rows as $r)
                <tr>
                    <td><x-badge tone="black">{{ $r->type->label() }}</x-badge></td>
                    <td class="text-sm">@php($url = $urls[$r->id] ?? null)
                        @if($url)<a href="{{ $url }}" wire:navigate class="link">{{ $r->approvable?->application_no }}</a><div class="text-xs text-neutral-500">{{ $r->approvable?->customer?->full_name }}</div>
                        @else{{ $r->approvable?->voucher_no ?? $r->approvable?->payment_no ?? $r->approvable?->reference ?? $r->approvable?->period ?? ('#'.$r->approvable_id) }}@endif</td>
                    <td class="text-right"><x-money :amount="$r->amount"/></td>
                    <td class="text-xs">{{ $r->requester?->name }}</td>
                    <td class="text-xs">@if($r->status->value === 'pending')<span class="font-medium">{{ $r->stageNumber() }}/{{ count($r->stage_plan) }}</span> {{ $r->currentStage?->name }}@else—@endif</td>
                    <td class="text-xs">{{ $r->stage_entered_at?->diffForHumans(null, true) }} @if($r->isOverdue())<x-badge tone="red">SLA</x-badge>@endif</td>
                    <td><x-status :value="$r->status"/></td>
                    <td class="text-right"><button wire:click="select({{ $r->id }})" class="btn-secondary btn-sm">{{ $tab === 'mine' || $tab === 'overdue' ? 'Review' : 'View' }}</button></td>
                </tr>
            @empty <x-empty colspan="8" :title="$tab === 'mine' ? 'No approval tasks waiting for you' : 'Nothing to show'"/> @endforelse
        </x-table>
        {{ $rows->links() }}
    </x-card>

    <x-modal show="showDecision" :title="$selected ? $selected->type->label().' approval' : 'Approval'" maxWidth="2xl">
        @if($selected)
            <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
                @foreach($selected->stage_plan as $i => $st)
                    @php($done = $selected->actions->where('approval_stage_id', $st['id'])->where('action', 'approve')->isNotEmpty())
                    <span @class(['rounded-full px-2.5 py-1', 'bg-brand-700 text-white' => $done, 'bg-amber-100 ring-2 ring-amber-400' => $selected->current_stage_id === $st['id'], 'bg-neutral-100 text-neutral-500' => ! $done && $selected->current_stage_id !== $st['id']])>{{ $i + 1 }}. {{ $st['name'] }}</span>
                @endforeach
            </div>
            <x-dl :items="$summary + ['Requested by' => $selected->requester?->name.' · '.$selected->created_at->format('d M Y H:i')]"/>
            @if($url = $selectedUrl)<a href="{{ $url }}" wire:navigate class="link mt-2 inline-block text-sm">Open full case file →</a>@endif
            @if($readiness)@include('livewire.ops.applications._readiness', ['checks' => $readiness, 'compact' => true, 'class' => 'mt-4'])@endif
            @if($selected->actions->isNotEmpty())
                <h4 class="mb-1 mt-4 text-xs font-semibold uppercase text-neutral-500">History</h4>
                <ul class="space-y-1 text-xs">@foreach($selected->actions as $act)<li>{{ $act->created_at->format('d M H:i') }} · <strong>{{ $act->actor?->name }}</strong> {{ $act->action }} @ {{ $act->stage_name }} {{ $act->comment ? '– '.$act->comment : '' }}</li>@endforeach</ul>
            @endif
            @if($canDecide)
                <div class="mt-4 border-t border-neutral-100 pt-4">
                    @error('action')<x-alert tone="red" class="mb-3">{{ $message }}</x-alert>@enderror
                    <x-textarea label="Comment" wire:model="comment" :hint="'Required to reject or return'.($selected->currentStage?->comment_required_on_approve ? ', and to approve at this stage' : '').'.'"/>
                    @if($delegates)<div class="mt-3 flex items-end gap-2"><x-select class="flex-1" label="Delegate to (same role)" wire:model="delegateTo" :options="$delegates" placeholder="Select approver"/><button wire:click="decide('delegate')" class="btn-ghost">Delegate</button></div>@endif
                </div>
            @elseif($selected->status->value === 'pending')
                <x-alert tone="gray" class="mt-4">You cannot act on this request (segregation of duties or different stage role).</x-alert>
            @endif
        @endif
        <x-slot:footer>
            <button class="btn-secondary" x-on:click="open=false">Close</button>
            @if($canDecide)
                <button wire:click="decide('return')" class="btn-secondary">Return</button>
                <button wire:click="decide('reject')" wire:confirm="Reject this request?" class="btn-danger">Reject</button>
                <button wire:click="decide('approve')" class="btn-primary">Approve</button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
