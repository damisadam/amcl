<div wire:poll.60s>
    <x-page-header :title="__('Collections')" :subtitle="__('Instalments due and overdue across your portfolio. Late payment amounts are routed to the charity fund.')">
        <x-slot:actions>
            @can('loans.manage')<button wire:click="runDelinquency" wire:confirm="{{ __('Recalculate DPD, late payment amounts and classifications now?') }}" class="btn-secondary">{{ __('Run delinquency now') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
        <x-stat :label="__('Due today')" :value="\App\Support\Money::format($dueToday->amt)" :hint="$dueToday->n.' '.__('instalments')" tone="amber"/>
        <x-stat :label="__('Collected today')" :value="\App\Support\Money::format($collectedToday)"/>
        @foreach(['1-30' => 'amber', '31-60' => 'red', '61-90' => 'red', '90+' => 'black'] as $b => $tone)
            <x-stat :label="__('Overdue :b days', ['b' => $b])" :value="\App\Support\Money::format($aging[$b]->amt ?? 0)" :hint="($aging[$b]->n ?? 0).' '.__('instalments')" :tone="$tone"/>
        @endforeach
    </div>

    <x-tabs :tabs="['overdue' => __('Overdue'), 'today' => __('Due today'), 'week' => __('Due in 7 days')]" :active="$tab"/>

    <x-card :padding="false">
        <div class="flex flex-wrap gap-2 border-b border-neutral-100 p-3">
            <x-input class="w-60" wire:model.live.debounce.400ms="search" :placeholder="__('Account, customer, CIF…')"/>
            @if($tab === 'overdue')<x-select wire:model.live="filterBucket" :placeholder="__('All aging buckets')" :options="['1-30' => '1–30 days', '31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => '90+ days']"/>@endif
            <x-select wire:model.live="filterBranch" :placeholder="__('All branches')" :options="$branches"/>
        </div>
        <x-table>
            <x-slot:head><x-th>{{ __('Account') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Instalment') }}</x-th><x-th>{{ __('Due date') }}</x-th><x-th>{{ __('Days late') }}</x-th>
                <x-th align="right">{{ __('Amount due') }}</x-th><x-th>{{ __('Agent') }}</x-th><x-th></x-th></x-slot:head>
            @forelse($rows as $i)
                @php($late = $i->due_date->isPast() ? (int) $i->due_date->diffInDays(today()) : 0)
                <tr wire:key="i{{ $i->id }}">
                    <td><a class="link" href="{{ route('ops.loans.show', $i->loanAccount) }}" wire:navigate>{{ $i->loanAccount->account_no }}</a><div class="text-xs text-neutral-500">{{ $i->loanAccount->branch?->name }}</div></td>
                    <td>{{ $i->loanAccount->customer->full_name }}<div class="text-xs text-neutral-500">{{ $sensitive ? $i->loanAccount->customer->mobile : substr($i->loanAccount->customer->mobile, 0, 4).'*****'.substr($i->loanAccount->customer->mobile, -2) }}</div></td>
                    <td>#{{ $i->number }} <x-status :value="$i->status"/></td>
                    <td class="text-xs">{{ $i->due_date->format('d M Y') }}</td>
                    <td>@if($late)<x-badge :tone="$late > 30 ? 'red' : 'amber'">{{ $late }} · {{ \App\Services\Lending\CollectionsService::bucket($late) }}</x-badge>@else<span class="text-neutral-400">—</span>@endif</td>
                    <td class="text-right font-semibold"><x-money :amount="\App\Support\Money::sub(\App\Support\Money::add($i->total_due, $i->late_charge_due), $i->total_paid)"/>
                        @if(\App\Support\Money::isPositive($i->late_charge_due))<div class="text-[11px] text-neutral-500">{{ __('incl. late') }} {{ \App\Support\Money::format($i->late_charge_due, false) }}</div>@endif</td>
                    <td class="text-xs">{{ $i->loanAccount->recoveryAgent?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        <button wire:click="remind({{ $i->id }})" class="btn-ghost btn-sm">{{ __('Remind') }}</button>
                        @can('repayments.record')<button wire:click="openPay({{ $i->id }})" class="btn-secondary btn-sm">{{ __('Record payment') }}</button>@endcan
                    </td>
                </tr>
            @empty
                <x-empty :colspan="8" :title="__('Nothing to collect here')" :description="__('No instalments match this view.')"/>
            @endforelse
        </x-table>
        {{ $rows->links() }}
    </x-card>

    <x-modal show="showPay" :title="__('Record payment')">
        <div class="space-y-3">
            <x-input :label="__('Amount (PKR)')" type="number" step="0.01" wire:model="payAmount"/>
            <x-select :label="__('Channel')" wire:model="payChannel" :options="['cash' => 'Cash at branch', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'salary_deduction' => 'Salary deduction']"/>
            <x-input :label="__('Receipt / bank reference')" wire:model="payRef"/>
            @error('action')<p class="field-error">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary" wire:click="recordPayment">{{ __('Record') }}</button></x-slot:footer>
    </x-modal>
</div>
