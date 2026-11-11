<div>
    <x-page-header :title="__('Roles & permissions')" :subtitle="__('Roles are defined per institution. Permissions are explicit grants; sensitive ones are flagged.')">
        <x-slot:actions>@if($canManage)<button wire:click="$set('showCreate', true)" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New role') }}</button>@endif</x-slot:actions>
    </x-page-header>
    <div class="grid gap-4 lg:grid-cols-4">
        <x-card :padding="false" class="lg:col-span-1">
            <ul class="divide-y divide-neutral-100">
                @foreach($roles as $r)
                    <li><button wire:click="select({{ $r->id }})" @class(['w-full px-4 py-2.5 text-left text-sm', 'bg-brand-50 font-semibold text-brand-900' => $current?->id === $r->id, 'hover:bg-neutral-50' => $current?->id !== $r->id])>
                        {{ $r->name }} <span class="float-right text-xs text-neutral-500">{{ $r->users_count }} {{ __('users') }}</span>
                        @if($r->is_system)<x-badge tone="black" class="ml-1">{{ __('system') }}</x-badge>@endif</button></li>
                @endforeach
            </ul>
        </x-card>
        <div class="lg:col-span-3">
            @if($current)
                <x-card :title="$current->name" :subtitle="$current->description">
                    <x-slot:actions>
                        @if($canManage)
                            <button wire:click="save" class="btn-primary btn-sm">{{ __('Save permissions') }}</button>
                            @unless($current->is_system)<button wire:click="delete" wire:confirm="{{ __('Delete this role?') }}" class="btn-danger btn-sm">{{ __('Delete') }}</button>@endunless
                        @endif
                    </x-slot:actions>
                    @if($current->key === 'tenant_admin')<x-alert tone="blue" class="mb-4">{{ __('Tenant Admin administers configuration, users and roles. By default it does NOT hold operational decision permissions (approvals, KYC/AML decisions, payout release, posting or reversing journals, reversals) – assign the relevant operational role explicitly if needed; that assignment is audited. User and role administration permissions cannot be removed from this role.') }}</x-alert>@endif
                    @if($stagesUsing->isNotEmpty())<p class="mb-3 text-xs text-neutral-500">{{ __('Approval stages using this role') }}: {{ $stagesUsing->map(fn ($s) => $s->workflow?->name.' › '.$s->name)->join('; ') }}</p>@endif
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach($catalog as $module => $perms)
                            <fieldset class="rounded-lg border border-neutral-200 p-3">
                                <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-neutral-600">{{ $module }}</legend>
                                @foreach($perms as $p)
                                    <label class="flex items-start gap-2 py-1 text-sm">
                                        <input type="checkbox" value="{{ $p['key'] }}" wire:model="selected" @disabled(! $canManage || ($current->key === 'tenant_admin' && in_array($p['key'], \App\Livewire\Ops\Admin\Roles::ADMIN_ESSENTIAL, true))) class="mt-0.5 rounded border-neutral-300 text-brand-700">
                                        <span>{{ $p['label'] }} @if($p['sensitive'])<x-badge tone="red">{{ __('sensitive') }}</x-badge>@endif<span class="block font-mono text-[11px] text-neutral-400">{{ $p['key'] }}</span></span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                </x-card>
            @else
                <x-card><x-empty :title="__('No roles yet')"/></x-card>
            @endif
        </div>
    </div>

    <x-modal show="showCreate" :title="__('New role')">
        <form wire:submit="create" class="space-y-3">
            <x-input :label="__('Role name')" wire:model="newName" required/>
            <x-input :label="__('Description')" wire:model="newDescription"/>
            <x-select :label="__('Copy permissions from')" wire:model="copyFrom" :options="$roles->pluck('name', 'id')->all()" :placeholder="__('Start empty')"/>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Create') }}</button></div>
        </form>
    </x-modal>
</div>
