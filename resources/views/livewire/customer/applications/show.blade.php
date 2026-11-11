<div class="space-y-5" wire:poll.30s>
    <x-page-header :title="$app->product->localizedName()" :subtitle="$app->application_no.' · '.__('submitted').' '.(($app->first_submitted_at ?? $app->submitted_at)?->format('d M Y') ?? '—')" :back="route('app.applications')">
        <x-slot:actions><x-status :value="$app->status"/></x-slot:actions>
    </x-page-header>
    <div class="card p-4">@include('livewire.customer.applications._stepper')</div>

    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror

    <x-card>
        <x-dl :cols="3" :items="[
            __('Amount') => \App\Support\Money::format($app->approved_amount ?? $app->requested_amount),
            __('Tenure') => $app->tenure.' '.($app->productVersion->isWeekly() ? __('weeks') : __('months')),
            __('Indicative instalment') => \App\Support\Money::format($app->indicative_instalment),
            __('Vendor') => $app->vendor?->business_name,
            __('Asset') => $app->asset_description,
            __('Purpose') => $app->purpose,
        ]"/>
    </x-card>

    {{-- Returned: objections to respond to --}}
    @if($objections->isNotEmpty())
        <x-card :title="__('Requests from our team')">
            @foreach($objections as $o)
                <div class="mb-4 rounded-lg border p-3 last:mb-0 {{ $o->status === 'open' ? 'border-amber-300 bg-amber-50' : 'border-neutral-200' }}">
                    <div class="flex justify-between text-xs text-neutral-500"><span>{{ $o->created_at->format('d M Y H:i') }}</span><x-status :value="$o->status"/></div>
                    <p class="mt-1 text-sm">{{ $o->body }}</p>
                    @if($o->response)<p class="mt-2 text-sm text-neutral-700"><span class="font-medium">{{ __('Your response') }}:</span> {{ $o->response }}</p>@endif
                    @if($o->status === 'open')
                        <div class="mt-3 space-y-2">
                            <x-textarea wire:model="responses.{{ $o->id }}" rows="2" :placeholder="__('Write your response')"/>
                            @error('responses.'.$o->id)<p class="field-error">{{ $message }}</p>@enderror
                            <input type="file" wire:model="objectionFile" accept="image/*,application/pdf" class="text-xs">
                            <button wire:click="respond({{ $o->id }})" class="btn-secondary btn-sm">{{ __('Send response') }}</button>
                        </div>
                    @endif
                </div>
            @endforeach
            @if($app->status->value === 'returned')
                @php($openRequests = $objections->where('status', 'open')->count())
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <a href="{{ route('app.apply', $app->product_id) }}?application={{ $app->id }}" wire:navigate class="btn-secondary">{{ __('Edit application') }}</a>
                    <button wire:click="resubmit" class="btn-primary" wire:loading.attr="disabled" @disabled($openRequests)>{{ __('Resubmit application') }}</button>
                    @if($openRequests)<span class="text-xs text-amber-700">{{ __('Respond to every open request first.') }}</span>@endif
                </div>
            @endif
        </x-card>
    @elseif($app->status->value === 'returned')
        <x-card><a href="{{ route('app.apply', $app->product_id) }}?application={{ $app->id }}" wire:navigate class="btn-secondary">{{ __('Edit application') }}</a>
            <button wire:click="resubmit" class="btn-primary">{{ __('Resubmit application') }}</button></x-card>
    @endif

    {{-- KFS --}}
    @if($kfs && in_array($app->status->value, ['approved', 'kfs_acknowledged', 'cooling_off', 'ready_for_disbursement', 'disbursement_in_progress', 'disbursed']))
        <x-card :title="__('Key Fact Statement')" :subtitle="$kfs->acknowledged_at ? __('Acknowledged :d', ['d' => $kfs->acknowledged_at->format('d M Y H:i')]) : __('Please read carefully before signing')">
            <x-slot:actions>
                <div class="flex rounded-lg border border-neutral-200 p-0.5 text-xs">
                    <button wire:click="$set('kfsLocale', 'en')" @class(['rounded-md px-2 py-1', 'bg-ink text-white' => $kfsLocale === 'en'])>English</button>
                    <button wire:click="$set('kfsLocale', 'ur')" @class(['rounded-md px-2 py-1 font-urdu', 'bg-ink text-white' => $kfsLocale === 'ur'])>اردو</button>
                </div>
            </x-slot:actions>
            @include('livewire.customer.applications._kfs', ['t' => $kfs->terms, 'loc' => $kfsLocale])
            @if($app->status->value === 'approved' && ! app(\App\Services\Lending\MurabahaService::class)->assetOwnershipConfirmed($app))
                <x-alert class="mt-4" tone="blue" :title="__('We are buying your asset')">{{ __('Under Murabaha, :inst first buys the item from the vendor in its own name. You can accept the offer and sign as soon as the purchase is confirmed — we will notify you.', ['inst' => $currentTenant->name ?? '']) }}</x-alert>
            @elseif($app->status->value === 'approved')
                <div class="mt-4 space-y-3 border-t border-neutral-100 pt-4">
                    <x-checkbox wire:model="kfsConfirm" :label="__('I have read and understood the Key Fact Statement, including the profit rate, APR, all fees and the total amount payable.')"/>
                    @error('kfsConfirm')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="flex flex-wrap gap-2"><button wire:click="acknowledgeKfs" class="btn-primary">{{ __('Acknowledge & continue to signing') }}</button>
                        <button x-data x-on:click="$dispatch('open-cancel')" class="btn-ghost text-red-600">{{ __('Decline / cancel') }}</button></div>
                </div>
            @endif
        </x-card>
    @endif

    {{-- Signatures --}}
    @if($signatures->isNotEmpty())
        <x-card :title="__('Financing documents')">
            <ul class="divide-y divide-neutral-100 text-sm">
                @foreach($signatures as $s)
                    <li class="flex items-center justify-between gap-2 py-2.5">
                        <div><p class="font-medium">{{ $s->documentType->name }}</p>
                            <p class="text-xs text-neutral-500">{{ $s->signer_type === 'applicant' ? __('You') : __('Guarantor').': '.$s->signer_name }} ·
                                {{ $s->signing_method === 'digital' ? __('e-signature (simulated NIFT eSign)') : __('wet-ink signature at branch (stamp paper / attestation required)') }}</p></div>
                        <div class="flex items-center gap-2">
                            <x-status :value="$s->status"/>
                            @if($s->document_id)<a href="{{ route('documents.show', $s->document_id) }}" target="_blank" class="text-xs link">{{ __('View signed') }}</a>
                            @elseif($s->signer_type === 'applicant' && $s->signing_method === 'digital' && in_array($s->status, ['sent', 'pending']))
                                @if($s->viewed_at)<span class="text-xs text-brand-700">✓ {{ __('Read') }} <bdi>{{ $s->viewed_at->format('H:i') }}</bdi></span>@endif
                                <a href="{{ route('app.applications.document', [$app, $s]) }}" target="_blank" class="btn-secondary btn-sm">{{ $s->viewed_at ? __('Read again') : __('Read') }}</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @php($mine = $signatures->where('signer_type', 'applicant')->where('signing_method', 'digital')->whereIn('status', ['sent', 'pending']))
            @if($app->status->value === 'kfs_acknowledged' && $mine->isNotEmpty())
                <div class="mt-4 rounded-lg bg-neutral-50 p-4">
                    <p class="text-sm font-medium">{{ __('Sign :n document(s) with a one-time code', ['n' => $mine->count()]) }}</p>
                    @if($unread->isNotEmpty())
                        {{-- QA C4: each document must be opened before signing; the page refreshes when you come back. --}}
                        <div wire:poll.5s class="mt-2 text-sm text-amber-800">{{ __('Open and read each document before signing:') }}
                            <ul class="mt-1 list-disc ps-5">@foreach($unread as $u)<li>{{ __($u->documentType->name) }}</li>@endforeach</ul></div>
                    @endif
                    @if(! $otpSent)
                        @error('otp')<p class="field-error">{{ $message }}</p>@enderror
                        <button wire:click="sendSignOtp" class="btn-primary mt-2" @disabled($unread->isNotEmpty())>{{ __('Send signing code') }}</button>
                    @else
                        @if($demoOtp)<x-alert class="mt-2" :title="__('Simulated SMS')">{{ __('Demo mode – your OTP is') }} <strong class="tabular tracking-widest">{{ $demoOtp }}</strong></x-alert>@endif
                        <div class="mt-2 flex flex-wrap items-end gap-2">
                            <x-input :label="__('Signing code')" wire:model="otp" inputmode="numeric" maxlength="6"/>
                            <button wire:click="signAll" class="btn-primary" wire:loading.attr="disabled">{{ __('Sign electronically') }}</button>
                        </div>
                    @endif
                    <p class="mt-2 text-xs text-neutral-500">{{ __('Your signature, time, IP address and device are recorded as the audit trail.') }}</p>
                </div>
            @endif
            @if($signatures->where('status', 'wet_ink_pending')->isNotEmpty())
                <x-alert class="mt-3">{{ __('Some documents (DPN / affidavit) need a wet-ink signature. Please visit your branch; our staff will confirm receipt.') }}</x-alert>
            @endif
            @if($signatures->where('signer_type', 'guarantor')->where('signing_method', 'digital')->whereIn('status', ['sent', 'pending'])->isNotEmpty())
                <x-alert tone="blue" class="mt-3">{{ __('Your guarantor will receive a signing request from our team.') }}</x-alert>
            @endif
        </x-card>
    @endif

    {{-- Cooling-off --}}
    @if($app->status->value === 'cooling_off')
        <x-card :title="__('Cooling-off period')">
            <div x-data="{ end: {{ $app->cooling_off_ends_at->getTimestamp() * 1000 }}, left: '' }" x-init="setInterval(() => { let s = Math.max(0, Math.floor((end - Date.now())/1000)); left = Math.floor(s/3600)+'h '+Math.floor(s%3600/60)+'m '+(s%60)+'s' }, 1000)">
                <p class="text-sm">{{ __('You can cancel this financing free of charge until') }} <strong>{{ $app->cooling_off_ends_at->format('d M Y H:i') }}</strong>.</p>
                <p class="mt-1 text-2xl font-bold tabular text-brand-700" x-text="left"></p>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button x-data x-on:click="$dispatch('open-cancel')" class="btn-danger btn-sm">{{ __('Cancel financing') }}</button>
                @if($app->productVersion->cooling_off_waivable)<button wire:click="waive" wire:confirm="{{ __('Waive your cooling-off right and proceed to disbursement now?') }}" class="btn-secondary btn-sm">{{ __('Waive & proceed') }}</button>@endif
            </div>
        </x-card>
    @endif

    <div x-data="{ open: false }" x-on:open-cancel.window="open = true" x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 sm:items-center">
        <div class="w-full max-w-md rounded-t-2xl bg-white p-5 sm:rounded-2xl" x-on:click.outside="open=false">
            <h3 class="font-semibold">{{ __('Cancel financing') }}</h3>
            <p class="mt-1 text-sm text-neutral-600">{{ __('No profit or charge will be applied.') }}</p>
            <x-textarea class="mt-3" :label="__('Reason')" wire:model="cancelReason" rows="2"/>
            <div class="mt-3 flex justify-end gap-2"><button x-on:click="open=false" class="btn-secondary">{{ __('Keep') }}</button><button wire:click="cancel" class="btn-danger">{{ __('Confirm cancellation') }}</button></div>
        </div>
    </div>

    {{-- Disbursement --}}
    @if(in_array($app->status->value, ['ready_for_disbursement', 'disbursement_in_progress', 'disbursed']))
        <x-card :title="__('Disbursement')">
            @if($app->status->value === 'disbursed' && $app->loanAccount)
                <x-alert tone="green">{{ __('Funds were disbursed on :d. Your financing account is :no.', ['d' => $app->disbursed_at?->format('d M Y'), 'no' => $app->loanAccount->account_no]) }}</x-alert>
                <a href="{{ route('app.financing.show', $app->loanAccount) }}" wire:navigate class="btn-primary mt-3">{{ __('View repayment schedule') }}</a>
            @elseif($disbursement)
                <p class="text-sm">{{ __('Payment :ref to :to', ['ref' => $disbursement->reference, 'to' => $disbursement->recipient_name]) }}: <x-status :value="$disbursement->status"/></p>
            @else
                <p class="text-sm text-neutral-600">{{ __('Your case is in the disbursement queue.') }}</p>
            @endif
        </x-card>
    @endif

    {{-- Withdraw / reapply --}}
    @if(in_array($app->status->value, ['submitted', 'under_review', 'pending_approval', 'returned']))
        <x-card :title="__('Withdraw application')">
            <div class="flex flex-wrap items-end gap-2"><x-input class="flex-1" :label="__('Reason')" wire:model="withdrawReason"/>
                <button wire:click="withdraw" wire:confirm="{{ __('Withdraw this application?') }}" class="btn-secondary text-red-600">{{ __('Withdraw') }}</button></div>
        </x-card>
    @elseif(in_array($app->status->value, ['rejected', 'withdrawn', 'cancelled']))
        <x-card>
            @if($app->status->value === 'rejected')<p class="mb-3 text-sm"><span class="font-medium">{{ __('Reason') }}:</span> {{ \App\Services\Lending\ApplicationService::REJECTION_CODES[$app->rejection_code] ?? __('Outside credit policy') }}</p>@endif
            <button wire:click="reapply" class="btn-secondary">{{ __('Apply again') }}</button>
            <p class="mt-2 text-xs text-neutral-500">{{ __('Reapplication after a decline is possible after the waiting period set by the institution.') }}</p>
        </x-card>
    @endif

    <x-card :title="__('Timeline')">
        <ol class="relative ms-2 border-s border-neutral-200">
            @foreach($history as $h)
                <li class="mb-4 ms-4 last:mb-0">
                    <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-white bg-brand-600"></span>
                    <p class="text-sm font-medium">{{ __($h->to_status->label()) }}</p>
                    @if($h->comment)<p class="text-xs text-neutral-600">{{ $h->comment }}</p>@endif
                    <p class="text-[11px] text-neutral-400">{{ $h->created_at->format('d M Y H:i') }}</p>
                </li>
            @endforeach
        </ol>
    </x-card>

    @if($documents->isNotEmpty())
        <x-card :title="__('Documents')">
            <ul class="space-y-1 text-sm">@foreach($documents as $d)<li class="flex justify-between"><span>{{ $d->title }}</span><a href="{{ route('documents.show', $d) }}" target="_blank" class="link text-xs">{{ __('Open') }}</a></li>@endforeach</ul>
        </x-card>
    @endif
</div>
