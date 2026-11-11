<div wire:poll.30s>
    <x-page-header :title="__('Disbursements')" :subtitle="__('Release approved financing to vendors, customer bank accounts or wallets. Loans are created only after the provider confirms the payout.')"/>

    <x-tabs :tabs="['ready' => __('Ready for disbursement').' ('.$counts['ready'].')', 'progress' => __('In progress').' ('.$counts['progress'].')', 'history' => __('History')]" :active="$tab"/>

    <div class="mb-4 flex flex-wrap gap-3">
        <x-input class="w-64" wire:model.live.debounce.400ms="search" :placeholder="$tab === 'ready' ? __('Application no…') : __('Reference or recipient…')"/>
        @if($tab === 'history')
            <x-select wire:model.live="filterStatus" :placeholder="__('All statuses')" :options="\App\Enums\DisbursementStatus::options()"/>
            <x-input type="date" wire:model.live="filterFrom"/><x-input type="date" wire:model.live="filterTo"/>
        @endif
    </div>

    @if($tab === 'ready')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Application') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Product') }}</x-th><x-th>{{ __('Recipient') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Readiness') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($ready as $a)
                    <tr wire:key="r{{ $a->id }}">
                        <td><a href="{{ Route::has('ops.applications.show') ? route('ops.applications.show', $a) : '#' }}" wire:navigate class="link">{{ $a->application_no }}</a><div class="text-xs text-neutral-500">{{ $a->branch?->name }}</div></td>
                        <td>{{ $a->customer->full_name }}<div class="text-xs text-neutral-500">{{ $a->customer->cif_no }}</div></td>
                        <td>{{ $a->product->name }}<div class="text-xs text-neutral-500">{{ $a->asset_description }}</div></td>
                        <td>{{ \Illuminate\Support\Str::headline($a->productVersion->disbursement_mode) }}<div class="text-xs text-neutral-500">{{ $a->vendor?->business_name }}</div></td>
                        <td class="text-right"><x-money :amount="$a->financedAmount()"/></td>
                        <td class="max-w-xs">
                            @if(empty($a->blockers))<x-badge tone="green">{{ __('Ready') }}</x-badge>
                            @else
                                <x-badge tone="amber">{{ __('Blocked') }}</x-badge>
                                <ul class="mt-1 list-disc pl-4 text-xs text-neutral-600">@foreach($a->blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
                            @endif
                            @if($a->disbursements->where('status.value', 'failed')->isNotEmpty())<p class="mt-1 text-xs text-red-600">{{ __('Previous attempt failed – retry allowed') }}</p>@endif
                        </td>
                        <td class="text-right">@if($canInitiate && empty($a->blockers))<button wire:click="confirm({{ $a->id }})" class="btn-primary btn-sm">{{ __('Request payout') }}</button>@endif</td>
                    </tr>
                @empty
                    <x-empty :colspan="7" :title="__('No cases awaiting disbursement')" :description="__('Approved applications appear here once the KFS is acknowledged, documents are signed and the cooling-off period ends.')"/>
                @endforelse
            </x-table>
            {{ $ready->links() }}
        </x-card>
    @else
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th sort="id" :current="$sort" :dir="$dir">{{ __('Reference') }}</x-th><x-th>{{ __('Application') }}</x-th><x-th>{{ __('Recipient') }}</x-th>
                    <x-th align="right">{{ __('Gross') }}</x-th><x-th align="right">{{ __('Deductions') }}</x-th><x-th align="right" sort="net_amount" :current="$sort" :dir="$dir">{{ __('Net paid') }}</x-th>
                    <x-th>{{ __('Status') }}</x-th><x-th>{{ __('Provider') }}</x-th><x-th>{{ __('Loan') }}</x-th><x-th sort="created_at" :current="$sort" :dir="$dir">{{ __('Date') }}</x-th></x-slot:head>
                @forelse($history as $d)
                    <tr wire:key="d{{ $d->id }}">
                        <td class="font-medium">{{ $d->reference }}</td>
                        <td>{{ $d->application->application_no }}<div class="text-xs text-neutral-500">{{ $d->application->customer->full_name }}</div></td>
                        <td>{{ $d->recipient_name }}<div class="text-xs text-neutral-500">{{ \Illuminate\Support\Str::headline($d->recipient_type) }} · {{ $d->recipient_iban }}</div></td>
                        <td class="text-right"><x-money :amount="$d->gross_amount" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$d->deductions" :currency="false"/></td>
                        <td class="text-right font-semibold"><x-money :amount="$d->net_amount"/></td>
                        <td>@if($d->awaitingRelease())<x-badge tone="amber">{{ __('Awaiting release') }}</x-badge>@else<x-status :value="$d->status"/>@endif
                            @if($d->failure_reason)<div class="mt-1 max-w-[14rem] text-xs text-red-600">{{ $d->failure_reason }}</div>@endif
                            <div class="mt-1 text-xs text-neutral-500">{{ __('Requested') }}: {{ $d->initiator?->name }}@if($d->releaser)<br>{{ __('Released') }}: {{ $d->releaser->name }}@endif</div>
                            @if($d->awaitingRelease() && $canInitiate)
                                @if((int) $d->initiated_by !== (int) auth()->id())<button wire:click="openRelease({{ $d->id }})" class="btn-primary btn-sm mt-1">{{ __('Review & release') }}</button>
                                @else<p class="mt-1 text-xs text-amber-700">{{ __('Another authorised user must release your request.') }}</p>@endif
                            @endif
                        </td>
                        <td class="text-xs">{{ strtoupper($d->provider) }}<div class="text-neutral-500">{{ $d->paymentOrder?->reference }}</div><div class="text-neutral-400">{{ $d->paymentOrder?->provider_ref }}</div>
                            @if($d->paymentOrder && Route::has('ops.payments.show'))<a class="link" href="{{ route('ops.payments.show', $d->paymentOrder) }}" wire:navigate>{{ __('Payment order') }}</a>@endif</td>
                        <td>@if($d->loanAccount)<a class="link" href="{{ route('ops.loans.show', $d->loanAccount) }}" wire:navigate>{{ $d->loanAccount->account_no }}</a>@else<span class="text-neutral-400">—</span>@endif</td>
                        <td class="text-xs whitespace-nowrap">{{ $d->created_at->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <x-empty :colspan="10" :title="__('No disbursements found')"/>
                @endforelse
            </x-table>
            {{ $history->links() }}
        </x-card>
    @endif

    <x-modal show="showConfirm" :title="__('Confirm disbursement')" maxWidth="xl">
        @if($preview)
            @php $a = $preview['app']; $q = $preview['quote']; @endphp
            <x-alert tone="amber" class="mb-4">{{ __('This instructs a payout through the sponsor bank / Raast. In demo mode the provider is SIMULATED and no real money moves.') }}</x-alert>
            <x-dl :items="[
                __('Application') => $a->application_no, __('Customer') => $a->customer->full_name,
                __('Mode') => \Illuminate\Support\Str::headline($a->productVersion->disbursement_mode),
                __('Recipient') => match($a->productVersion->disbursement_mode) { 'vendor' => $a->vendor?->business_name.' · '.$a->vendor?->iban, 'wallet' => __('Customer wallet').' '.$a->customer->wallet?->wallet_no, default => $a->customer->linkedBankAccounts->sortByDesc('is_default')->first()?->iban },
                __('Gross financed') => \App\Support\Money::format($q['principal']), __('Upfront deductions') => \App\Support\Money::format($q['charges_upfront']),
                __('Net payout') => \App\Support\Money::format($q['net_disbursed']), __('Instalment') => \App\Support\Money::format($q['instalment']).' × '.$q['tenure'],
            ]"/>
            <x-alert tone="blue" class="mt-4">{{ __('Maker-checker: this creates a payout request. A different user with disbursement rights must review and release it before any money moves.') }}</x-alert>
            @if($simulation)
                <x-select class="mt-4" :label="__('Simulation scenario (demo tenant only – audited)')" wire:model="scenario" :options="config('integrations.payment_scenarios')"/>
            @endif
            @error('action')<p class="field-error mt-3">{{ $message }}</p>@enderror
        @endif
        <x-slot:footer>
            <button class="btn-secondary" x-on:click="open = false">{{ __('Cancel') }}</button>
            <button class="btn-primary" wire:click="initiate" wire:loading.attr="disabled">{{ __('Submit payout request') }}</button>
        </x-slot:footer>
    </x-modal>

    <x-modal show="showRelease" :title="__('Release payout')" maxWidth="lg">
        @if($releasing)
            <x-alert tone="amber" class="mb-4">{{ __('Releasing sends the payout to the provider (SIMULATED in demo). Check the recipient and amount against the approved case.') }}</x-alert>
            <x-dl :items="[__('Reference') => $releasing->reference, __('Application') => $releasing->application->application_no, __('Customer') => $releasing->application->customer->full_name,
                __('Recipient') => $releasing->recipient_name.' · '.$releasing->recipient_iban, __('Net payout') => \App\Support\Money::format($releasing->net_amount),
                __('Requested by') => $releasing->initiator?->name.' · '.$releasing->created_at->format('d M Y H:i')]"/>
            <x-textarea class="mt-4" :label="__('Reason (only if declining)')" wire:model="declineReason"/>
            @error('action')<p class="field-error mt-3">{{ $message }}</p>@enderror
        @endif
        <x-slot:footer>
            <button class="btn-secondary" x-on:click="open = false">{{ __('Cancel') }}</button>
            <button class="btn-danger" wire:click="decline" wire:loading.attr="disabled">{{ __('Decline request') }}</button>
            <button class="btn-primary" wire:click="release" wire:loading.attr="disabled">{{ __('Release payout') }}</button>
        </x-slot:footer>
    </x-modal>
</div>
