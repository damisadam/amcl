<div class="space-y-5" wire:poll.60s>
    <x-page-header :title="$l->product->localizedName()" :subtitle="$l->account_no.' · '.__($l->shariah_structure->label())" :back="route('app.financing')">
        <x-slot:actions><x-status :value="$l->status"/></x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat :label="__('Outstanding')" :value="\App\Support\Money::format($l->totalOutstanding())" tone="black"/>
        <x-stat :label="__('Paid to date')" :value="\App\Support\Money::format($l->total_paid)"/>
        <x-stat :label="__('Next due')" :value="$next ? \App\Support\Money::format(\App\Support\Money::sub(\App\Support\Money::add($next->total_due, $next->late_charge_due), $next->total_paid)) : '—'" :hint="$next?->due_date->format('d M Y')" tone="amber"/>
        <x-stat :label="__('Overdue')" :value="\App\Support\Money::format($overdue)" :hint="$l->dpd ? __(':d days past due', ['d' => $l->dpd]) : __('Up to date')" :tone="$l->dpd ? 'red' : 'green'"/>
    </div>

    @if($l->isOpen())
        <a href="{{ route('app.pay') }}?loan={{ $l->id }}" wire:navigate class="btn-primary w-full sm:w-auto">{{ __('Pay instalment') }}</a>
    @elseif($l->status->value === 'noc_issued' && $noc)
        <x-alert tone="green" :title="__('Fully repaid')">{{ __('No Objection Certificate :n issued on :d.', ['n' => $l->noc_no, 'd' => $l->noc_issued_at->format('d M Y')]) }}
            <a href="{{ route('documents.show', $noc) }}" target="_blank" class="ml-1 font-semibold underline">{{ __('Download NOC') }}</a></x-alert>
    @elseif($l->status->value === 'closed')
        <x-alert tone="green">{{ __('Fully repaid – your NOC will be issued shortly.') }}</x-alert>
    @endif

    <x-card>
        <x-dl :cols="3" :items="[
            __('Financed amount') => \App\Support\Money::format($l->principal),
            __('Profit') => \App\Support\Money::format($l->profit_total).' ('.rtrim(rtrim((string) $l->profit_rate, '0'), '.').'% p.a.)',
            __('Charges') => \App\Support\Money::format($l->charges_total),
            __('Total receivable') => \App\Support\Money::format($l->total_receivable),
            __('Principal outstanding') => \App\Support\Money::format($l->principal_outstanding),
            __('Profit outstanding') => \App\Support\Money::format($l->profit_outstanding),
            __('Late payment (charity)') => \App\Support\Money::format($l->late_charges_outstanding),
            __('Vendor') => $l->vendor?->business_name,
            __('Maturity') => $l->maturity_date->format('d M Y'),
        ]"/>
    </x-card>

    <x-card :padding="false">
        <div class="px-5 pt-3"><x-tabs :tabs="['schedule' => __('Repayment schedule'), 'payments' => __('Payments')]" :active="$tab"/></div>
        @if($tab === 'schedule')
            {{-- Phones: one card per instalment instead of an 8-column table (QA UX) --}}
            <ul class="divide-y divide-neutral-100 sm:hidden">
                @foreach($instalments as $i)
                    <li @class(['flex items-center justify-between gap-3 px-4 py-3', 'bg-red-50/60' => $i->status->value === 'overdue'])>
                        <div><p class="text-sm font-medium"><bdi>#{{ $i->number }} · {{ $i->due_date->format('d M Y') }}</bdi></p>
                            <p class="text-xs text-neutral-500">{{ __('Paid') }} <bdi><x-money :amount="$i->total_paid" :currency="false"/></bdi></p></div>
                        <div class="text-end"><p class="font-semibold"><bdi><x-money :amount="\App\Support\Money::add($i->total_due, $i->late_charge_due)"/></bdi></p><x-status :value="$i->status"/></div>
                    </li>
                @endforeach
            </ul>
            <x-table class="hidden sm:block">
                <x-slot:head><x-th>#</x-th><x-th>{{ __('Due date') }}</x-th><x-th align="right">{{ __('Principal') }}</x-th><x-th align="right">{{ __('Profit') }}</x-th><x-th align="right">{{ __('Charges') }}</x-th><x-th align="right">{{ __('Total due') }}</x-th><x-th align="right">{{ __('Paid') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                @foreach($instalments as $i)
                    <tr @class(['bg-red-50/60' => $i->status->value === 'overdue'])>
                        <td>{{ $i->number }}</td><td class="whitespace-nowrap">{{ $i->due_date->format('d M Y') }}</td>
                        <td class="text-right"><x-money :amount="$i->principal_due" :currency="false"/></td><td class="text-right"><x-money :amount="$i->profit_due" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="\App\Support\Money::add($i->charges_due, $i->late_charge_due)" :currency="false"/></td>
                        <td class="text-right font-semibold"><x-money :amount="\App\Support\Money::add($i->total_due, $i->late_charge_due)" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$i->total_paid" :currency="false"/></td><td><x-status :value="$i->status"/></td>
                    </tr>
                @endforeach
            </x-table>
        @else
            <x-table>
                <x-slot:head><x-th>{{ __('Date') }}</x-th><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Channel') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($payments as $p)
                    <tr><td class="whitespace-nowrap">{{ ($p->received_at ?? $p->created_at)->format('d M Y') }}</td><td>{{ $p->reference }}</td><td>{{ __($p->channel->label()) }}</td>
                        <td class="text-right"><x-money :amount="$p->amount"/></td><td><x-status :value="$p->status"/></td>
                        <td>@if(in_array($p->status->value, ['succeeded', 'reversed']))<a href="{{ route('app.receipt', $p) }}" target="_blank" class="link text-xs">{{ __('Receipt') }}</a>@endif</td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No payments yet')"/>
                @endforelse
            </x-table>
        @endif
    </x-card>
</div>
