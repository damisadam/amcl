<div>
    <x-page-header :title="$complaint->ticket_no.' · '.$complaint->subject" :back="route('ops.complaints.index')"
        :subtitle="$complaint->category->label().' · '.__('opened').' '.$complaint->created_at->format('d M Y H:i').' · '.__('via').' '.$complaint->channel">
        <x-slot:actions>
            <x-status :value="$complaint->status"/>
            @if($canManage)
                @foreach($transitions as $t)
                    <button wire:click="openTransition('{{ $t->value }}')" @class(['btn-sm', 'btn-primary' => $t->value === 'resolved', 'btn-danger' => $t->value === 'escalated', 'btn-secondary' => ! in_array($t->value, ['resolved','escalated'])])>{{ $t->label() }}</button>
                @endforeach
            @endif
        </x-slot:actions>
    </x-page-header>
    @if($complaint->isOverdue())<x-alert tone="red" class="mb-4">{{ __('SLA breached – response was due :d.', ['d' => $complaint->sla_due_at->format('d M Y')]) }}</x-alert>@endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-card :title="__('Conversation')">
                <div class="space-y-3">
                    @foreach($messages as $m)
                        <div @class(['rounded-lg p-3 text-sm', 'bg-amber-50 border border-amber-200' => $m->is_internal, 'bg-neutral-50' => ! $m->is_internal && $m->user?->isCustomer(), 'bg-brand-50' => ! $m->is_internal && $m->user?->isStaff(), 'bg-neutral-50 ' => ! $m->user])>
                            <p class="mb-1 text-xs text-neutral-500">{{ $m->user?->name ?? $complaint->customer?->full_name ?? __('Customer') }} · {{ $m->created_at->format('d M Y H:i') }} @if($m->is_internal)<x-badge tone="amber">{{ __('Internal note') }}</x-badge>@endif</p>
                            <p class="whitespace-pre-line">{{ $m->body }}</p>
                        </div>
                    @endforeach
                </div>
                @if($canManage && $complaint->status !== \App\Enums\ComplaintStatus::Closed)
                    <form wire:submit="sendReply" class="mt-4 space-y-2 border-t border-neutral-100 pt-4">
                        <x-textarea wire:model="reply" :label="__('Reply')" rows="3"/>
                        <div class="flex items-center justify-between"><x-checkbox wire:model="internal" :label="__('Internal note (not visible to customer)')"/><button class="btn-primary btn-sm">{{ __('Send') }}</button></div>
                    </form>
                @endif
            </x-card>
            @if($complaint->resolution)<x-card :title="__('Resolution')"><p class="whitespace-pre-line text-sm">{{ $complaint->resolution }}</p></x-card>@endif
        </div>
        <div class="space-y-4">
            <x-card :title="__('Details')">
                <x-dl :cols="1" :items="[
                    __('Customer') => $complaint->customer?->full_name.' ('.$complaint->customer?->cif_no.')', __('Mobile') => $complaint->customer && auth()->user()->hasPermission('customers.view_sensitive') ? $complaint->customer->mobile : ($complaint->customer ? substr($complaint->customer->mobile, 0, 4).'*****'.substr($complaint->customer->mobile, -2) : null),
                    __('Priority') => ucfirst($complaint->priority), __('SLA due') => $complaint->sla_due_at?->format('d M Y H:i'),
                    __('Acknowledged') => $complaint->acknowledged_at?->format('d M Y H:i'), __('Resolved') => $complaint->resolved_at?->format('d M Y H:i'),
                ]"/>
            </x-card>
            <x-card :title="__('Owner')">
                @if($canManage)
                    <div class="flex gap-2"><select wire:model="assignee" class="field-input"><option value="">{{ __('Select…') }}</option>@foreach($agents as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select><button wire:click="assign" class="btn-secondary btn-sm">{{ __('Assign') }}</button></div>
                @endif
                <p class="mt-2 text-sm">{{ $complaint->assignee?->name ?? __('Unassigned') }}</p>
            </x-card>
            @if($related)
                <x-card :title="__('Linked record')">
                    @if($related instanceof \App\Models\WalletTransaction)
                        <x-dl :cols="1" :items="[__('Transaction') => $related->reference, __('Type') => $related->type->label(), __('Amount') => \App\Support\Money::format($related->amount), __('Status') => $related->status->label(), __('Date') => $related->created_at->format('d M Y H:i')]"/>
                        @if($refundable)<button wire:click="$set('showRefund', true)" class="btn-danger btn-sm mt-3">{{ __('Refund / reverse this transaction') }}</button>@endif
                    @elseif($related instanceof \App\Models\LoanAccount)
                        <x-dl :cols="1" :items="[__('Financing') => $related->account_no, __('Status') => $related->status->label(), __('Outstanding') => \App\Support\Money::format($related->totalOutstanding())]"/>
                        @if(Route::has('ops.loans.show'))<a href="{{ route('ops.loans.show', $related) }}" wire:navigate class="link mt-2 inline-block text-sm">{{ __('Open loan account') }}</a>@endif
                    @elseif($related instanceof \App\Models\LoanApplication)
                        <x-dl :cols="1" :items="[__('Application') => $related->application_no, __('Status') => $related->status->label()]"/>
                    @else
                        <p class="text-sm">{{ class_basename($related) }} #{{ $related->getKey() }}</p>
                    @endif
                </x-card>
            @endif
        </div>
    </div>

    <x-modal show="showTransition" :title="__('Update status')">
        <form wire:submit="transition" class="space-y-3">
            <p class="text-sm">{{ __('New status') }}: <strong>{{ $targetStatus ? \App\Enums\ComplaintStatus::from($targetStatus)->label() : '' }}</strong></p>
            @if($targetStatus === 'resolved')<x-textarea :label="__('Resolution given to the customer')" wire:model="resolution" required/>@else<x-textarea :label="__('Note (optional)')" wire:model="note"/>@endif
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Confirm') }}</button></div>
        </form>
    </x-modal>
    <x-modal show="showRefund" :title="__('Refund disputed transaction')">
        <form wire:submit="refund" class="space-y-3">
            <x-alert>{{ __('Compensating wallet entries return the funds to the payer. Requires wallet reversal permission; you cannot reverse a transaction you initiated.') }}</x-alert>
            <x-textarea :label="__('Reason (audited)')" wire:model="refundReason" required/>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-danger">{{ __('Refund') }}</button></div>
        </form>
    </x-modal>
</div>
