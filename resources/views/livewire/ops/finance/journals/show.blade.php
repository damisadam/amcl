<div>
    <x-page-header :title="$journal->voucher_no" :subtitle="$journal->narration" :back="route('ops.finance.journals.index')">
        <x-slot:actions>
            <x-status :value="$journal->status"/>
            @if($editable && $isMaker)
                <a href="{{ route('ops.finance.journals.edit', $journal) }}" wire:navigate class="btn-secondary">Edit</a>
                <button wire:click="submit" class="btn-primary" wire:loading.attr="disabled">{{ $directPost ? 'Mark ready' : 'Submit for approval' }}</button>
                <button wire:click="discard" wire:confirm="Discard this draft voucher?" class="btn-ghost text-red-600">Discard</button>
            @endif
            @if($directPost && $journal->status->value === 'draft' && ! $isMaker)
                @can('fms.journals.post')<button wire:click="postDirect" wire:confirm="Post this voucher to the general ledger?" class="btn-primary">Post voucher</button>@endcan
            @endif
            @if($journal->status->value === 'posted' && ! $journal->reversal_requested_at)
                @can('fms.journals.reverse')<button wire:click="$set('showReverse', true)" class="btn-danger">Request reversal</button>@endcan
            @endif
        </x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($journal->status->value === 'posted' && $journal->reversal_requested_at)
        <x-alert tone="amber" class="mb-4" title="Reversal awaiting approval">Requested by {{ $journal->reversalRequester?->name }} on {{ $journal->reversal_requested_at->format('d M Y H:i') }}: “{{ $journal->reversal_reason }}”. A second authorised user must approve it (Approval tasks); the requester cannot.</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Lines" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Account</x-th><x-th>Description</x-th><x-th>Dimensions</x-th><x-th align="right">Debit</x-th><x-th align="right">Credit</x-th></x-slot:head>
                    @foreach($journal->lines as $l)
                        <tr>
                            <td><a href="{{ route('ops.finance.accounts.ledger', $l->account_id) }}" wire:navigate class="link">{{ $l->account->code }}</a> {{ $l->account->name }}</td>
                            <td class="text-neutral-600">{{ $l->description }}</td>
                            <td class="text-xs text-neutral-500">{{ collect([$l->branch?->name, $l->costCenter?->name])->filter()->join(' · ') ?: '—' }}</td>
                            <td class="text-right">@if(\App\Support\Money::isPositive($l->debit))<x-money :amount="$l->debit" :currency="false"/>@endif</td>
                            <td class="text-right">@if(\App\Support\Money::isPositive($l->credit))<x-money :amount="$l->credit" :currency="false"/>@endif</td>
                        </tr>
                    @endforeach
                    <x-slot:foot><tr><td colspan="3" class="px-4 py-3 text-right">Totals</td><td class="px-4 py-3 text-right"><x-money :amount="$journal->total_debit"/></td><td class="px-4 py-3 text-right"><x-money :amount="$journal->total_credit"/></td></tr></x-slot:foot>
                </x-table>
            </x-card>

            @if($history->isNotEmpty())
            <x-card title="Approval history">
                @foreach($history as $r)
                    <div class="mb-3 text-sm"><p class="font-medium">Request #{{ $r->id }} <x-status :value="$r->status"/></p>
                        <ol class="mt-2 space-y-1 border-l-2 border-neutral-200 pl-4">
                            @foreach($r->actions as $a)
                                <li><span class="font-medium">{{ ucfirst($a->action) }}</span> · {{ $a->stage_name }} · {{ $a->actor?->name }} · <span class="text-neutral-500">{{ $a->created_at->format('d M Y H:i') }}</span>
                                    @if($a->comment)<p class="text-neutral-600">“{{ $a->comment }}”</p>@endif</li>
                            @endforeach
                        </ol></div>
                @endforeach
            </x-card>
            @endif
        </div>

        <div class="space-y-6">
            <x-card title="Details">
                <x-dl :cols="1" :items="[
                    'Voucher date' => $journal->voucher_date->format('d M Y'),
                    'Period' => $journal->period?->name,
                    'Origin' => ucfirst($journal->origin).($journal->event_type ? ' · '.$journal->event_type : ''),
                    'Created by' => $journal->creator?->name ?? 'System',
                    'Posted by / at' => $journal->posted_at ? (($journal->poster?->name ?? 'System').' · '.$journal->posted_at->format('d M Y H:i')) : null,
                ]"/>
                <div class="mt-3 text-sm"><p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Source transaction</p>
                    @if($source[1])<a href="{{ $source[1] }}" wire:navigate class="link">{{ $source[0] }}</a>@else<p>{{ $source[0] }}</p>@endif</div>
                @if($journal->reversalOf)<p class="mt-3 text-sm">Reversal of <a class="link" wire:navigate href="{{ route('ops.finance.journals.show', $journal->reversalOf) }}">{{ $journal->reversalOf->voucher_no }}</a></p>@endif
                @if($journal->reversedBy)<p class="mt-3 text-sm">Reversed by <a class="link" wire:navigate href="{{ route('ops.finance.journals.show', $journal->reversedBy) }}">{{ $journal->reversedBy->voucher_no }}</a></p>@endif
            </x-card>

            @if($pending)
                <x-card title="Awaiting approval">
                    <p class="text-sm">Stage: <strong>{{ $pending->currentStage?->name }}</strong> ({{ $pending->currentStage?->role?->name }})</p>
                    @if($canDecide)
                        <x-textarea label="Comment / reason" wire:model="comment" rows="2" class="mt-3"/>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button wire:click="decide('approve')" class="btn-primary btn-sm">Approve & post</button>
                            <button wire:click="decide('return')" class="btn-secondary btn-sm">Return</button>
                            <button wire:click="decide('reject')" class="btn-danger btn-sm">Reject</button>
                        </div>
                    @else
                        <p class="mt-2 text-xs text-neutral-500">You cannot act on this stage (role or segregation-of-duties rule).</p>
                    @endif
                </x-card>
            @endif

            <x-card title="Attachments">
                @forelse($journal->documents as $d)
                    <a href="{{ route('documents.show', $d) }}" target="_blank" class="link block text-sm">{{ $d->title }} (v{{ $d->version }})</a>
                @empty
                    <p class="text-sm text-neutral-500">No supporting documents.</p>
                @endforelse
            </x-card>
        </div>
    </div>

    <x-modal show="showReverse" title="Request reversal of {{ $journal->voucher_no }}">
        <p class="text-sm text-neutral-600">After a second authorised user approves the request, a linked mirror-image voucher is posted. The original remains in the books for audit. Vouchers in closed periods cannot be reversed until the period is reopened.</p>
        <x-textarea label="Reason" wire:model="reason" class="mt-3" required/>
        <x-slot:footer><button wire:click="$set('showReverse', false)" class="btn-secondary">Cancel</button><button wire:click="reverse" class="btn-danger">Submit reversal request</button></x-slot:footer>
    </x-modal>
</div>
