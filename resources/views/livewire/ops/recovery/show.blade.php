<div>
    <x-page-header :title="__('Recovery case').' '.$loanAcc->account_no" :subtitle="$loanAcc->customer->full_name.' · '.$loanAcc->product->name" :back="route('ops.recovery.index')">
        <x-slot:actions>
            <x-badge tone="red">{{ $loanAcc->dpd }} DPD · {{ strtoupper($loanAcc->classification) }}</x-badge>
            <button wire:click="sendNotice" wire:confirm="{{ __('Send an overdue payment notice to the borrower now?') }}" class="btn-secondary">{{ __('Send delay notice') }}</button>
            @can('loans.view')<a href="{{ route('ops.loans.show', $loanAcc) }}" wire:navigate class="btn-secondary">{{ __('Loan account') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <x-alert tone="blue" class="mb-4" :title="__('Collections conduct guardrails')">
        {{ __('Permitted contact window :a–:b. Contact only the borrower or guarantor. Third-party contact about overdue status is prohibited. Calls are recorded.', ['a' => $window[0], 'b' => $window[1]]) }}
    </x-alert>

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="__('Overdue amount')" :value="\App\Support\Money::format($overdue)" tone="red"/>
        <x-stat :label="__('Total outstanding')" :value="\App\Support\Money::format($loanAcc->totalOutstanding())" tone="black"/>
        <x-stat :label="__('Late payment (charity)')" :value="\App\Support\Money::format($loanAcc->late_charges_outstanding)" tone="amber"/>
        <x-stat :label="__('Agent')" :value="$loanAcc->recoveryAgent?->name ?? __('Unassigned')" tone="gray"/>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-card :title="__('Contact details')">
                <x-dl :items="[__('Borrower') => $loanAcc->customer->full_name, __('Mobile') => $mask($loanAcc->customer->mobile), __('Address') => $loanAcc->customer->current_address]"/>
                @if($loanAcc->application->guarantors->isNotEmpty())
                    <p class="mt-4 text-xs font-semibold uppercase text-neutral-500">{{ __('Guarantors') }}</p>
                    <ul class="mt-1 text-sm">@foreach($loanAcc->application->guarantors as $g)<li>{{ $g->name }} ({{ $g->relation }}) · {{ $mask($g->mobile) }}</li>@endforeach</ul>
                @endif
            </x-card>
            <x-card :title="__('Unpaid instalments')" :padding="false">
                <x-table>
                    <x-slot:head><x-th>#</x-th><x-th>{{ __('Due') }}</x-th><x-th align="right">{{ __('Due amount') }}</x-th><x-th align="right">{{ __('Paid') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                    @foreach($instalments as $i)
                        <tr><td>{{ $i->number }}</td><td>{{ $i->due_date->format('d M Y') }}</td><td class="text-right"><x-money :amount="\App\Support\Money::add($i->total_due, $i->late_charge_due)" :currency="false"/></td>
                            <td class="text-right"><x-money :amount="$i->total_paid" :currency="false"/></td><td><x-status :value="$i->status"/></td></tr>
                    @endforeach
                </x-table>
            </x-card>
            <x-card :title="__('Contact history')" :padding="false">
                <x-slot:actions>@if(auth()->user()->hasAnyPermission(['recovery.assign', 'recovery.view']))<button wire:click="evaluatePromises" class="btn-ghost btn-sm">{{ __('Evaluate promises') }}</button>@endif</x-slot:actions>
                <ul class="divide-y divide-neutral-100">
                    @forelse($contacts as $c)
                        <li class="px-5 py-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-medium">{{ \Illuminate\Support\Str::headline($c->contact_type) }}</span> → {{ $c->contacted_party }} · <x-status :value="$c->outcome"/>
                                @if($c->ptp_date)<span class="text-xs">PTP {{ $c->ptp_date->format('d M') }} · {{ \App\Support\Money::format($c->ptp_amount) }}</span> <x-status :value="$c->ptp_status"/>@endif
                                <span class="ml-auto text-xs text-neutral-500">{{ $c->agent->name }} · {{ $c->contacted_at->format('d M Y H:i') }}</span></div>
                            @if($c->notes)<p class="mt-1 text-neutral-600">{{ $c->notes }}</p>@endif
                            @if($c->call_recording_ref)<p class="text-[11px] text-neutral-400">{{ __('Recording') }}: {{ $c->call_recording_ref }}</p>@endif
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-neutral-500">{{ __('No contact logged yet.') }}</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
        <div class="space-y-4">
            <x-card :title="__('Log contact')">
                <form wire:submit="logContact" class="space-y-3">
                    <x-select :label="__('Contact type')" wire:model="contact_type" :options="['call' => 'Phone call (recorded)', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'visit' => 'Field visit', 'app_notification' => 'App notification']"/>
                    <x-select :label="__('Contacted party')" wire:model="contacted_party" :options="['borrower' => 'Borrower', 'guarantor' => 'Guarantor']"/>
                    <x-select :label="__('Outcome')" wire:model.live="outcome" :options="['promise_to_pay' => 'Promise to pay', 'dispute' => 'Dispute', 'unreachable' => 'Unreachable', 'paid' => 'Paid', 'settled' => 'Settled', 'escalate_legal' => 'Escalate to legal', 'other' => 'Other']"/>
                    @if($outcome === 'promise_to_pay')
                        <x-input :label="__('Promised date')" type="date" wire:model="ptp_date"/>
                        <x-input :label="__('Promised amount')" type="number" step="0.01" wire:model="ptp_amount"/>
                    @endif
                    <x-textarea :label="__('Notes')" wire:model="notes"/>
                    @error('action')<p class="field-error">{{ $message }}</p>@enderror
                    <button class="btn-primary w-full">{{ __('Save contact') }}</button>
                </form>
            </x-card>
            <x-card :title="__('Recent payments')" :padding="false">
                <ul class="divide-y divide-neutral-100 text-sm">
                    @forelse($payments as $p)<li class="flex justify-between px-4 py-2"><span>{{ $p->received_at?->format('d M Y') }} · {{ $p->channel->label() }}</span><x-money :amount="$p->amount"/></li>
                    @empty<li class="px-4 py-4 text-center text-neutral-500">{{ __('None') }}</li>@endforelse
                </ul>
            </x-card>
        </div>
    </div>
</div>
