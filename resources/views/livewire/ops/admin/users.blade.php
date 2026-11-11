<div>
    <x-page-header :title="__('Users')" :subtitle="__('Staff and vendor-portal users of this institution. A user may hold multiple roles.')">
        <x-slot:actions>@if($canManage)<button wire:click="create" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New user') }}</button>@endif</x-slot:actions>
    </x-page-header>

    @if($tempPassword)
        <x-alert tone="green" class="mb-4" :title="__('Temporary password for :e', ['e' => $tempFor])">
            <p>{{ __('Share it securely; it is shown only once and must be changed at first sign-in.') }}</p>
            <p class="mt-1 font-mono text-lg font-bold tracking-wide">{{ $tempPassword }}</p>
            <button wire:click="dismissTemp" class="btn-secondary btn-sm mt-2">{{ __('I have noted it') }}</button>
        </x-alert>
    @endif

    <x-card :padding="false">
        <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-5">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Name, email, mobile…') }}" class="field-input">
            <select wire:model.live="filterRole" class="field-input"><option value="">{{ __('All roles') }}</option>@foreach($roles as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
            <select wire:model.live="filterBranch" class="field-input"><option value="">{{ __('All branches') }}</option>@foreach($branches as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select wire:model.live="filterStatus" class="field-input"><option value="">{{ __('Active & inactive') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterType" class="field-input"><option value="">{{ __('Staff & vendor users') }}</option><option value="staff">{{ __('Staff') }}</option><option value="vendor">{{ __('Vendor portal') }}</option></select>
        </div>
        <x-table>
            <x-slot:head><x-th sort="name" :current="$sort" :dir="$dir">{{ __('User') }}</x-th><x-th>{{ __('Roles') }}</x-th><x-th>{{ __('Branch') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th sort="last_login_at" :current="$sort" :dir="$dir">{{ __('Last login') }}</x-th><x-th></x-th></x-slot:head>
            @forelse($users as $u)
                <tr wire:key="u{{ $u->id }}">
                    <td><p class="font-medium">{{ $u->name }}</p><p class="text-xs text-neutral-500">{{ $u->email }} @if($u->designation)· {{ $u->designation }}@endif</p>
                        @if($u->isVendor())<x-badge tone="blue">{{ __('Vendor') }}: {{ $u->vendor?->business_name }}</x-badge>@endif</td>
                    <td class="max-w-xs"><div class="flex flex-wrap gap-1">@foreach($u->roles as $r)<x-badge :tone="$r->key === 'tenant_admin' ? 'black' : 'green'">{{ $r->name }}</x-badge>@endforeach</div></td>
                    <td class="text-xs">{{ $u->branch?->name }}</td>
                    <td><x-status :value="$u->status"/>@if($u->status->value !== 'locked' && $u->locked_until && $u->locked_until->isFuture())<x-badge tone="red" class="ml-1">{{ __('locked until :t', ['t' => $u->locked_until->format('H:i')]) }}</x-badge>@endif @if($u->must_change_password)<x-badge tone="amber" class="ml-1">{{ __('pwd change') }}</x-badge>@endif</td>
                    <td class="text-xs text-neutral-500">{{ $u->last_login_at?->diffForHumans() ?? __('never') }}</td>
                    <td class="whitespace-nowrap text-right">
                        <div x-data="{ m:false }" class="relative inline-block text-left">
                            <button x-on:click="m=!m" class="btn-ghost btn-sm">⋯</button>
                            <div x-show="m" x-cloak x-on:click.outside="m=false" class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-neutral-200 bg-white py-1 text-left text-sm shadow-lg">
                                <button wire:click="activity({{ $u->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-50">{{ __('Activity & devices') }}</button>
                                @if($canManage && ! $u->trashed())<button wire:click="edit({{ $u->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-50">{{ __('Edit / roles') }}</button>@endif
                                @if($canReset)
                                    @if($u->locked_until && $u->locked_until->isFuture())<button wire:click="unlock({{ $u->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-50">{{ __('Unlock') }}</button>@endif
                                    @if($u->id !== auth()->id())<button wire:click="resetPassword({{ $u->id }})" wire:confirm="{{ __('Reset this user\'s password?') }}" class="block w-full px-3 py-1.5 text-left hover:bg-neutral-50">{{ __('Force password reset') }}</button>@endif
                                @endif
                                @if($canManage && $u->id !== auth()->id() && ! $u->trashed())
                                    @foreach(['active' => __('Reactivate'), 'inactive' => __('Deactivate'), 'suspended' => __('Suspend'), 'archived' => __('Archive')] as $st => $lbl)
                                        @if($u->status->value !== $st)<button wire:click="openStatus({{ $u->id }}, '{{ $st }}')" @class(['block w-full px-3 py-1.5 text-left hover:bg-neutral-50', 'text-red-600' => in_array($st, ['suspended','archived'])])>{{ $lbl }}</button>@endif
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <x-empty :colspan="6" :title="__('No users match these filters')"/>
            @endforelse
        </x-table>
        {{ $users->links() }}
    </x-card>

    <x-modal show="showForm" :title="$editingId ? __('Edit user') : __('New user')" max-width="2xl">
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
            @unless($editingId)<x-select :label="__('User type')" wire:model.live="form.type" :options="['staff' => __('Staff'), 'vendor' => __('Vendor portal user')]"/>@endunless
            <x-input :label="__('Full name')" wire:model="form.name" required/>
            <x-input :label="__('Email (login)')" type="email" wire:model="form.email" required/>
            <x-input :label="__('Mobile')" wire:model="form.mobile" placeholder="03001234567"/>
            @if($form['type'] === 'vendor')
                <x-select :label="__('Vendor')" wire:model="form.vendor_id" :options="$vendors->all()" :placeholder="__('Select…')" required/>
            @else
                <x-input :label="__('Designation')" wire:model="form.designation"/>
                <x-select :label="__('Branch')" wire:model="form.branch_id" :options="$branches->all()" :placeholder="__('—')"/>
                <x-select :label="__('Department')" wire:model="form.department_id" :options="$departments->all()" :placeholder="__('—')"/>
                <x-select :label="__('Linked employee record')" wire:model="form.employee_id" :options="$employees->all()" :placeholder="__('—')"/>
                <div class="sm:col-span-2">
                    <p class="field-label">{{ __('Roles') }} <span class="text-red-600">*</span></p>
                    @if($editingId === auth()->id())<p class="field-hint mb-1">{{ __('You cannot change your own roles.') }}</p>@endif
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        @foreach($roles as $r)
                            <label class="flex items-start gap-2 rounded-md border border-neutral-200 p-2 text-sm"><input type="checkbox" value="{{ $r->id }}" wire:model="roleIds" @disabled($editingId === auth()->id()) class="mt-0.5 rounded border-neutral-300 text-brand-700">
                                <span><span class="font-medium">{{ $r->name }}</span><span class="block text-xs text-neutral-500">{{ $r->description }}</span></span></label>
                        @endforeach
                    </div>
                    @error('roleIds')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            @endif
            @error('action')<p class="field-error sm:col-span-2">{{ $message }}</p>@enderror
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary" wire:loading.attr="disabled">{{ __('Save') }}</button></div>
        </form>
    </x-modal>

    <x-modal show="showStatus" :title="__('Change user status')">
        <form wire:submit="changeStatus" class="space-y-3">
            <p class="text-sm">{{ __('New status') }}: <strong>{{ $targetStatus ? \App\Enums\UserStatus::from($targetStatus)->label() : '' }}</strong></p>
            <x-textarea :label="__('Reason (audited)')" wire:model="statusReason" required/>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Confirm') }}</button></div>
        </form>
    </x-modal>

    <x-modal show="showActivity" :title="__('Activity & devices')" max-width="2xl">
        @if($activityUser)
            <x-dl :items="[__('User') => $activityUser->name, __('Last login') => $activityUser->last_login_at?->format('d M Y H:i'), __('Last IP') => $activityUser->last_login_ip, __('Failed attempts') => $activityUser->failed_login_attempts, __('Password changed') => $activityUser->password_changed_at?->format('d M Y')]"/>
            <h4 class="mt-4 text-sm font-semibold">{{ __('Devices') }}</h4>
            <ul class="mt-1 space-y-1 text-xs">@forelse($activityUser->devices as $d)<li>{{ $d->label }} · {{ $d->ip }} · {{ $d->last_seen_at?->diffForHumans() }} @if($d->revoked_at)<x-badge tone="red">{{ __('revoked') }}</x-badge>@endif</li>@empty<li class="text-neutral-500">{{ __('No devices recorded.') }}</li>@endforelse</ul>
            <h4 class="mt-4 text-sm font-semibold">{{ __('Recent actions') }}</h4>
            <ul class="mt-1 space-y-1 text-xs">@forelse($activityLog as $a)<li>{{ $a->created_at->format('d M H:i') }} · {{ $a->action }}</li>@empty<li class="text-neutral-500">{{ __('No recorded actions.') }}</li>@endforelse</ul>
        @endif
    </x-modal>
</div>
