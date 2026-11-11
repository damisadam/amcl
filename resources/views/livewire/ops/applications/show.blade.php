@php($S = \App\Enums\ApplicationStatus::class)
@php($s = $a->status)
<div wire:poll.30s>
    <x-page-header :title="$a->application_no.' · '.$a->customer->full_name" :back="route('ops.applications.index')">
        <x-slot:actions>
            @if(in_array($s, [$S::Draft, $S::Returned]))@can('applications.submit')<button wire:click="submit" wire:loading.attr="disabled" class="btn-primary">Submit</button>@endcan @endif
            @if($s === $S::Submitted)@can('applications.verify')<button wire:click="startReview" class="btn-primary">Start review</button>@endcan @endif
            @if($s === $S::UnderReview)@canany(['applications.verify', 'applications.assess'])<button wire:click="sendForApproval" wire:confirm="Send this case into the configured approval hierarchy?" class="btn-primary">Send for approval</button>@endcanany @endif
            @if(in_array($s, [$S::Submitted, $S::UnderReview]))@can('applications.return')<button wire:click="openModal('return')" class="btn-secondary">Return</button>@endcan @endif
            @if(in_array($s, [$S::Submitted, $S::UnderReview, $S::Returned]))@can('applications.reject')<button wire:click="openModal('reject')" class="btn-danger">Reject</button>@endcan @endif
            @if(in_array($s, [$S::Draft, $S::Submitted, $S::UnderReview, $S::Returned, $S::PendingApproval]))@can('applications.create')<button wire:click="openModal('withdraw')" class="btn-ghost">Withdraw</button>@endcan @endif
            @if(in_array($s, [$S::Rejected, $S::Withdrawn, $S::Cancelled]))@can('applications.reevaluate')
                <button wire:click="reapply(true)" wire:confirm="Create a re-evaluation case referencing this application?" class="btn-secondary">Re-evaluate</button>
                <button wire:click="reapply(false)" wire:confirm="Create a reapplication (waiting period applies)?" class="btn-secondary">Reapply</button>@endcan @endif
            @if($s === $S::ReadyForDisbursement && Route::has('ops.disbursements.index'))@can('disbursements.view')<a href="{{ route('ops.disbursements.index') }}" wire:navigate class="btn-primary">Disbursement queue</a>@endcan @endif
        </x-slot:actions>
    </x-page-header>
    <div class="-mt-4 mb-5 flex flex-wrap items-center gap-2 text-sm">
        <x-status :value="$s"/><span class="text-neutral-500">{{ $a->product->name }} · {{ $a->product->shariah_structure->label() }} · {{ ucfirst($a->channel) }} · {{ $a->branch?->name }}</span>
        @if($a->credit_grade)<x-badge tone="black">Grade {{ $a->credit_grade }}</x-badge>@endif @if($a->risk_rating)<x-status :value="$a->risk_rating"/>@endif
        @if($a->is_reevaluation)<x-badge tone="blue">Re-evaluation</x-badge>@endif
        @if($a->previousApplication)<a class="link text-xs" href="{{ route('ops.applications.show', $a->previousApplication) }}" wire:navigate>Previous: {{ $a->previousApplication->application_no }}</a>@endif
    </div>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if(in_array($s, [$S::UnderReview, $S::PendingApproval, $S::Approved, $S::KfsAcknowledged, $S::CoolingOff, $S::ReadyForDisbursement]))
        @include('livewire.ops.applications._readiness', ['checks' => $readiness, 'class' => 'mb-5'])
    @endif
    @if($s === $S::UnderReview && $assessmentStale)
        <x-alert class="mb-5" title="Credit assessment is out of date">Bureau, field-visit, guarantor or income data changed after the assessment was run. <button wire:click="$set('tab', 'assessment')" class="link">Re-run assessment</button> before sending for approval.</x-alert>
    @endif
    @if($s === $S::PendingApproval && $req)
        <x-card class="mb-5">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @foreach($req->stage_plan as $i => $st)
                    @php($done = $req->actions->where('approval_stage_id', $st['id'])->where('action', 'approve')->isNotEmpty())
                    <span @class(['rounded-full px-3 py-1 font-medium', 'bg-brand-700 text-white' => $done, 'bg-amber-100 text-amber-900 ring-2 ring-amber-400' => $req->current_stage_id === $st['id'], 'bg-neutral-100 text-neutral-500' => ! $done && $req->current_stage_id !== $st['id']])>{{ $i + 1 }}. {{ $st['name'] }}</span>
                    @if(! $loop->last)<span class="text-neutral-300">→</span>@endif
                @endforeach
                @if($req->isOverdue())<x-badge tone="red">Stage SLA overdue</x-badge>@endif
            </div>
            @if($canDecide)
                <div class="mt-4 grid gap-3 border-t border-neutral-100 pt-4 sm:grid-cols-[1fr_auto]">
                    <x-textarea wire:model="comment" rows="2" placeholder="Comment (required to reject / return{{ $req->currentStage?->comment_required_on_approve ? ' and to approve at this stage' : '' }})"/>
                    <div class="flex flex-wrap items-start gap-2">
                        <button wire:click="approval('approve')" class="btn-primary">Approve</button>
                        <button wire:click="approval('return')" class="btn-secondary">Return</button>
                        <button wire:click="approval('reject')" wire:confirm="Reject this application?" class="btn-danger">Reject</button>
                        @if($delegates)<select wire:model="delegateTo" class="field-input w-40"><option value="">Delegate to…</option>@foreach($delegates as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                            <button wire:click="approval('delegate')" class="btn-ghost btn-sm">Delegate</button>@endif
                    </div>
                </div>
            @else
                <p class="mt-3 text-xs text-neutral-500">Awaiting <strong>{{ $req->currentStage?->name }}</strong> ({{ $req->currentStage?->role?->name }}).</p>
            @endif
        </x-card>
    @endif
    @if($checklist)
        <x-alert class="mb-5" title="Before this application can be submitted">
            <ul class="mt-1 list-disc pl-5">@foreach($checklist as $item)<li>{{ $item }}</li>@endforeach</ul>
        </x-alert>
    @endif

    <x-tabs :active="$tab" :tabs="['overview' => 'Overview', 'documents' => 'Documents'.(count($missingDocs) ? ' ('.count($missingDocs).' missing)' : ''), 'guarantors' => 'Guarantors ('.$a->guarantors->count().')',
        'bureau' => 'Credit bureau', 'visits' => 'Field visits ('.$a->fieldVisits->count().')', 'assessment' => 'Credit assessment', 'objections' => 'Objections ('.$a->objections->where('status', 'open')->count().' open)',
        'approvals' => 'Approval history', 'agreement' => 'KFS & agreement', 'timeline' => 'Timeline']"/>

    @if($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Financing request" class="lg:col-span-2">
                <x-dl :cols="3" :items="['Product' => $a->product->name.' (v'.$a->productVersion->version.')', 'Structure' => $a->product->shariah_structure->label(),
                    'Asset' => $a->asset_description, 'Vendor' => $a->vendor?->business_name, 'Asset price' => $a->asset_price ? \App\Support\Money::format($a->asset_price) : null,
                    'Down payment' => $a->asset_price ? \App\Support\Money::format($a->down_payment) : null, 'Requested amount' => \App\Support\Money::format($a->requested_amount),
                    'Approved amount' => $a->approved_amount ? \App\Support\Money::format($a->approved_amount) : null, 'Tenure' => $a->tenure.' '.($a->frequency === 'weekly' ? 'weeks' : 'months'),
                    'Indicative instalment' => $a->indicative_instalment ? \App\Support\Money::format($a->indicative_instalment) : null, 'Profit rate' => $a->productVersion->profit_rate.'% p.a. ('.$a->productVersion->profit_method.')',
                    'DBR' => $a->dbr ? $a->dbr.'% (limit '.$a->productVersion->max_dbr.'%)' : null, 'Purpose' => $a->purpose, 'Funding source' => $a->fund?->name, 'Loan officer' => $a->officer?->name]"/>
                @if($a->rejection_notes)<x-alert tone="red" class="mt-4" :title="'Rejected: '.(\App\Services\Lending\ApplicationService::REJECTION_CODES[$a->rejection_code] ?? $a->rejection_code)">{{ $a->rejection_notes }}</x-alert>@endif
            </x-card>
            <x-card title="Applicant">
                <x-dl :cols="1" :items="['Name' => $a->customer->full_name, 'CIF' => $a->customer->cif_no, 'CNIC' => $sensitive ? $a->customer->cnic : $a->customer->maskedCnic(),
                    'Age' => $a->customer->age(), 'Employment' => \Illuminate\Support\Str::headline((string) $a->customer->employment_type), 'Monthly income' => \App\Support\Money::format($a->customer->totalIncome()),
                    'Obligations' => \App\Support\Money::format($a->customer->existing_obligations), 'KYC' => $a->customer->kyc_status->label()] + ($canAml ? ['AML' => $a->customer->aml_status->label()] : [])"/>
                @can('customers.view')<a href="{{ route('ops.customers.show', $a->customer) }}" wire:navigate class="link mt-3 inline-block text-sm">Open customer 360° →</a>@endcan
            </x-card>
        </div>
    @elseif($tab === 'documents')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Case documents (application & customer)" class="lg:col-span-2" :padding="false">
                @if($missingDocs)<div class="border-b border-neutral-100 p-4"><x-alert>Missing required: {{ implode(', ', $missingDocs) }}</x-alert></div>@endif
                @can('documents.verify')<div class="border-b border-neutral-100 p-4"><x-input wire:model="reason" placeholder="Rejection reason (used when you click Reject)"/></div>@endcan
                <x-table>
                    <x-slot:head><x-th>Document</x-th><x-th>Owner</x-th><x-th>Status</x-th><x-th>Signing</x-th><x-th>Uploaded</x-th><x-th></x-th></x-slot:head>
                    @forelse($docs as $d)
                        @php($restricted = in_array($d->type?->code, ['CNIC_FRONT', 'CNIC_BACK', 'SELFIE', 'GUARANTOR_CNIC']) && ! $sensitive)
                        <tr @class(['opacity-50' => $d->status === 'superseded'])>
                            <td>@if(! $restricted)<a href="{{ route('documents.show', $d) }}" target="_blank" class="link">{{ $d->title }}</a>@else{{ $d->title }} <x-badge>restricted</x-badge>@endif<div class="text-xs text-neutral-500">v{{ $d->version }} · {{ $d->original_name }}</div></td>
                            <td class="text-xs">{{ class_basename($d->documentable_type) === 'Customer' ? 'Customer' : 'Application' }}</td>
                            <td><x-status :value="$d->status"/>@if($d->rejection_reason)<div class="text-xs text-red-600">{{ $d->rejection_reason }}</div>@endif</td>
                            <td class="text-xs">{{ $d->type?->signing_method !== 'none' ? ucfirst(str_replace('_', '-', $d->type?->signing_method)) : '—' }}</td>
                            <td class="text-xs">{{ $d->created_at->format('d M Y') }}<br>{{ $d->uploader?->name }}</td>
                            <td class="whitespace-nowrap text-right">@if($d->status === 'pending')@can('documents.verify')<button wire:click="verifyDocument({{ $d->id }}, true)" class="btn-secondary btn-sm">Verify</button>
                                <button wire:click="verifyDocument({{ $d->id }}, false)" class="btn-ghost btn-sm text-red-600">Reject</button>@endcan @endif</td></tr>
                    @empty <x-empty colspan="6" title="No documents"/> @endforelse
                </x-table>
            </x-card>
            @can('documents.upload')
            <x-card title="Upload to case file">
                <form wire:submit="uploadDocument" class="space-y-3">
                    <x-select label="Type" wire:model="uploadType" :options="$docTypes" placeholder="Select type" required/>
                    <div><input type="file" wire:model="docFile" class="field-input" accept="image/*,application/pdf">@error('docFile')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <button class="btn-primary w-full" wire:loading.attr="disabled">Upload</button>
                </form>
            </x-card>
            @endcan
        </div>
    @elseif($tab === 'guarantors')
        <x-card :padding="false" title="Guarantors" :subtitle="'Product requires '.$a->productVersion->guarantors_required.' guarantor(s).'">
            <x-slot:actions>@can('applications.create')@if(! in_array($s, [$S::Disbursed, $S::Rejected, $S::Withdrawn, $S::Cancelled]))<button wire:click="openModal('guarantor')" class="btn-primary btn-sm">Add guarantor</button>@endif @endcan</x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Name</x-th><x-th>CNIC</x-th><x-th>Mobile</x-th><x-th>Relation</x-th><x-th align="right">Income</x-th><x-th>Consent</x-th>@if($canAml)<x-th>AML / PEP</x-th>@endif<x-th>Verification</x-th><x-th>Signed</x-th><x-th></x-th></x-slot:head>
                @forelse($a->guarantors as $g)
                    @php($gr = $guarantorReadiness[$g->id])
                    <tr><td class="font-medium">{{ $g->name }}</td><td class="whitespace-nowrap tabular">{{ $sensitive ? $g->cnic : substr($g->cnic, 0, 5).'-*******-'.substr($g->cnic, -1) }}</td><td class="whitespace-nowrap">{{ $sensitive ? $g->mobile : substr($g->mobile, 0, 4).'****'.substr($g->mobile, -3) }}</td><td>{{ $g->relation }}</td>
                        <td class="text-right">@if(\App\Support\Money::isPositive($g->monthly_income ?? 0))<x-money :amount="$g->monthly_income"/>@else<span class="text-red-600">Not recorded</span>@endif</td><td>{{ $g->consent_at ? $g->consent_at->format('d M Y') : 'No' }}</td>
                        @if($canAml)<td>@if($g->aml_checked_at)<x-status :value="$g->aml_status"/><div class="text-xs text-neutral-500">{{ $g->aml_checked_at->format('d M H:i') }}</div>@else<span class="text-xs text-neutral-500">Not screened</span>@endif</td>@endif
                        <td><x-status :value="$gr['status']"/>@if($gr['reasons'])<ul class="mt-1 list-disc pl-4 text-xs text-neutral-600">@foreach($gr['reasons'] as $why)<li>{{ $why }}</li>@endforeach</ul>@endif</td>
                        <td>{{ $g->signed_at?->format('d M Y') ?? '—' }}</td>
                        <td class="text-right">@canany(['bureau.request', 'applications.verify'])@if($g->consent_at && ! in_array($s, [$S::Disbursed, $S::Rejected, $S::Withdrawn, $S::Cancelled]))<button wire:click="screenGuarantor({{ $g->id }})" class="btn-ghost btn-sm whitespace-nowrap">{{ $g->aml_checked_at ? 'Re-screen AML' : 'Run AML screening' }}</button>@endif @endcanany</td></tr>
                @empty <x-empty colspan="10" title="No guarantors"/> @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'bureau')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Bureau reports" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Subject</x-th><x-th>Provider</x-th><x-th>Result</x-th><x-th align="right">Score</x-th><x-th align="right">Facilities</x-th><x-th align="right">Monthly obligations</x-th><x-th align="right">Max DPD 12m</x-th><x-th>Date</x-th></x-slot:head>
                    @forelse($a->bureauChecks->sortByDesc('id') as $b)
                        <tr><td>{{ ucfirst($b->subject_type) }}<div class="text-xs text-neutral-500">{{ $b->report['subject_name'] ?? '' }}</div></td><td>{{ ucfirst($b->provider) }} <x-badge tone="amber">simulated</x-badge></td>
                            <td><x-status :value="$b->status"/></td><td class="text-right tabular">{{ $b->score ?? '—' }}</td><td class="text-right tabular">{{ $b->active_facilities ?? '—' }}</td>
                            <td class="text-right">@if($b->monthly_obligations !== null)<x-money :amount="$b->monthly_obligations"/>@else—@endif</td><td class="text-right tabular">{{ $b->max_dpd_12m ?? '—' }}</td><td class="text-xs">{{ $b->created_at->format('d M Y H:i') }}</td></tr>
                    @empty <x-empty colspan="8" title="No bureau reports" description="Bureau checks require the applicant's itemised credit-bureau consent."/> @endforelse
                </x-table>
            </x-card>
            @can('bureau.request')
            <x-card title="Request report">
                <div class="space-y-3">
                    <x-select label="Bureau" wire:model="bureauProvider" :options="['tasdeeq' => 'Tasdeeq', 'datacheck' => 'Data Check']"/>
                    @if(\App\Services\Payments\Simulation::enabled())<x-select label="Simulation" wire:model="bureauScenario" :options="['auto' => 'Deterministic by CNIC', 'adverse' => 'Force adverse', 'fail' => 'Bureau unavailable']"/>@endif
                    <button wire:click="requestBureau('applicant')" class="btn-primary w-full">Applicant report</button>
                    @if($a->guarantors->isNotEmpty())
                        <x-select label="Guarantor" wire:model="bureauGuarantor" :options="$a->guarantors->pluck('name', 'id')->all()" placeholder="Select guarantor"/>
                        <button wire:click="requestBureau('guarantor')" class="btn-secondary w-full">Guarantor report</button>
                    @endif
                    <p class="field-hint">Scores are deterministic simulations, not real bureau data.</p>
                </div>
            </x-card>
            @endcan
        </div>
    @elseif($tab === 'visits')
        <x-card title="Field verification" :padding="false">
            <x-slot:actions>@can('field_visits.assign')@if(in_array($s, [$S::Submitted, $S::UnderReview, $S::Returned]))<button wire:click="openModal('visit')" class="btn-primary btn-sm">Schedule visit</button>@endif @endcan</x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Type</x-th><x-th>Officer</x-th><x-th>Scheduled</x-th><x-th>Status</x-th><x-th>Recommendation</x-th><x-th>Remarks</x-th></x-slot:head>
                @forelse($a->fieldVisits as $v)
                    <tr><td><a href="{{ route('ops.visits.show', $v) }}" wire:navigate class="link">{{ ucfirst($v->visit_type) }}</a></td><td>{{ $v->officer?->name }}</td><td>{{ $v->scheduled_for->format('d M Y') }}</td>
                        <td><x-status :value="$v->status"/></td><td>@if($v->recommendation)<x-status :value="$v->recommendation"/>@else—@endif</td><td class="max-w-sm text-xs">{{ $v->remarks }}</td></tr>
                @empty <x-empty colspan="6" title="No visits scheduled"/> @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'assessment')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card title="Latest assessment" class="lg:col-span-2">
                @if($assessment)
                    <div class="grid gap-4 sm:grid-cols-4">
                        <x-stat label="Internal score" :value="$assessment->internal_score.'/100'" tone="black"/><x-stat label="Grade" :value="$assessment->grade" tone="black"/>
                        <x-stat label="DBR" :value="$assessment->dbr.'%'" :hint="'Limit '.$assessment->dbr_limit.'%'" :tone="$assessment->dbr_pass ? 'green' : 'red'"/>
                        <x-stat label="Risk" :value="ucfirst($assessment->risk_rating)" :hint="'Recommendation: '.ucfirst($assessment->recommendation)" :tone="['low' => 'green', 'medium' => 'amber', 'high' => 'red'][$assessment->risk_rating]"/>
                    </div>
                    <h3 class="mb-2 mt-5 text-sm font-semibold">Score breakdown</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach(($assessment->score_breakdown ?? []) as $k => $pts)
                            @if($k !== 'aml' || $canAml)<div class="flex justify-between rounded bg-neutral-50 px-3 py-1.5 text-sm"><span>{{ \Illuminate\Support\Str::headline($k) }}</span><span @class(['font-semibold tabular', 'text-red-600' => $pts < 0])>{{ $pts > 0 ? '+' : '' }}{{ $pts }}</span></div>@endif
                        @endforeach
                    </div>
                    <x-dl class="mt-5" :cols="3" :items="['Income' => \App\Support\Money::format($assessment->monthly_income), 'Other income' => \App\Support\Money::format($assessment->other_income),
                        'Household expenses' => \App\Support\Money::format($assessment->household_expenses), 'Obligations (max of declared/bureau)' => \App\Support\Money::format($assessment->existing_obligations),
                        'Proposed instalment (monthly)' => \App\Support\Money::format($assessment->proposed_instalment), 'Bureau score' => $assessment->bureau_score, 'Field visit' => $assessment->visit_result,
                        'Guarantor' => $assessment->guarantor_result, 'Assessed by' => $assessment->assessor?->name.' · '.$assessment->created_at->format('d M Y H:i')]"/>
                    @if($assessment->business_cashflow)<p class="mt-3 text-sm">Business cash flow: inflows {{ \App\Support\Money::format($assessment->business_cashflow['monthly_inflows']) }}, outflows {{ \App\Support\Money::format($assessment->business_cashflow['monthly_outflows']) }}, net {{ \App\Support\Money::format($assessment->business_cashflow['net']) }}</p>@endif
                    @if($assessment->notes)<p class="mt-3 rounded bg-neutral-50 p-3 text-sm">{{ $assessment->notes }}</p>@endif
                @else
                    <x-empty title="Not assessed yet" description="Request the bureau report and complete field visits, then run the assessment."/>
                @endif
            </x-card>
            @can('applications.assess')@if(in_array($s, [$S::Submitted, $S::UnderReview]))
            <x-card title="Run assessment" subtitle="DBR = (obligations + proposed instalment) ÷ income.">
                @if($assessmentStale)<x-alert class="mb-3">The latest assessment is stale – inputs changed since it ran. Re-run it.</x-alert>@endif
                <form wire:submit="runAssessment" class="space-y-3">
                    <x-input label="Verified monthly income (PKR)" type="number" step="0.01" wire:model="assess.monthly_income" :hint="'Enter the income verified from salary slips / bank statements. Declared: '.\App\Support\Money::format($declared['monthly_income'])" required/>
                    <x-input label="Other income (PKR)" type="number" step="0.01" wire:model="assess.other_income" :hint="'Declared: '.\App\Support\Money::format($declared['other_income'])"/>
                    <x-input label="Household expenses (PKR)" type="number" step="0.01" wire:model="assess.household_expenses"/>
                    <x-input label="Existing monthly obligations (PKR)" type="number" step="0.01" wire:model="assess.existing_obligations" :hint="'Declared: '.\App\Support\Money::format($declared['existing_obligations']).' (the higher of this and the bureau figure is used)'"/>
                    @if(in_array($a->customer->employment_type, ['business', 'self_employed']))
                        <x-input label="Business monthly inflows" type="number" step="0.01" wire:model="assess.cash_inflows"/><x-input label="Business monthly outflows" type="number" step="0.01" wire:model="assess.cash_outflows"/>
                    @endif
                    <x-textarea label="Analyst notes" wire:model="assess.notes"/>
                    <button class="btn-primary w-full" wire:loading.attr="disabled">Calculate & save</button>
                </form>
            </x-card>
            @endif @endcan
        </div>
    @elseif($tab === 'objections')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-3 lg:col-span-2">
                @forelse($a->objections as $o)
                    <div class="card p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500"><span><strong class="text-ink">{{ $o->raiser?->name }}</strong> · {{ $o->stage }} · <x-badge>{{ $o->category }}</x-badge> @if($o->visible_to_customer)<x-badge tone="blue">visible to customer</x-badge>@endif</span>
                            <span><x-status :value="$o->status"/> {{ $o->created_at->format('d M Y H:i') }}</span></div>
                        <p class="mt-2 text-sm">{{ $o->body }}</p>
                        @if($o->response)<p class="mt-2 rounded bg-brand-50 p-2 text-sm"><strong>Response:</strong> {{ $o->response }}</p>@endif
                        @if($o->status !== 'resolved')@canany(['applications.verify', 'applications.create'])<button wire:click="openResolve({{ $o->id }})" class="btn-secondary btn-sm mt-3">Resolve</button>@endcanany @endif
                    </div>
                @empty <x-empty title="No objections raised"/> @endforelse
            </div>
            @can('applications.verify')
            <x-card title="Raise objection">
                <form wire:submit="raiseObjection" class="space-y-3">
                    <x-select label="Category" wire:model="objectionCategory" :options="['documents' => 'Documents', 'income' => 'Income', 'identity' => 'Identity', 'visit' => 'Field visit', 'policy' => 'Policy', 'other' => 'Other']"/>
                    <x-textarea label="Objection" wire:model="objection" required/>
                    <x-checkbox wire:model="objectionVisible" label="Show to customer (they will be notified)"/>
                    <button class="btn-primary w-full">Raise objection</button>
                </form>
            </x-card>
            @endcan
        </div>
    @elseif($tab === 'approvals')
        @if($req)
            <x-card :title="$req->workflow?->name" :padding="false">
                <x-table>
                    <x-slot:head><x-th>When</x-th><x-th>Stage</x-th><x-th>By</x-th><x-th>Action</x-th><x-th>Comment</x-th></x-slot:head>
                    @foreach($req->actions as $act)
                        <tr><td class="whitespace-nowrap text-xs">{{ $act->created_at->format('d M Y H:i') }}</td><td>{{ $act->stage_name }}</td><td>{{ $act->actor?->name }}</td>
                            <td><x-badge :tone="['approve' => 'green', 'reject' => 'red', 'return' => 'amber', 'delegate' => 'blue', 'submit' => 'gray'][$act->action] ?? 'gray'">{{ ucfirst($act->action) }}</x-badge></td><td class="text-sm">{{ $act->comment }}</td></tr>
                    @endforeach
                </x-table>
            </x-card>
        @else <x-empty title="Not yet in the approval hierarchy"/> @endif
    @elseif($tab === 'agreement')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Key Fact Statement">
                @if($murabaha)
                    <div class="mb-5 rounded-lg border {{ $murabaha['confirmed'] ? 'border-brand-200 bg-brand-50' : 'border-amber-300 bg-amber-50' }} p-4">
                        <p class="text-sm font-semibold text-ink">Murabaha asset ownership {!! $murabaha['confirmed'] ? '<span class="text-brand-700">✓ confirmed</span>' : '<span class="text-amber-700">pending</span>' !!}</p>
                        <p class="mt-1 text-xs text-neutral-600">The institution must buy the asset (vendor invoice in the institution's name, verified by a second user) before the customer can accept the Murabaha offer and sign.</p>
                        @if($murabaha['invoice'])
                            <p class="mt-2 text-sm">{{ $murabaha['invoice']->title }} · uploaded by {{ $murabaha['invoice']->uploader?->name }} · <x-status :value="$murabaha['invoice']->status"/>
                                · <a class="link" href="{{ route('documents.show', $murabaha['invoice']) }}" target="_blank">View</a></p>
                            @if(! $murabaha['confirmed'] && $u->hasAnyPermission(['agreements.manage', 'disbursements.initiate']))
                                <button wire:click="confirmAssetOwnership" wire:confirm="Confirm the institution owns this asset?" class="btn-primary btn-sm mt-3">Confirm asset ownership</button>
                            @endif
                        @endif
                        @if(! $murabaha['confirmed'] && $u->hasAnyPermission(['agreements.wet_ink', 'agreements.manage', 'applications.create']))
                            <form wire:submit="recordVendorInvoice" class="mt-3 grid gap-2 sm:grid-cols-3 sm:items-end">
                                <x-input label="Invoice number" wire:model="invoiceRef"/>
                                <div><label class="field-label">Invoice (PDF/JPG)</label><input type="file" wire:model="invoiceFile" class="field-input" accept="image/*,application/pdf">@error('invoiceFile')<p class="field-error">{{ $message }}</p>@enderror</div>
                                <button class="btn-secondary" wire:loading.attr="disabled" wire:target="invoiceFile,recordVendorInvoice">{{ $murabaha['invoice'] ? 'Replace invoice' : 'Record invoice' }}</button>
                            </form>
                        @endif
                    </div>
                @endif
                @if($a->kfs)
                    @php($t = $a->kfs->terms)
                    <x-dl :cols="2" :items="['Version' => 'v'.$a->kfs->version, 'Generated' => $a->kfs->generated_at->format('d M Y H:i'), 'Financed amount' => \App\Support\Money::format($t['financed_amount']),
                        'Profit rate / APR' => $t['profit_rate'].'% / '.$t['apr'].'%', 'Instalment' => \App\Support\Money::format($t['instalment']), 'Total payable' => \App\Support\Money::format($t['total_payable']),
                        ($t['payout_label'] ?? 'Net disbursed') => \App\Support\Money::format($t['net_disbursed']), 'Acknowledged' => $a->kfs->acknowledged_at ? $a->kfs->acknowledged_at->format('d M Y H:i').' ('.strtoupper($a->kfs->acknowledged_locale).')' : 'Pending customer']"/>
                    <p class="mt-3 text-xs text-neutral-500">{{ $t['late_payment'] }}</p>
                @else <p class="text-sm text-neutral-500">Generated automatically when the final approval stage approves.</p> @endif
                @if($a->cooling_off_ends_at)<x-alert class="mt-4" :tone="$a->isCoolingOffElapsed() ? 'green' : 'amber'">Cooling-off {{ $a->isCoolingOffElapsed() ? 'ended' : 'ends' }} {{ $a->cooling_off_ends_at->format('d M Y H:i') }}{{ $a->cooling_off_waived ? ' (waived)' : '' }}</x-alert>@endif
            </x-card>
            <x-card title="Signatures" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Document</x-th><x-th>Signer</x-th><x-th>Method</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                    @forelse($a->signatureRequests as $r)
                        <tr><td>{{ $r->documentType->name }}</td><td>{{ $r->signer_name }}<div class="text-xs text-neutral-500">{{ $r->signer_type }}</div></td><td>{{ $r->signing_method === 'digital' ? 'e-Sign (NIFT, simulated)' : 'Wet ink' }}</td>
                            <td><x-status :value="$r->status"/>@if($r->signed_at)<div class="text-xs text-neutral-500">{{ $r->signed_at->format('d M H:i') }}</div>@endif</td>
                            <td class="text-right">@if($r->document_id)<a href="{{ route('documents.show', $r->document_id) }}" target="_blank" class="link text-xs">View</a>@endif
                                @if($r->status === 'wet_ink_pending')@canany(['agreements.wet_ink', 'agreements.manage'])<button wire:click="openWetInk({{ $r->id }})" class="btn-secondary btn-sm">Confirm received</button>@endcanany @endif
                                @if($r->signer_type === 'guarantor' && $r->signing_method === 'digital' && in_array($r->status, ['sent', 'pending']) && $s === $S::KfsAcknowledged)@canany(['agreements.wet_ink', 'agreements.manage'])<button wire:click="sendSigningLink({{ $r->id }})" class="btn-secondary btn-sm whitespace-nowrap">Send signing link</button>@endcanany @endif</td></tr>
                    @empty <x-empty colspan="5" title="No signature requests yet" description="Created when the customer acknowledges the KFS."/> @endforelse
                </x-table>
                @if($signingLink)<div class="border-t border-neutral-100 p-4"><x-alert title="Simulated SMS – guarantor signing link (valid 48 hours)"><input readonly value="{{ $signingLink }}" class="field-input mt-1 text-xs" onclick="this.select()"></x-alert></div>@endif
            </x-card>
        </div>
    @elseif($tab === 'timeline')
        <x-card>
            <ol class="relative border-l-2 border-neutral-200 pl-6">
                @foreach($a->histories->sortByDesc('id') as $h)
                    <li class="mb-5"><span class="absolute -left-[7px] mt-1.5 h-3 w-3 rounded-full bg-brand-600"></span>
                        <div class="flex flex-wrap items-center gap-2 text-sm"><x-status :value="$h->to_status"/><span class="text-xs text-neutral-500">{{ $h->created_at->format('d M Y H:i') }} · {{ $h->actor?->name ?? 'System' }}</span>
                            @unless($h->visible_to_customer)<x-badge>internal</x-badge>@endunless</div>
                        @if($h->comment)<p class="mt-1 text-sm text-neutral-700">{{ $h->comment }}</p>@endif</li>
                @endforeach
            </ol>
        </x-card>
    @endif

    <x-modal show="showReturn" title="Return for correction">
        <x-textarea label="What must be corrected?" wire:model="reason" required/><x-checkbox class="mt-3" wire:model="visibleToCustomer" label="Notify the customer and show this message in their app"/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="returnCase" class="btn-primary">Return</button></x-slot:footer>
    </x-modal>
    <x-modal show="showReject" title="Reject application">
        <x-select label="Reason code" wire:model="rejectCode" :options="\App\Services\Lending\ApplicationService::REJECTION_CODES"/>
        <x-textarea class="mt-3" label="Notes (recorded for regulatory reporting)" wire:model="reason" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="reject" class="btn-danger">Reject</button></x-slot:footer>
    </x-modal>
    <x-modal show="showWithdraw" title="Withdraw application">
        <x-textarea label="Reason given by the customer" wire:model="reason" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="withdraw" class="btn-danger">Withdraw</button></x-slot:footer>
    </x-modal>
    <x-modal show="showGuarantor" title="Add guarantor" maxWidth="xl">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Name" wire:model="guarantor.name" required/><x-input label="CNIC" wire:model="guarantor.cnic" placeholder="35202-1234567-1" required/>
            <x-input label="Mobile" wire:model="guarantor.mobile" required/><x-input label="Relation" wire:model="guarantor.relation"/>
            <x-input label="Occupation" wire:model="guarantor.occupation"/><x-input label="Monthly income" type="number" wire:model="guarantor.monthly_income"/>
            <x-input class="sm:col-span-2" label="Address" wire:model="guarantor.address"/>
            <x-checkbox class="sm:col-span-2" wire:model="guarantor.consent" label="Guarantor consents to identity verification and a credit bureau check (captured in person)"/>
            @error('guarantor.consent')<p class="field-error sm:col-span-2">Guarantor consent is required.</p>@enderror
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="addGuarantor" class="btn-primary">Add</button></x-slot:footer>
    </x-modal>
    <x-modal show="showVisit" title="Schedule field visit">
        <div class="space-y-3">
            <x-select label="Visit type" wire:model="visit.type" :options="['residence' => 'Residence verification', 'business' => 'Business verification', 'collateral' => 'Collateral / asset inspection']"/>
            <x-select label="Visit officer" wire:model="visit.officer" :options="$officers" placeholder="Select officer" required/>
            <x-input label="Date" type="date" wire:model="visit.date" required/><x-input label="Address (defaults to customer address)" wire:model="visit.address"/>
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="scheduleVisit" class="btn-primary">Schedule</button></x-slot:footer>
    </x-modal>
    <x-modal show="showResolve" title="Resolve objection">
        <x-textarea label="Resolution" wire:model="resolution" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="resolveObjection" class="btn-primary">Resolve</button></x-slot:footer>
    </x-modal>
    <x-modal show="showWetInk" title="Confirm wet-ink document received">
        <x-textarea label="Receipt note (stamp paper no., attestation details)" wire:model="comment" required/>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="confirmWetInk" class="btn-primary">Confirm</button></x-slot:footer>
    </x-modal>
</div>
