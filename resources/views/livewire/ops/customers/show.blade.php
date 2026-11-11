<div>
    <x-page-header :title="$c->full_name" :back="route('ops.customers.index')">
        <x-slot:actions>
            @can('customers.update')@if($c->kyc_status !== \App\Enums\KycStatus::Passed)
                @if(\App\Services\Payments\Simulation::enabled())<x-select wire:model="kycScenario" :options="config('integrations.kyc_scenarios')"/>@endif
                <button wire:click="runKyc" wire:loading.attr="disabled" class="btn-dark">{{ __('Run e-KYC') }}</button>
            @endif @endcan
            @can('customers.update')<button wire:click="openEdit" class="btn-secondary">{{ __('Edit') }}</button>@endcan
            @can('applications.create')@if($c->isKycPassed())<a href="{{ route('ops.applications.create', ['customer' => $c->id]) }}" wire:navigate class="btn-primary">{{ __('New application') }}</a>@endif @endcan
        </x-slot:actions>
    </x-page-header>
    <div class="-mt-4 mb-6 flex flex-wrap items-center gap-2 text-sm">
        <span class="tabular font-semibold">{{ $c->cif_no }}</span><x-status :value="$c->status"/><span class="text-neutral-400">KYC</span><x-status :value="$c->kyc_status"/>
        @if($canAml)<span class="text-neutral-400">AML</span><x-status :value="$c->aml_status"/>@endif
        @if($c->risk_rating)<span class="text-neutral-400">Risk</span><x-status :value="$c->risk_rating"/>@endif
        @if($c->is_blacklisted)<x-badge tone="red">Blacklisted: {{ $c->blacklist_reason }}</x-badge>@endif
    </div>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror

    <x-tabs :active="$tab" :tabs="['profile' => 'Profile', 'kyc' => 'KYC & verification', 'documents' => 'Documents ('.$docs->count().')', 'financing' => 'Applications & loans', 'wallet' => 'Wallet', 'notes' => 'Notes ('.$notes->count().')', 'consents' => 'Consents', 'changes' => 'Change requests', 'admin' => 'Admin']"/>

    @if($tab === 'profile')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Identity & contact" class="lg:col-span-2">
                <x-dl :cols="3" :items="[
                    'CNIC' => $sensitive ? $c->cnic : $c->maskedCnic(), 'CNIC expiry' => $c->cnic_expiry_date?->format('d M Y'), 'Date of birth' => $c->date_of_birth?->format('d M Y').($c->age() ? ' ('.$c->age().' yrs)' : ''),
                    'Father / husband' => $c->father_husband_name, 'Gender' => ucfirst((string) $c->gender), 'Marital status' => ucfirst((string) $c->marital_status),
                    'Mobile' => $sensitive ? $c->mobile : substr($c->mobile, 0, 4).'*****'.substr($c->mobile, -2), 'Mobile verified' => $c->user?->mobile_verified_at?->format('d M Y H:i') ?? 'No', 'Email' => $c->email,
                    'Current address' => $c->current_address, 'Permanent address' => $c->permanent_address, 'City' => $c->city,
                ]"/>
            </x-card>
            <x-card title="Relationship">
                <x-dl :cols="1" :items="['Client type' => \Illuminate\Support\Str::headline($c->client_type), 'Branch' => $c->branch?->name, 'Relationship officer' => $c->officer?->name,
                    'Onboarding channel' => ucfirst($c->onboarding_channel), 'Origin vendor' => $c->originVendor?->business_name, 'Customer since' => $c->created_at->format('d M Y'), 'Activated' => $c->activated_at?->format('d M Y')]"/>
            </x-card>
            <x-card title="Employment & income" class="lg:col-span-2">
                <x-dl :cols="3" :items="['Employment' => \Illuminate\Support\Str::headline((string) $c->employment_type), 'Occupation' => $c->occupation, 'Employer' => $c->employer_name,
                    'Business' => trim($c->business_name.' '.($c->business_type ? '('.$c->business_type.')' : '')), 'Income source' => $c->income_source,
                    'Monthly income' => \App\Support\Money::format($c->monthly_income), 'Other income' => \App\Support\Money::format($c->other_income), 'Monthly obligations' => \App\Support\Money::format($c->existing_obligations)]"/>
            </x-card>
            <x-card title="Next of kin">
                @forelse($c->nextOfKins as $n)<p class="text-sm"><span class="font-medium">{{ $n->name }}</span> · {{ $n->relation }}<br><span class="text-neutral-500">{{ $n->mobile }}</span></p>
                @empty<p class="text-sm text-neutral-500">None recorded.</p>@endforelse
            </x-card>
        </div>
    @elseif($tab === 'kyc')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Verification results" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Step</x-th><x-th>Provider</x-th><x-th>Result</x-th><x-th align="right">Score</x-th><x-th>When</x-th><x-th>Review</x-th></x-slot:head>
                    @forelse($kyc as $k)
                        <tr><td class="font-medium">{{ $k->step->label() }}</td><td class="text-xs">{{ config('integrations.providers.'.$k->provider.'.name', $k->provider) }} <x-badge tone="amber">simulated</x-badge></td>
                            <td><x-status :value="$k->status"/>@if($k->result['message'] ?? null)<div class="text-xs text-neutral-500">{{ $k->result['message'] }}</div>@endif
                                @if($k->step->value === 'aml_screening' && !empty($k->result['hits']))@foreach($k->result['hits'] as $h)<div class="text-xs text-red-700">{{ $h['list'] }} · {{ $h['category'] ?? '' }} · {{ $h['score'] }}</div>@endforeach @endif
                                @if($k->step->value === 'nadra_verisys' && ($k->result['note'] ?? null))<div class="text-xs text-amber-700">{{ $k->result['note'] }}</div>@endif</td>
                            <td class="text-right tabular">{{ $k->score !== null ? number_format($k->score, 1) : '—' }}</td><td class="whitespace-nowrap text-xs">{{ $k->created_at->format('d M Y H:i') }}</td>
                            <td class="text-xs">@if($k->reviewed_at){{ ucfirst($k->review_decision) }} by {{ $k->reviewer?->name }}<div class="text-neutral-500">{{ $k->review_notes }}</div>@endif</td></tr>
                    @empty <x-empty colspan="6" title="e-KYC has not been run" description="Upload the CNIC front, capture NADRA consent and run e-KYC."/> @endforelse
                </x-table>
            </x-card>
            <x-card title="Manual review">
                @if($c->kyc_status === \App\Enums\KycStatus::PendingReview)
                    <x-textarea label="Review notes" wire:model="reviewNotes" rows="3" required/>
                    <div class="mt-3 space-y-2">
                        @can('kyc.review')<div class="flex gap-2"><button wire:click="reviewKyc(true)" class="btn-primary btn-sm flex-1">Approve identity</button><button wire:click="reviewKyc(false)" class="btn-danger btn-sm flex-1">Reject identity</button></div>@endcan
                        @if($canAml && $c->aml_status === \App\Enums\AmlStatus::PotentialMatch)@can('aml.review')
                            <div class="flex gap-2"><button wire:click="reviewAml(true)" class="btn-secondary btn-sm flex-1">Clear AML match</button><button wire:click="reviewAml(false)" wire:confirm="Confirming a match blacklists the customer. Continue?" class="btn-danger btn-sm flex-1">Confirm AML match</button></div>
                        @endcan @endif
                    </div>
                @else<p class="text-sm text-neutral-500">No review pending. KYC status: {{ $c->kyc_status->label() }}.</p>@endif
            </x-card>
        </div>
    @elseif($tab === 'documents')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Documents" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Document</x-th><x-th>Version</x-th><x-th>Status</x-th><x-th>Expiry</x-th><x-th>Uploaded</x-th><x-th></x-th></x-slot:head>
                    @forelse($docs as $d)
                        <tr @class(['opacity-60' => $d->status === 'superseded'])>
                            <td class="font-medium">@if(!($d->restricted ?? false))<a href="{{ route('documents.show', $d) }}" target="_blank" class="link">{{ $d->type?->name ?? $d->title }}</a>@else{{ $d->type?->name }} <x-badge>restricted</x-badge>@endif
                                <div class="text-xs text-neutral-500">{{ $d->original_name }} · {{ number_format($d->size / 1024) }} KB @if($d->is_synthetic)<x-badge tone="amber">synthetic</x-badge>@endif</div></td>
                            <td>v{{ $d->version }}</td><td><x-status :value="$d->status"/>@if($d->rejection_reason)<div class="text-xs text-red-600">{{ $d->rejection_reason }}</div>@endif</td>
                            <td>@if($d->expires_on)<span @class(['text-red-600 font-semibold' => $d->isExpired(), 'text-amber-700' => $d->expiresSoon()])>{{ $d->expires_on->format('d M Y') }}</span>@else—@endif</td>
                            <td class="text-xs">{{ $d->created_at->format('d M Y') }}<br>{{ $d->uploader?->name }}</td>
                            <td class="whitespace-nowrap text-right">@if($d->status === 'pending')@can('documents.verify')<button wire:click="verifyDocument({{ $d->id }})" class="btn-secondary btn-sm">Verify</button>
                                <button wire:click="askReject({{ $d->id }})" class="btn-ghost btn-sm text-red-600">Reject</button>@endcan @endif</td></tr>
                    @empty <x-empty colspan="6" title="No documents uploaded"/> @endforelse
                </x-table>
            </x-card>
            @can('documents.upload')
            <x-card title="Upload document">
                <form wire:submit="uploadDocument" class="space-y-3">
                    <x-select label="Type" wire:model="uploadType" placeholder="Select type" :options="$docTypes" required/>
                    <div><label class="field-label">File</label><input type="file" wire:model="docFile" class="field-input" accept="image/*,application/pdf">@error('docFile')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <x-input label="Expiry date (if applicable)" type="date" wire:model="uploadExpiry"/>
                    <button class="btn-primary w-full" wire:loading.attr="disabled">Upload</button>
                    <p class="field-hint">Re-uploading a type creates a new version; the previous version is kept as superseded.</p>
                </form>
            </x-card>
            @endcan
        </div>
    @elseif($tab === 'financing')
        <x-card title="Applications" :padding="false" class="mb-6">
            <x-table>
                <x-slot:head><x-th>Application</x-th><x-th>Product</x-th><x-th align="right">Amount</x-th><x-th>Status</x-th><x-th>Created</x-th></x-slot:head>
                @forelse($applications as $a)
                    <tr><td><a href="{{ route('ops.applications.show', $a) }}" wire:navigate class="link">{{ $a->application_no }}</a></td><td>{{ $a->product->name }}</td>
                        <td class="text-right"><x-money :amount="$a->requested_amount"/></td><td><x-status :value="$a->status"/></td><td>{{ $a->created_at->format('d M Y') }}</td></tr>
                @empty <x-empty colspan="5" title="No applications"/> @endforelse
            </x-table>
        </x-card>
        @can('loans.view')
        <x-card title="Financing accounts" :padding="false">
            <x-table>
                <x-slot:head><x-th>Account</x-th><x-th>Product</x-th><x-th align="right">Principal</x-th><x-th align="right">Outstanding</x-th><x-th align="right">DPD</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($loans as $l)
                    <tr><td>@if(Route::has('ops.loans.show'))<a href="{{ route('ops.loans.show', $l) }}" wire:navigate class="link">{{ $l->account_no }}</a>@else{{ $l->account_no }}@endif</td><td>{{ $l->product->name }}</td>
                        <td class="text-right"><x-money :amount="$l->principal"/></td><td class="text-right"><x-money :amount="$l->totalOutstanding()"/></td><td class="text-right tabular">{{ $l->dpd }}</td><td><x-status :value="$l->status"/></td></tr>
                @empty <x-empty colspan="6" title="No financing accounts"/> @endforelse
            </x-table>
        </x-card>
        @endcan
    @elseif($tab === 'wallet')
        @if($c->wallet)
            <div class="mb-4 grid gap-4 sm:grid-cols-4">
                <x-stat label="Wallet" :value="$c->wallet->wallet_no" :hint="$c->wallet->status->label()" tone="black"/>
                @can('wallet.view')<x-stat label="Balance" :value="\App\Support\Money::format($c->wallet->balance)" :hint="'Held '.\App\Support\Money::format($c->wallet->held_balance)"/>
                <x-stat label="Daily limit" :value="\App\Support\Money::format($c->wallet->daily_limit)" tone="gray"/><x-stat label="Per transaction" :value="\App\Support\Money::format($c->wallet->per_transaction_limit)" tone="gray"/>@endcan
            </div>
            @can('wallet.view')
            <x-card title="Recent wallet transactions" :padding="false">
                <x-table><x-slot:head><x-th>Reference</x-th><x-th>Type</x-th><x-th align="right">Amount</x-th><x-th>Status</x-th><x-th>Date</x-th></x-slot:head>
                    @forelse($walletTxns as $t)<tr><td class="tabular">{{ $t->reference }}</td><td>{{ $t->type->label() }}</td><td class="text-right {{ $t->isCredit() ? 'text-brand-700' : '' }}">{{ $t->isCredit() ? '+' : '−' }}<x-money :amount="$t->amount"/></td><td><x-status :value="$t->status"/></td><td>{{ $t->created_at->format('d M Y H:i') }}</td></tr>
                    @empty <x-empty colspan="5" title="No transactions"/> @endforelse
                </x-table>
            </x-card>
            @endcan
        @else
            <x-empty title="No wallet" description="A wallet is opened automatically when KYC passes and the institution has the Wallet feature."/>
        @endif
    @elseif($tab === 'notes')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-3 lg:col-span-2">
                @forelse($notes as $n)
                    <div class="card p-4"><div class="flex justify-between text-xs text-neutral-500"><span>{{ $n->user?->name }} · <x-badge>{{ $n->category }}</x-badge></span><span>{{ $n->created_at->format('d M Y H:i') }}</span></div>
                        <p class="mt-2 whitespace-pre-line text-sm">{{ $n->body }}</p></div>
                @empty <x-empty title="No notes yet"/> @endforelse
            </div>
            <x-card title="Add note">
                <form wire:submit="addNote" class="space-y-3">
                    <x-select wire:model="noteCategory" :options="['general' => 'General', 'kyc' => 'KYC', 'collection' => 'Collection', 'complaint' => 'Complaint', 'risk' => 'Risk']"/>
                    <x-textarea wire:model="note" rows="4" required/><button class="btn-primary w-full">Add note</button>
                </form>
            </x-card>
        </div>
    @elseif($tab === 'consents')
        <x-card :padding="false">
            <x-table><x-slot:head><x-th>Consent</x-th><x-th>Granted</x-th><x-th>Language</x-th><x-th>Recorded</x-th><x-th>Source</x-th><x-th>Withdrawn</x-th></x-slot:head>
                @forelse($consents as $k)<tr><td class="font-medium">{{ $k->consent_type->label() }}<div class="max-w-md text-xs text-neutral-500">{{ $k->text_snapshot }}</div></td>
                    <td>@if($k->granted)<x-badge tone="green">Yes</x-badge>@else<x-badge tone="red">No</x-badge>@endif</td><td>{{ strtoupper($k->locale) }}</td><td class="text-xs">{{ $k->recorded_at->format('d M Y H:i') }}<br>{{ $k->ip }}</td>
                    <td class="max-w-xs truncate text-xs">{{ $k->user_agent }}</td><td>{{ $k->withdrawn_at?->format('d M Y') ?? '—' }}</td></tr>
                @empty <x-empty colspan="6" title="No consents recorded"/> @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'changes')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Sensitive change requests" class="lg:col-span-2" :padding="false">
                <x-table><x-slot:head><x-th>Field</x-th><x-th>Change</x-th><x-th>Reason</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                    @forelse($changes as $r)
                        <tr><td>{{ \Illuminate\Support\Str::headline($r->field) }}</td>
                            <td class="text-xs">{{ $r->field === 'cnic' && !$sensitive ? '*****' : $r->old_value }} → <strong>{{ $r->field === 'cnic' && !$sensitive ? '*****' : $r->new_value }}</strong></td>
                            <td class="text-xs">{{ $r->reason }}<br><span class="text-neutral-500">by {{ $r->requester?->name }}</span></td>
                            <td><x-status :value="$r->status"/>@if($r->decider)<div class="text-xs text-neutral-500">{{ $r->decider->name }}: {{ $r->decision_comment }}</div>@endif</td>
                            <td class="whitespace-nowrap text-right">@if($r->status === 'pending')@can('customers.approve_changes')
                                <button wire:click="decideChange({{ $r->id }}, true)" class="btn-secondary btn-sm">Approve</button><button wire:click="decideChange({{ $r->id }}, false)" class="btn-ghost btn-sm text-red-600">Reject</button>@endcan @endif</td></tr>
                    @empty <x-empty colspan="5" title="No change requests"/> @endforelse
                </x-table>
                @can('customers.approve_changes')<div class="border-t border-neutral-100 p-4"><x-input label="Decision comment" wire:model="decisionComment"/></div>@endcan
            </x-card>
            @can('customers.update')
            <x-card title="Request identity change" subtitle="CNIC and identity fields change only after approval by a second user (LMS §2.2).">
                <form wire:submit="requestChange" class="space-y-3">
                    <x-select label="Field" wire:model="changeField" :options="['cnic' => 'CNIC', 'full_name' => 'Full name', 'date_of_birth' => 'Date of birth', 'father_husband_name' => 'Father / husband name']"/>
                    <x-input label="New value" wire:model="changeValue" required/><x-textarea label="Reason / evidence" wire:model="changeReason" required/>
                    <button class="btn-primary w-full">Submit for approval</button>
                </form>
            </x-card>
            @endcan
        </div>
    @elseif($tab === 'admin')
        <div class="grid gap-6 lg:grid-cols-2">
            @can('config.blacklist')
            <x-card :title="$c->is_blacklisted ? 'Remove from blacklist' : 'Add to blacklist'">
                <form wire:submit="toggleBlacklist" class="space-y-3"><x-textarea label="Reason" wire:model="blacklistReason" required/>
                    <button class="{{ $c->is_blacklisted ? 'btn-secondary' : 'btn-danger' }}">{{ $c->is_blacklisted ? 'Remove from blacklist' : 'Blacklist customer' }}</button></form>
            </x-card>
            @endcan
            @can('customers.archive')
            <x-card title="Archive customer" subtitle="Soft delete: history is preserved for regulatory retention. Not allowed with open financing or wallet balance.">
                <button wire:click="$set('showArchive', true)" class="btn-danger">Archive…</button>
            </x-card>
            @endcan
        </div>
    @endif

    <x-modal show="showReject" title="Reject document">
        <x-textarea label="Reason (shown to the customer)" wire:model="rejectReason" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="rejectDocument" class="btn-danger">Reject</button></x-slot:footer>
    </x-modal>
    <x-modal show="showArchive" title="Archive {{ $c->full_name }}">
        <x-textarea label="Reason" wire:model="archiveReason" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="archive" class="btn-danger">Archive customer</button></x-slot:footer>
    </x-modal>
    <x-modal show="showEdit" title="Edit customer" maxWidth="2xl">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Email" wire:model="edit.email"/><x-input label="City" wire:model="edit.city"/>
            <x-input class="sm:col-span-2" label="Current address" wire:model="edit.current_address"/><x-input class="sm:col-span-2" label="Permanent address" wire:model="edit.permanent_address"/>
            <x-select label="Employment" wire:model="edit.employment_type" :options="['salaried' => 'Salaried', 'self_employed' => 'Self-employed', 'business' => 'Business', 'unemployed' => 'Not employed']"/>
            <x-input label="Occupation" wire:model="edit.occupation"/><x-input label="Employer" wire:model="edit.employer_name"/><x-input label="Business name" wire:model="edit.business_name"/>
            <x-input label="Monthly income" type="number" step="0.01" wire:model="edit.monthly_income"/><x-input label="Other income" type="number" step="0.01" wire:model="edit.other_income"/>
            <x-input label="Monthly obligations" type="number" step="0.01" wire:model="edit.existing_obligations"/>
        </div>
        <p class="mt-3 text-xs text-neutral-500">Identity fields (CNIC, name, DOB) change only through an approved change request.</p>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="saveEdit" class="btn-primary">Save</button></x-slot:footer>
    </x-modal>
</div>
