<div>
    <x-page-header :title="$loan->account_no" :subtitle="$loan->customer->full_name.' · '.$loan->product->name.' · '.$loan->shariah_structure->label()" :back="route('ops.loans.index')">
        <x-slot:actions>
            <x-status :value="$loan->status"/>
            @if($loan->dpd > 0)<x-badge tone="red">{{ $loan->dpd }} DPD · {{ strtoupper($loan->classification) }}</x-badge>@endif
            @if($loan->isOpen())@can('repayments.record')<button wire:click="openPay" class="btn-primary">{{ __('Record payment') }}</button>@endcan @endif
            @if($loan->status->value === 'closed')@can('loans.issue_noc')<button wire:click="$set('showNoc', true)" class="btn-dark">{{ __('Issue NOC') }}</button>@endcan @endif
            @if(Route::has('ops.recovery.show') && $loan->dpd > 0)<a href="{{ route('ops.recovery.show', $loan) }}" wire:navigate class="btn-secondary">{{ __('Recovery case') }}</a>@endif
        </x-slot:actions>
    </x-page-header>

    @if($writeOff)<x-alert tone="{{ $writeOff->status === 'approved' ? 'red' : 'amber' }}" class="mb-4">{{ __('Write-off :r – :s (:a).', ['r' => $writeOff->reference, 's' => \Illuminate\Support\Str::headline($writeOff->status), 'a' => \App\Support\Money::format($writeOff->amount)]) }}</x-alert>@endif
    @if($loan->noc_issued_at)<x-alert tone="green" class="mb-4">{{ __('NOC :n issued on :d.', ['n' => $loan->noc_no, 'd' => $loan->noc_issued_at->format('d M Y')]) }}</x-alert>@endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="__('Total outstanding')" :value="\App\Support\Money::format($loan->totalOutstanding())" tone="black"/>
        <x-stat :label="__('Overdue')" :value="\App\Support\Money::format($overdue)" :tone="\App\Support\Money::isPositive($overdue) ? 'red' : 'green'"/>
        <x-stat :label="__('Total paid')" :value="\App\Support\Money::format($loan->total_paid)"/>
        <x-stat :label="__('Next instalment')" :value="$next ? \App\Support\Money::format(\App\Support\Money::sub(\App\Support\Money::add($next->total_due, $next->late_charge_due), $next->total_paid)) : '—'" :hint="$next ? '#'.$next->number.' · '.$next->due_date->format('d M Y') : null" tone="amber"/>
    </div>

    <x-tabs :tabs="['summary' => __('Summary'), 'schedule' => __('Schedule'), 'payments' => __('Payments'), 'recovery' => __('Recovery contacts'), 'documents' => __('Documents')] + (auth()->user()->hasAnyPermission(['audit.view', 'loans.manage']) ? ['audit' => __('Audit trail')] : [])" :active="$tab"/>

    @if($tab === 'summary')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :title="__('Terms (frozen at disbursement)')" class="lg:col-span-2">
                <x-dl :cols="3" :items="[
                    __('Financed amount') => \App\Support\Money::format($loan->principal), __('Profit total') => \App\Support\Money::format($loan->profit_total),
                    __('Charges in instalments') => \App\Support\Money::format($loan->charges_total), __('Total receivable') => \App\Support\Money::format($loan->total_receivable),
                    __('Profit rate') => $loan->profit_rate.'% p.a. ('.($loan->terms_snapshot['profit_method'] ?? '').')', __('APR') => ($loan->terms_snapshot['apr'] ?? '—').'%',
                    __('Tenure') => $loan->tenure.' '.($loan->frequency === 'weekly' ? __('weeks') : __('months')), __('Product version') => 'v'.($loan->terms_snapshot['product_version'] ?? $loan->productVersion->version),
                    __('Late payment') => \App\Support\Money::format($loan->terms_snapshot['late_charge_per_day'] ?? 0).'/day after '.($loan->terms_snapshot['grace_days'] ?? 0).' days → '.($loan->terms_snapshot['late_payment_treatment'] ?? ''),
                    __('Disbursed') => $loan->disbursed_at->format('d M Y'), __('First due') => $loan->first_due_date->format('d M Y'), __('Maturity') => $loan->maturity_date->format('d M Y'),
                    __('Allocation order') => implode(' → ', $loan->terms_snapshot['allocation_order'] ?? []), __('Partial payments') => ($loan->terms_snapshot['allow_partial_payment'] ?? true) ? __('Allowed') : __('Not allowed'),
                    __('Application') => $loan->application->application_no,
                ]"/>
            </x-card>
            <div class="space-y-4">
                <x-card :title="__('Outstanding breakdown')">
                    <x-dl :cols="1" :items="[
                        __('Principal') => \App\Support\Money::format($loan->principal_outstanding), __('Profit') => \App\Support\Money::format($loan->profit_outstanding),
                        __('Charges') => \App\Support\Money::format($loan->charges_outstanding), __('Late payment (charity)') => \App\Support\Money::format($loan->late_charges_outstanding),
                    ]"/>
                </x-card>
                <x-card :title="__('Parties')">
                    <x-dl :cols="1" :items="[
                        __('Customer') => $loan->customer->full_name.' ('.$loan->customer->cif_no.')',
                        __('Mobile') => $sensitive ? $loan->customer->mobile : substr($loan->customer->mobile, 0, 4).'*****'.substr($loan->customer->mobile, -2),
                        __('Branch') => $loan->branch?->name, __('Loan officer') => $loan->officer?->name, __('Recovery agent') => $loan->recoveryAgent?->name,
                        __('Vendor') => $loan->vendor?->business_name, __('Funding source') => $loan->fund?->name,
                    ]"/>
                </x-card>
            </div>
        </div>
    @elseif($tab === 'schedule')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>#</x-th><x-th>{{ __('Due date') }}</x-th><x-th align="right">{{ __('Principal') }}</x-th><x-th align="right">{{ __('Profit') }}</x-th><x-th align="right">{{ __('Charges') }}</x-th><x-th align="right">{{ __('Late (charity)') }}</x-th><x-th align="right">{{ __('Total due') }}</x-th><x-th align="right">{{ __('Paid') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                @foreach($instalments as $i)
                    <tr>
                        <td>{{ $i->number }}</td><td>{{ $i->due_date->format('d M Y') }}</td>
                        <td class="text-right"><x-money :amount="$i->principal_due" :currency="false"/></td><td class="text-right"><x-money :amount="$i->profit_due" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$i->charges_due" :currency="false"/></td><td class="text-right"><x-money :amount="$i->late_charge_due" :currency="false"/></td>
                        <td class="text-right font-semibold"><x-money :amount="\App\Support\Money::add($i->total_due, $i->late_charge_due)" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$i->total_paid" :currency="false"/></td>
                        <td><x-status :value="$i->status"/>@if($i->paid_at)<div class="text-[11px] text-neutral-500">{{ $i->paid_at->format('d M Y') }}</div>@endif</td>
                    </tr>
                @endforeach
                <x-slot:foot><tr><td class="px-4 py-2" colspan="2">{{ __('Totals') }}</td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->principal_due)" :currency="false"/></td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->profit_due)" :currency="false"/></td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->charges_due)" :currency="false"/></td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->late_charge_due)" :currency="false"/></td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->total_due + $i->late_charge_due)" :currency="false"/></td>
                    <td class="px-4 py-2 text-right"><x-money :amount="$instalments->sum(fn($i) => $i->total_paid)" :currency="false"/></td><td></td></tr></x-slot:foot>
            </x-table>
        </x-card>
    @elseif($tab === 'payments')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Reference / receipt') }}</x-th><x-th>{{ __('Received') }}</x-th><x-th>{{ __('Channel') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Allocation') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('GL') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($payments as $p)
                    <tr wire:key="p{{ $p->id }}">
                        <td class="font-medium">{{ $p->reference }}<div class="text-xs text-neutral-500">{{ $p->receipt_no }} @if($p->external_ref)· {{ $p->external_ref }}@endif</div></td>
                        <td class="text-xs">{{ $p->received_at?->format('d M Y H:i') ?? $p->created_at->format('d M Y H:i') }}<div class="text-neutral-500">{{ $p->recorder?->name }}</div></td>
                        <td>{{ $p->channel->label() }}</td>
                        <td class="text-right font-semibold"><x-money :amount="$p->amount"/></td>
                        <td class="text-xs">@if($p->allocation)@foreach($p->allocation as $k => $v)@if(\App\Support\Money::isPositive($v)) <span class="mr-2">{{ \Illuminate\Support\Str::headline($k) }}: {{ \App\Support\Money::format($v, false) }}</span>@endif @endforeach
                            @if(\App\Support\Money::isPositive($p->unallocated_amount))<span class="text-amber-700">{{ __('Advance') }}: {{ \App\Support\Money::format($p->unallocated_amount, false) }}</span>@endif
                            <button wire:click="$set('allocationId', {{ $p->id }})" class="link block">{{ __('Detail') }}</button>@endif</td>
                        <td><x-status :value="$p->status"/>@if($p->reversal_reason)<div class="max-w-[12rem] text-xs text-neutral-500">{{ $p->reversal_reason }}</div>@endif @if($p->failure_reason)<div class="text-xs text-red-600">{{ $p->failure_reason }}</div>@endif</td>
                        <td class="text-xs">{{ $vouchers[$p->id]->voucher_no ?? '—' }}</td>
                        <td class="text-right">@if($p->status->value === 'succeeded')@can('repayments.reverse')<button wire:click="openReverse({{ $p->id }})" class="btn-ghost btn-sm text-red-600">{{ __('Reverse') }}</button>@endcan @endif</td>
                    </tr>
                @empty
                    <x-empty :colspan="8" :title="__('No payments yet')"/>
                @endforelse
            </x-table>
        </x-card>
        @if(isset($allocation) && $allocation)
            <x-card class="mt-4" title="{{ __('Allocation of :r', ['r' => $allocation->reference]) }}">
                <x-slot:actions><button wire:click="$set('allocationId', null)" class="btn-ghost btn-sm">✕</button></x-slot:actions>
                <x-table><x-slot:head><x-th>{{ __('Instalment') }}</x-th><x-th>{{ __('Component') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th></x-slot:head>
                    @foreach($allocation->allocations as $al)<tr><td>#{{ $al->instalment->number }} ({{ $al->instalment->due_date->format('d M Y') }})</td><td>{{ \Illuminate\Support\Str::headline($al->component) }}</td><td class="text-right"><x-money :amount="$al->amount"/></td></tr>@endforeach
                </x-table>
            </x-card>
        @endif
    @elseif($tab === 'recovery')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('When') }}</x-th><x-th>{{ __('Agent') }}</x-th><x-th>{{ __('Type / party') }}</x-th><x-th>{{ __('Outcome') }}</x-th><x-th>{{ __('Promise to pay') }}</x-th><x-th>{{ __('Notes') }}</x-th></x-slot:head>
                @forelse($contacts as $c)
                    <tr><td class="text-xs">{{ $c->contacted_at->format('d M Y H:i') }}</td><td>{{ $c->agent->name }}</td>
                        <td>{{ \Illuminate\Support\Str::headline($c->contact_type) }}<div class="text-xs text-neutral-500">{{ $c->contacted_party }} @if($c->call_recording_ref)· {{ $c->call_recording_ref }}@endif</div></td>
                        <td><x-status :value="$c->outcome"/></td>
                        <td>@if($c->ptp_date){{ $c->ptp_date->format('d M Y') }} · <x-money :amount="$c->ptp_amount"/> <x-status :value="$c->ptp_status"/>@else — @endif</td>
                        <td class="max-w-xs text-xs">{{ $c->notes }}</td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No recovery contacts logged')"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'documents')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Document') }}</x-th><x-th>{{ __('Type') }}</x-th><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Uploaded') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($documents as $d)
                    <tr><td class="font-medium">{{ $d->title }}</td><td>{{ $d->type?->name ?? '—' }}</td><td>v{{ $d->version }}</td><td><x-status :value="$d->status"/></td><td class="text-xs">{{ $d->created_at->format('d M Y') }}</td>
                        <td class="text-right"><a href="{{ route('documents.show', $d) }}" target="_blank" class="link">{{ __('Open') }}</a></td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No documents')"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'audit')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('When') }}</x-th><x-th>{{ __('Actor') }}</x-th><x-th>{{ __('Action') }}</x-th><x-th>{{ __('Changes') }}</x-th><x-th>{{ __('Reason') }}</x-th></x-slot:head>
                @forelse($audits as $a)
                    <tr><td class="text-xs whitespace-nowrap">{{ $a->created_at->format('d M Y H:i:s') }}</td><td class="text-xs">{{ $a->actor?->name ?? 'System' }}</td><td class="text-xs font-medium">{{ $a->action }}</td>
                        <td class="max-w-md break-all font-mono text-[11px] text-neutral-600">{{ json_encode($a->new_values) }}</td><td class="text-xs">{{ $a->reason }}</td></tr>
                @empty
                    <x-empty :colspan="5" :title="__('No audit events')"/>
                @endforelse
            </x-table>
        </x-card>
    @endif

    <x-modal show="showPay" :title="__('Record manual repayment')">
        <div class="space-y-3">
            <x-alert tone="blue">{{ __('Use for money received outside the platform (cash at branch, cheque, bank transfer, salary deduction). It is allocated immediately and posted to the GL.') }}</x-alert>
            <x-input :label="__('Amount (PKR)')" type="number" step="0.01" wire:model="payAmount" required/>
            <x-select :label="__('Channel')" wire:model="payChannel" :options="['cash' => 'Cash at branch', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'salary_deduction' => 'Salary deduction']"/>
            <x-input :label="__('Receipt / bank reference')" wire:model="payRef" required/>
            <x-input :label="__('Received on')" type="date" wire:model="payDate" required/>
            @error('action')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary" wire:click="recordPayment" wire:loading.attr="disabled">{{ __('Record payment') }}</button></x-slot:footer>
    </x-modal>

    <x-modal show="showReverse" :title="__('Reverse repayment')">
        <x-alert tone="red" class="mb-3">{{ __('Reversal voids the allocation, restores instalment balances, posts a reversing journal voucher and (for wallet payments) credits the wallet back. You cannot reverse a payment you recorded.') }}</x-alert>
        <x-textarea :label="__('Reason')" wire:model="reverseReason" required/>
        @error('action')<p class="field-error mt-2">{{ $message }}</p>@enderror
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-danger" wire:click="reverse">{{ __('Reverse payment') }}</button></x-slot:footer>
    </x-modal>

    <x-modal show="showNoc" :title="__('Issue No Objection Certificate')">
        <p class="text-sm">{{ __('Confirm that :a is fully repaid. A NOC will be generated, stored with the loan documents and sent to the customer. This cannot be undone.', ['a' => $loan->account_no]) }}</p>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-dark" wire:click="issueNoc">{{ __('Issue NOC') }}</button></x-slot:footer>
    </x-modal>
</div>
