<div class="space-y-4" wire:poll.30s>
    <x-page-header :title="$c->subject" :subtitle="$c->ticket_no.' · '.__($c->category->label())" :back="route('app.support')"><x-slot:actions><x-status :value="$c->status"/></x-slot:actions></x-page-header>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
    <x-card>
        <x-dl :cols="3" :items="[__('Opened') => $c->created_at->format('d M Y H:i'), __('Response due by') => $c->sla_due_at?->format('d M Y'), __('Handled by') => $c->assignee?->name ?? __('Customer Grievances team')]"/>
        @if($c->related)<p class="mt-3 text-sm text-neutral-600">{{ __('Related to') }}: {{ $c->related->reference ?? $c->related->account_no ?? '' }}</p>@endif
        @if($c->resolution)<x-alert tone="green" class="mt-3" :title="__('Resolution')">{{ $c->resolution }}</x-alert>@endif
    </x-card>
    <x-card :title="__('Conversation')">
        <div class="space-y-3">
            @foreach($messages as $m)
                @php($mine = $m->user_id === auth()->id())
                <div @class(['flex', 'justify-end' => $mine])>
                    <div @class(['max-w-[85%] rounded-2xl px-4 py-2 text-sm', 'bg-brand-700 text-white' => $mine, 'bg-neutral-100' => ! $mine])>
                        <p class="whitespace-pre-line">{{ $m->body }}</p><p @class(['mt-1 text-[11px]', 'text-white/70' => $mine, 'text-neutral-500' => ! $mine])>{{ $mine ? __('You') : ($m->user?->name ?? __('Support')) }} · {{ $m->created_at->format('d M H:i') }}</p>
                    </div>
                </div>
            @endforeach
        </div>
        @if($c->status->value !== 'closed')
            <form wire:submit="reply" class="mt-4 flex gap-2"><x-textarea class="flex-1" wire:model="message" rows="2" :placeholder="__('Type a message')"/><button class="btn-primary self-end">{{ __('Send') }}</button></form>
        @endif
    </x-card>
</div>
