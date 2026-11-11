<div>
    <x-page-header :title="__('Approval workflows')" :subtitle="__('Configure approval hierarchies per type, product, branch and amount. No code changes required.')">
        <x-slot:actions><button wire:click="editWorkflow" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New workflow') }}</button></x-slot:actions>
    </x-page-header>
    <div class="mb-4 flex flex-wrap gap-2">@foreach($types as $k => $v)<button wire:click="$set('type', '{{ $k }}')" @class(['btn-sm', 'btn-dark' => $type === $k, 'btn-secondary' => $type !== $k])>{{ $v }}</button>@endforeach</div>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card :padding="false" :title="__('Workflows')" :subtitle="__('Most specific match wins (product/branch), then lowest priority.')">
            <ul class="divide-y divide-neutral-100">
                @forelse($workflows as $w)
                    <li @class(['flex items-start justify-between gap-2 px-4 py-3', 'bg-brand-50' => $current?->id === $w->id])>
                        <button wire:click="$set('workflowId', {{ $w->id }})" class="min-w-0 text-left">
                            <p class="text-sm font-medium">{{ $w->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $w->stages_count }} {{ __('stages') }} · {{ __('priority') }} {{ $w->priority }}
                                @if($w->product) · {{ $w->product->name }}@endif @if($w->branch) · {{ $w->branch->name }}@endif
                                @if($w->min_amount || $w->max_amount) · {{ \App\Support\Money::format($w->min_amount ?? 0, false) }}–{{ $w->max_amount ? \App\Support\Money::format($w->max_amount, false) : '∞' }}@endif</p>
                        </button>
                        <button wire:click="toggleActive({{ $w->id }})" title="{{ __('Toggle active') }}"><x-status :value="$w->is_active ? 'active' : 'inactive'"/></button>
                    </li>
                @empty
                    <li><x-empty :title="__('No workflow for this type')" :description="__('Requests of this type cannot be submitted until a workflow exists.')"/></li>
                @endforelse
            </ul>
        </x-card>

        <div class="lg:col-span-2">
            @if($current)
                <x-card :title="$current->name" :subtitle="$current->type->label()">
                    <x-slot:actions>
                        <button wire:click="editWorkflow({{ $current->id }})" class="btn-secondary btn-sm">{{ __('Edit') }}</button>
                        <button wire:click="editStage" class="btn-primary btn-sm">+ {{ __('Stage') }}</button>
                    </x-slot:actions>
                    @if($inFlight)<x-alert tone="blue" class="mb-4">{{ __(':n request(s) in flight on this workflow keep the stage plan captured when they were submitted.', ['n' => $inFlight]) }}</x-alert>@endif
                    <ol class="space-y-2">
                        @foreach($current->stages as $i => $s)
                            <li class="flex items-center gap-3 rounded-lg border border-neutral-200 p-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-ink text-sm font-bold text-white">{{ $i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">{{ $s->name }}</p>
                                    <p class="text-xs text-neutral-500">{{ __('Role') }}: {{ $s->role?->name }}
                                        @if($s->min_amount) · {{ __('only when amount ≥') }} {{ \App\Support\Money::format($s->min_amount) }}@endif
                                        · {{ __('SLA') }} {{ $s->sla_hours ? $s->sla_hours.'h' : '—' }}
                                        @if($s->comment_required_on_approve) · {{ __('comment on approve') }}@endif
                                        @if(! $s->allow_return) · {{ __('no return') }}@endif</p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <button wire:click="move({{ $s->id }}, -1)" class="btn-ghost btn-sm" @disabled($i === 0) title="{{ __('Move up') }}">↑</button>
                                    <button wire:click="move({{ $s->id }}, 1)" class="btn-ghost btn-sm" @disabled($loop->last) title="{{ __('Move down') }}">↓</button>
                                    <button wire:click="editStage({{ $s->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button>
                                    <button wire:click="removeStage({{ $s->id }})" wire:confirm="{{ __('Remove this stage?') }}" class="btn-ghost btn-sm text-red-600">{{ __('Remove') }}</button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-4 text-xs text-neutral-500">{{ __('Controls: the submitter/creator can never approve; by default a user may approve only one stage of a request.') }}</p>
                </x-card>
            @endif
        </div>
    </div>

    <x-modal show="showWorkflow" :title="($wf['id'] ?? null) ? __('Edit workflow') : __('New workflow')" max-width="xl">
        <form wire:submit="saveWorkflow" class="grid gap-3 sm:grid-cols-2">
            <x-select :label="__('Type')" wire:model="wf.type" :options="$types"/>
            <x-input :label="__('Name')" wire:model="wf.name" required/>
            <x-select :label="__('Product (optional)')" wire:model="wf.product_id" :options="$products->all()" :placeholder="__('All products')"/>
            <x-select :label="__('Branch (optional)')" wire:model="wf.branch_id" :options="$branches->all()" :placeholder="__('All branches')"/>
            <x-input :label="__('Minimum amount')" type="number" step="0.01" wire:model="wf.min_amount"/>
            <x-input :label="__('Maximum amount')" type="number" step="0.01" wire:model="wf.max_amount"/>
            <x-input :label="__('Priority (lower first)')" type="number" wire:model="wf.priority"/>
            <div class="pt-6"><x-checkbox wire:model="wf.is_active" :label="__('Active')"/></div>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>

    <x-modal show="showStage" :title="$stageId ? __('Edit stage') : __('Add stage')">
        <form wire:submit="saveStage" class="grid gap-3 sm:grid-cols-2">
            <x-input class="sm:col-span-2" :label="__('Stage name')" wire:model="stage.name" required/>
            <x-select :label="__('Approver role')" wire:model="stage.role_id" :options="$roles->all()" :placeholder="__('Select…')" required/>
            <x-input :label="__('Applies at/above amount')" type="number" step="0.01" wire:model="stage.min_amount" :hint="__('Leave empty to always apply')"/>
            <x-input :label="__('SLA (hours)')" type="number" wire:model="stage.sla_hours"/>
            <div class="flex flex-col gap-2 sm:col-span-2">
                <x-checkbox wire:model="stage.comment_required_on_approve" :label="__('Comment required to approve')"/>
                <x-checkbox wire:model="stage.comment_required_on_reject" :label="__('Reason required to reject')"/>
                <x-checkbox wire:model="stage.allow_return" :label="__('Allow return for correction')"/>
            </div>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save stage') }}</button></div>
        </form>
    </x-modal>
</div>
