<div>
    <x-page-header :title="$tenant->name" :subtitle="$tenant->legal_name ?: 'Tenant code: '.$tenant->code" :back="route('platform.tenants.index')">
        <x-slot:actions>
            <x-status :value="$tenant->status"/>
            @if(auth()->user()->hasPermission('platform.tenants.suspend'))
                @if($tenant->status->value !== 'active')<button wire:click="askStatus('active')" class="btn-primary btn-sm">Activate</button>@endif
                @if($tenant->status->value === 'active')<button wire:click="askStatus('suspended')" class="btn-secondary btn-sm">Suspend</button>@endif
                @if($tenant->status->value !== 'inactive')<button wire:click="askStatus('inactive')" class="btn-danger btn-sm">Deactivate</button>@endif
            @endif
        </x-slot:actions>
    </x-page-header>

    @if($newCredentials)
        <x-alert tone="green" title="Temporary administrator credentials (shown once)" class="mb-4">
            @if($newCredentials['email'])Email: <strong>{{ $newCredentials['email'] }}</strong> · @endif Password: <strong class="font-mono">{{ $newCredentials['password'] }}</strong>
            — the administrator must change it at first sign-in. Share it through a secure channel.
        </x-alert>
    @endif
    @if($tenant->suspension_reason && $tenant->status->value !== 'active')<x-alert tone="red" class="mb-4">{{ ucfirst($tenant->status->value) }} {{ $tenant->suspended_at?->diffForHumans() }}: {{ $tenant->suspension_reason }}. Users of this tenant cannot sign in.</x-alert>@endif

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-stat label="Users" :value="$health['users'] ?? 0"/>
        <x-stat label="Customers" :value="$health['customers'] ?? 0" hint="count only"/>
        <x-stat label="Active loans" :value="$health['active_loans'] ?? 0" hint="count only"/>
        <x-stat label="Pending payments" :value="$health['pending_payments'] ?? 0" tone="amber"/>
        <x-stat label="Integration errors 24h" :value="$health['integration_errors_24h'] ?? 0" :tone="($health['integration_errors_24h'] ?? 0) ? 'red' : 'green'"/>
    </div>

    <x-tabs :tabs="['profile' => 'Profile & branding', 'features' => 'Feature entitlements', 'access' => 'Entry points & admins', 'integrations' => 'Integration modes', 'audit' => 'Platform audit']" :active="$tab"/>

    @if($tab === 'profile')
        <form wire:submit="saveProfile">
            <x-card>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input label="Display name" wire:model="form.name" required/>
                    <x-input label="Legal name" wire:model="form.legal_name"/>
                    <x-input label="Registration no." wire:model="form.registration_no"/>
                    <x-input label="Licence reference" wire:model="form.license_ref"/>
                    <x-input label="NTN" wire:model="form.ntn"/>
                    <x-input label="City" wire:model="form.city" required/>
                    <x-input label="Address" wire:model="form.address"/>
                    <x-input label="Primary contact" wire:model="form.primary_contact_name"/>
                    <x-input label="Primary contact email" wire:model="form.primary_contact_email"/>
                    <x-input label="Primary contact phone" wire:model="form.primary_contact_phone"/>
                    <x-input label="Support phone" wire:model="form.support_phone"/>
                    <x-input label="Support email" wire:model="form.support_email"/>
                    <div><label class="field-label">Brand colour</label><div class="flex gap-2"><input type="color" wire:model.live="form.primary_color" class="h-10 w-14 rounded border border-neutral-300"><input wire:model.live="form.primary_color" class="field-input"></div></div>
                    <x-select label="Default language" wire:model="form.default_locale" :options="['en' => 'English', 'ur' => 'Urdu']"/>
                    <x-input label="Tagline (English)" wire:model="form.tagline"/>
                    <x-input label="Tagline (Urdu)" wire:model="form.tagline_ur" dir="rtl"/>
                    <x-select label="Wallet safeguarding model" wire:model="form.wallet_safeguarding_model" :options="['tenant_safeguarded' => 'Tenant-owned safeguarded account', 'platform_pooled' => 'Platform (AMCL) pooled safeguarded account']" hint="Legal model unconfirmed – see DECISIONS D-001."/>
                    <x-checkbox wire:model="form.is_demo" label="Demo tenant" class="mt-6"/>
                </div>
                @if(auth()->user()->hasPermission('platform.tenants.manage'))<div class="mt-4 flex justify-end"><button class="btn-primary">Save profile</button></div>@endif
            </x-card>
        </form>
    @elseif($tab === 'features')
        <x-card title="Modules this institution may use">
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach(\App\Models\Tenant::FEATURES as $key => $label)<x-checkbox wire:model="features" value="{{ $key }}" :label="$label"/>@endforeach
            </div>
            <p class="mt-3 text-xs text-neutral-500">Disabled modules are hidden from navigation and blocked server-side for this tenant's users.</p>
            @if(auth()->user()->hasPermission('platform.tenants.manage'))<div class="mt-4 flex justify-end"><button wire:click="saveFeatures" class="btn-primary">Save entitlements</button></div>@endif
        </x-card>
    @elseif($tab === 'access')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Entry points">
                <x-dl :cols="1" :items="['Customer portal' => url('/t/'.$tenant->code), 'Staff & vendor sign-in' => route('staff.login', ['org' => $tenant->code]), 'Vendor onboarding request' => url('/t/'.$tenant->code.'/vendor-request'), 'Webhook base' => url('/webhooks/'.$tenant->code.'/{provider}')]"/>
            </x-card>
            <x-card title="Tenant administrators">
                <ul class="divide-y divide-neutral-100 text-sm">
                    @forelse($admins as $a)
                        <li class="flex items-center justify-between py-2"><span>{{ $a->name }} <span class="text-neutral-500">· {{ $a->email }}</span></span><span class="text-xs">{!! $a->must_change_password ? '<span class="text-amber-700">must change password</span>' : 'last login '.($a->last_login_at?->diffForHumans() ?? 'never') !!}</span></li>
                    @empty
                        <li class="py-3 text-neutral-500">No administrator found.</li>
                    @endforelse
                </ul>
                @if(auth()->user()->hasPermission('platform.tenants.manage'))
                    <button wire:click="resetAdminPassword" wire:confirm="Generate a new temporary password for the initial administrator? Their current password will stop working." class="btn-secondary btn-sm mt-4">Reset initial admin password</button>
                @endif
            </x-card>
        </div>
    @elseif($tab === 'integrations')
        <x-card title="Provider modes (credentials are never shown on the platform)">
            <x-table>
                <x-slot:head><x-th>Provider</x-th><x-th>Enabled</x-th><x-th>Mode</x-th><x-th>Environment</x-th><x-th>Live approved</x-th></x-slot:head>
                @forelse($matrix as $provider => $m)
                    <tr><td>{{ config('integrations.providers.'.$provider.'.name', $provider) }}</td><td>{{ $m['enabled'] ? 'Yes' : 'No' }}</td>
                        <td><x-badge :tone="$m['mode'] === 'live' ? 'red' : 'amber'">{{ strtoupper($m['mode']) }}</x-badge></td><td>{{ $m['environment'] }}</td><td>{{ $m['live_approved'] ? 'Yes' : 'No' }}</td></tr>
                @empty
                    <x-empty colspan="5" title="No integration settings"/>
                @endforelse
            </x-table>
        </x-card>
    @else
        <x-card title="Platform actions on this tenant">
            <x-table>
                <x-slot:head><x-th>When</x-th><x-th>Action</x-th><x-th>By</x-th><x-th>Reason</x-th></x-slot:head>
                @forelse($audit as $a)
                    <tr><td class="text-xs">{{ $a->created_at->format('d M Y H:i') }}</td><td>{{ $a->action }}</td><td>{{ $a->actor?->name ?? 'system' }}</td><td class="text-xs">{{ $a->reason }}</td></tr>
                @empty
                    <x-empty colspan="4" title="No platform actions yet"/>
                @endforelse
            </x-table>
        </x-card>
    @endif

    <x-modal show="showStatus" title="Change tenant status">
        <p class="text-sm text-neutral-600">New status: <strong>{{ \Illuminate\Support\Str::headline($targetStatus) }}</strong>. @if($targetStatus !== 'active')All users of {{ $tenant->name }} will be signed out and blocked until reactivated.@endif</p>
        <x-textarea class="mt-3" label="Reason" wire:model="statusReason" :required="$targetStatus !== 'active'"/>
        @error('action')<p class="field-error">{{ $message }}</p>@enderror
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false" type="button">Cancel</button><button wire:click="changeStatus" class="{{ $targetStatus === 'active' ? 'btn-primary' : 'btn-danger' }}">Confirm</button></x-slot:footer>
    </x-modal>
</div>
