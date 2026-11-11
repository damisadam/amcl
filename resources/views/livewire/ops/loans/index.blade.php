<div wire:poll.60s>
    <x-page-header :title="__('Loan accounts')" :subtitle="__('Disbursed financing portfolio – balances maintained by the repayment engine.')">
        <x-slot:actions>
            @can('loans.manage')<a href="{{ route('ops.loans.transfer') }}" wire:navigate class="btn-secondary">{{ __('Portfolio transfer') }}</a>@endcan
            @can('reports.export')<button wire:click="export" class="btn-secondary">{{ __('Export CSV') }}</button>@endcan
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat :label="__('Accounts')" :value="number_format($totals->n)"/>
        <x-stat :label="__('Disbursed principal')" :value="\App\Support\Money::format($totals->principal)" tone="black"/>
        <x-stat :label="__('Principal outstanding')" :value="\App\Support\Money::format($totals->po)"/>
        <x-stat :label="__('Overdue accounts')" :value="number_format($totals->overdue)" tone="red"/>
        <x-stat :label="__('PAR 30')" :value="\App\Support\Money::isPositive($totals->po) ? number_format($totals->par30 / $totals->po * 100, 2).'%' : '0.00%'" :hint="\App\Support\Money::format($totals->par30)" tone="amber"/>
    </div>

    <x-card :padding="false">
        <div class="flex flex-wrap gap-2 border-b border-neutral-100 p-3">
            <x-input class="w-60" wire:model.live.debounce.400ms="search" :placeholder="__('Account, customer, CIF, mobile…')"/>
            <x-select wire:model.live="filterStatus" :placeholder="__('All statuses')" :options="\App\Enums\LoanAccountStatus::options()"/>
            <x-select wire:model.live="filterClass" :placeholder="__('All classifications')" :options="['regular' => 'Regular', 'oaem' => 'OAEM', 'substandard' => 'Substandard', 'doubtful' => 'Doubtful', 'loss' => 'Loss']"/>
            <x-select wire:model.live="filterDpd" :placeholder="__('All DPD')" :options="['current' => 'Current', '1-30' => '1–30', '31-60' => '31–60', '61-90' => '61–90', '90+' => '90+']"/>
            <x-select wire:model.live="filterProduct" :placeholder="__('All products')" :options="$products"/>
            <x-select wire:model.live="filterBranch" :placeholder="__('All branches')" :options="$branches"/>
            <x-select wire:model.live="filterOfficer" :placeholder="__('All officers')" :options="$officers"/>
        </div>
        <x-table>
            <x-slot:head>
                <x-th sort="id" :current="$sort" :dir="$dir">{{ __('Account') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Product') }}</x-th><x-th>{{ __('Branch / officer') }}</x-th>
                <x-th align="right">{{ __('Principal') }}</x-th><x-th align="right" sort="principal_outstanding" :current="$sort" :dir="$dir">{{ __('Outstanding') }}</x-th>
                <x-th sort="dpd" :current="$sort" :dir="$dir">{{ __('DPD') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th sort="maturity_date" :current="$sort" :dir="$dir">{{ __('Maturity') }}</x-th>
            </x-slot:head>
            @forelse($loans as $l)
                <tr wire:key="l{{ $l->id }}">
                    <td><a href="{{ route('ops.loans.show', $l) }}" wire:navigate class="link">{{ $l->account_no }}</a><div class="text-xs text-neutral-500">{{ $l->disbursed_at->format('d M Y') }}</div></td>
                    <td>{{ $l->customer->full_name }}<div class="text-xs text-neutral-500">{{ $l->customer->cif_no }}</div></td>
                    <td>{{ $l->product->name }}<div class="text-xs text-neutral-500">{{ $l->shariah_structure->label() }}</div></td>
                    <td class="text-xs">{{ $l->branch?->name }}<div class="text-neutral-500">{{ $l->officer?->name }}</div></td>
                    <td class="text-right"><x-money :amount="$l->principal" :currency="false"/></td>
                    <td class="text-right font-semibold"><x-money :amount="$l->totalOutstanding()" :currency="false"/></td>
                    <td>@if($l->dpd > 0)<x-badge :tone="$l->dpd > 30 ? 'red' : 'amber'">{{ $l->dpd }}</x-badge>@else<span class="text-neutral-400">0</span>@endif<div class="text-[11px] text-neutral-500">{{ \Illuminate\Support\Str::upper($l->classification) }}</div></td>
                    <td><x-status :value="$l->status"/></td>
                    <td class="text-xs">{{ $l->maturity_date->format('d M Y') }}</td>
                </tr>
            @empty
                <x-empty :colspan="9" :title="__('No loan accounts match')" :description="__('Loan accounts are created automatically when a disbursement is confirmed by the provider.')"/>
            @endforelse
        </x-table>
        {{ $loans->links() }}
    </x-card>
</div>
