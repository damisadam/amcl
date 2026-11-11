<div>
    <x-page-header :title="__('Company configuration')" :subtitle="__('Settings for this institution only; other tenants are unaffected. Every change is audited.')"/>
    @php($tabs = $canConfig ? ['settings' => __('Policies & limits'), 'holidays' => __('Holiday calendar'), 'payments' => __('Payment types'), 'categories' => __('Loan categories'), 'documents' => __('Document types'), 'branding' => __('Branding')] : [])
    @if($canBlacklist)@php($tabs['blacklist'] = __('Blacklist'))@endif
    <x-tabs :tabs="$tabs" :active="$tab"/>

    @if($tab === 'settings')
        <x-card>
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach($groups as $g)<button wire:click="$set('group', '{{ $g }}')" @class(['btn-sm', 'btn-dark' => $group === $g, 'btn-secondary' => $group !== $g])>{{ \Illuminate\Support\Str::headline($g) }}</button>@endforeach
            </div>
            <form wire:submit="saveGroup" class="grid gap-4 sm:grid-cols-2">
                @foreach($defaults as $key => $default)
                    @php($label = \Illuminate\Support\Str::headline($key))
                    @if(is_bool($default))
                        <x-checkbox wire:model="values.{{ $key }}" :label="$label"/>
                        @error("values.$key")<p class="field-error">{{ $message }}</p>@enderror
                    @elseif($key === 'days')
                        <div class="sm:col-span-2"><p class="field-label">{{ __('Working days') }}</p><div class="flex flex-wrap gap-3">
                            @foreach(\App\Livewire\Ops\Admin\Configuration::DAY_NAMES as $n => $d)<label class="flex items-center gap-1 text-sm"><input type="checkbox" value="{{ $n }}" wire:model="values.days" class="rounded border-neutral-300 text-brand-700">{{ __($d) }}</label>@endforeach
                        </div>@error('values.days')<p class="field-error">{{ $message }}</p>@enderror</div>
                    @elseif(is_array($default) && ! array_is_list($default))
                        <fieldset class="rounded-lg border border-neutral-200 p-3 sm:col-span-2"><legend class="px-1 text-xs font-semibold text-neutral-600">{{ $label }}</legend>
                            <div class="grid gap-2 sm:grid-cols-5">@foreach($default as $sub => $_)<x-input :label="\Illuminate\Support\Str::headline($sub)" type="number" step="0.01" wire:model="values.{{ $key }}.{{ $sub }}"/>@endforeach</div></fieldset>
                    @elseif(is_array($default))
                        <x-input :label="$label" wire:model="values.{{ $key }}" :hint="__('Comma separated')"/>
                    @else
                        <x-input :label="$label" wire:model="values.{{ $key }}" :type="is_numeric($default) ? 'number' : 'text'" step="any"/>
                    @endif
                @endforeach
                <div class="flex items-center justify-between gap-3 sm:col-span-2">
                    <p class="text-xs text-neutral-500">{{ __('Changes apply to new transactions; existing loans keep the terms snapshot taken at disbursement.') }}</p>
                    <button class="btn-primary">{{ __('Save :g settings', ['g' => \Illuminate\Support\Str::headline($group)]) }}</button>
                </div>
            </form>
            @if($group === 'wallet')<x-alert tone="blue" class="mt-4">{{ __('Wallet safeguarding model for this institution') }}: <strong>{{ \Illuminate\Support\Str::headline($tenant->wallet_safeguarding_model) }}</strong>. {{ __('The legal safeguarding arrangement must be confirmed before production (see DECISIONS D-001). All balances shown are SIMULATED.') }}</x-alert>@endif
        </x-card>
    @elseif($tab === 'holidays')
        <x-card :title="__('Holiday calendar')" :subtitle="__('Instalment due dates falling on holidays or non-working days roll forward to the next working day.')">
            <form wire:submit="addHoliday" class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end"><x-input :label="__('Date')" type="date" wire:model="holiday.date"/><x-input class="flex-1" :label="__('Name')" wire:model="holiday.name"/><button class="btn-primary">{{ __('Add') }}</button></form>
            <x-table><x-slot:head><x-th>{{ __('Date') }}</x-th><x-th>{{ __('Holiday') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($holidays as $h)<tr><td class="text-sm">{{ $h->date->format('D, d M Y') }}</td><td>{{ $h->name }}</td><td class="text-right"><button wire:click="removeHoliday({{ $h->id }})" wire:confirm="{{ __('Remove holiday?') }}" class="btn-ghost btn-sm text-red-600">{{ __('Remove') }}</button></td></tr>
                @empty<x-empty :colspan="3" :title="__('No holidays configured')"/>@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'payments')
        <x-card :padding="false" :title="__('Accepted repayment methods')">
            <x-table><x-slot:head><x-th>{{ __('Code') }}</x-th><x-th>{{ __('Payment type') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @foreach($paymentTypes as $p)<tr><td class="font-mono text-xs">{{ $p->code }}</td><td>{{ $p->name }}</td><td><x-status :value="$p->is_active ? 'active' : 'inactive'"/></td>
                    <td class="text-right"><button wire:click="togglePaymentType({{ $p->id }})" class="btn-secondary btn-sm">{{ $p->is_active ? __('Disable') : __('Enable') }}</button></td></tr>@endforeach
            </x-table>
        </x-card>
    @elseif($tab === 'categories')
        <x-card :title="__('Loan categories')">
            <form wire:submit="addCategory" class="mb-4 grid gap-2 sm:grid-cols-4 sm:items-end"><x-input :label="__('Code')" wire:model="category.code"/><x-input :label="__('Name')" wire:model="category.name"/><x-input :label="__('Regulatory category')" wire:model="category.regulatory_category"/><button class="btn-primary">{{ __('Add') }}</button></form>
            <x-table><x-slot:head><x-th>{{ __('Code') }}</x-th><x-th>{{ __('Name') }}</x-th><x-th>{{ __('Regulatory category') }}</x-th></x-slot:head>
                @forelse($categories as $c)<tr><td class="font-mono text-xs">{{ $c->code }}</td><td>{{ $c->name }}</td><td class="text-sm">{{ $c->regulatory_category }}</td></tr>@empty<x-empty :colspan="3"/>@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'blacklist')
        <x-card :title="__('Blacklist')" :subtitle="__('Checked automatically at onboarding and loan request.')">
            <form wire:submit="addBlacklist" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <x-input :label="__('Name')" wire:model="blacklist.name" required/><x-input :label="__('CNIC')" wire:model="blacklist.cnic" placeholder="35202-1234567-1"/>
                <x-select :label="__('Reason')" wire:model="blacklist.reason" :options="['prior_default' => __('Prior default'), 'fraud' => __('Fraud'), 'aml' => __('AML / CFT'), 'other' => __('Other')]"/>
                <x-input :label="__('Notes (audited)')" wire:model="blacklist.notes" required/>
                <div class="lg:col-span-4 flex justify-end"><button class="btn-danger">{{ __('Add to blacklist') }}</button></div>
            </form>
            <input type="search" wire:model.live.debounce.400ms="blacklistSearch" class="field-input mb-3 sm:max-w-xs" placeholder="{{ __('Search name or CNIC') }}">
            <x-table><x-slot:head><x-th>{{ __('Name') }}</x-th><x-th>{{ __('CNIC') }}</x-th><x-th>{{ __('Reason') }}</x-th><x-th>{{ __('Added') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($blacklistEntries as $b)<tr><td>{{ $b->name }}<div class="max-w-xs truncate text-xs text-neutral-500">{{ $b->notes }}</div></td><td class="text-xs tabular">{{ $b->cnic }}</td><td><x-badge tone="red">{{ \Illuminate\Support\Str::headline($b->reason) }}</x-badge></td>
                    <td class="text-xs">{{ $b->created_at->format('d M Y') }} · {{ $b->addedBy?->name }}</td><td><x-status :value="$b->is_active ? 'active' : 'inactive'"/></td>
                    <td class="text-right">@if($b->is_active)<button wire:click="deactivateBlacklist({{ $b->id }})" wire:confirm="{{ __('Remove this person from the blacklist?') }}" class="btn-ghost btn-sm">{{ __('Remove') }}</button>@endif</td></tr>
                @empty<x-empty :colspan="6" :title="__('Blacklist is empty')"/>@endforelse
            </x-table>
            @if($blacklistEntries){{ $blacklistEntries->links() }}@endif
        </x-card>
    @elseif($tab === 'documents')
        <x-card :padding="false" :title="__('Document type management')" :subtitle="__('Signing method controls the e-signature path: digital (e-sign), conditional/wet-ink (DPN, Affidavit pending legal clearance).')">
            <x-slot:actions><button wire:click="editDocType" class="btn-primary btn-sm">+ {{ __('Document type') }}</button></x-slot:actions>
            <x-table><x-slot:head><x-th>{{ __('Code') }}</x-th><x-th>{{ __('Name') }}</x-th><x-th>{{ __('Category') }}</x-th><x-th>{{ __('Validity') }}</x-th><x-th>{{ __('Signing') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @foreach($docTypes as $d)<tr><td class="font-mono text-xs">{{ $d->code }}</td><td>{{ $d->name }}<div class="font-urdu text-xs text-neutral-500">{{ $d->name_ur }}</div></td><td class="text-xs">{{ ucfirst($d->category) }}</td>
                    <td class="text-xs">{{ $d->validity_days ? $d->validity_days.' '.__('days') : '—' }} @if($d->tracks_expiry)<x-badge tone="amber">{{ __('expiry') }}</x-badge>@endif</td>
                    <td><x-badge :tone="match($d->signing_method) { 'digital' => 'green', 'conditional', 'wet_ink' => 'amber', default => 'gray' }">{{ \Illuminate\Support\Str::headline($d->signing_method) }}</x-badge></td>
                    <td><x-status :value="$d->is_active ? 'active' : 'inactive'"/></td><td class="text-right"><button wire:click="editDocType({{ $d->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></td></tr>@endforeach
            </x-table>
        </x-card>
    @elseif($tab === 'branding')
        <x-card :title="__('Branding & contacts')">
            <form wire:submit="saveBrand" class="grid gap-3 sm:grid-cols-2">
                <x-input :label="__('Display name')" wire:model="brand.name" required/><x-input :label="__('Legal name')" wire:model="brand.legal_name"/>
                <x-input :label="__('Tagline (English)')" wire:model="brand.tagline"/><x-input :label="__('Tagline (Urdu)')" wire:model="brand.tagline_ur" dir="rtl" class="font-urdu"/>
                <div><label class="field-label">{{ __('Primary colour') }}</label><div class="flex gap-2"><input type="color" wire:model.live="brand.primary_color" class="h-10 w-14 rounded border border-neutral-300"><input wire:model="brand.primary_color" class="field-input"></div>@error('brand.primary_color')<p class="field-error">{{ $message }}</p>@enderror</div>
                <x-select :label="__('Default language')" wire:model="brand.default_locale" :options="['en' => 'English', 'ur' => 'اردو']"/>
                <x-input :label="__('Grievance / support phone')" wire:model="brand.support_phone"/><x-input :label="__('Support email')" wire:model="brand.support_email"/>
                <x-input :label="__('Address')" wire:model="brand.address"/><x-input :label="__('City')" wire:model="brand.city"/>
                <div class="sm:col-span-2 flex items-center justify-between"><p class="text-xs text-neutral-500">{{ __('Licence ref') }}: {{ $tenant->license_ref ?? '—' }} · {{ __('Managed by the platform') }}</p><button class="btn-primary">{{ __('Save branding') }}</button></div>
            </form>
        </x-card>
    @endif

    <x-modal show="showDocType" :title="$docTypeId ? __('Edit document type') : __('New document type')" max-width="xl">
        <form wire:submit="saveDocType" class="grid gap-3 sm:grid-cols-2">
            <x-input :label="__('Code')" wire:model="docType.code" :hint="__('UPPER_SNAKE_CASE')" required/>
            <x-select :label="__('Category')" wire:model="docType.category" :options="collect(['identity','income','business','guarantor','loan','agreement','vendor','field','other'])->mapWithKeys(fn ($c) => [$c => ucfirst($c)])->all()"/>
            <x-input :label="__('Name (English)')" wire:model="docType.name" required/><x-input :label="__('Name (Urdu)')" wire:model="docType.name_ur" dir="rtl"/>
            <x-input :label="__('Validity (days)')" type="number" wire:model="docType.validity_days" :hint="__('e.g. 90 for a utility bill')"/>
            <x-select :label="__('Signing method')" wire:model="docType.signing_method" :options="['none' => __('Not signed'), 'digital' => __('Digital e-signature'), 'conditional' => __('Conditional (verify first)'), 'wet_ink' => __('Wet ink only')]"/>
            <x-input :label="__('Linked verification step')" wire:model="docType.verification_step" :hint="__('e.g. income_analysis, tasdeeq, kyc')"/>
            <div class="flex flex-col gap-2 pt-6"><x-checkbox wire:model="docType.tracks_expiry" :label="__('Track expiry with alerts')"/><x-checkbox wire:model="docType.is_active" :label="__('Active')"/></div>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</div>
