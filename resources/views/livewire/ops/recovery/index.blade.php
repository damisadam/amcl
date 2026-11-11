<div wire:poll.60s>
    <x-page-header :title="__('Recovery')" :subtitle="$manager ? __('Delinquent cases, agent assignment and collection outcomes.') : __('Cases assigned to you.')"/>
    <x-alert tone="blue" class="mb-4" :title="__('Collections conduct')">{{ __('Contact only between 07:00 and 22:00, only the borrower or guarantor, never family, neighbours or employers. Calls are recorded.') }}</x-alert>

    @if($manager && $canWork)<x-tabs model="scope" :tabs="['mine' => __('My cases'), 'all' => __('All delinquent cases')]" :active="$scope"/>@endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-end gap-2 border-b border-neutral-100 p-3">
            <x-input class="w-56" wire:model.live.debounce.400ms="search" :placeholder="__('Account or customer…')"/>
            <x-select wire:model.live="filterBucket" :placeholder="__('All DPD')" :options="['1-30' => '1–30', '31-60' => '31–60', '61-90' => '61–90', '90+' => '90+']"/>
            @if($manager && $scope === 'all')
                <x-select wire:model.live="filterAgent" :placeholder="__('Any agent')" :options="['unassigned' => __('Unassigned')] + $agents"/>
                @if($canAssign)
                    <div class="ml-auto flex items-end gap-2">
                        <x-select wire:model="assignTo" :placeholder="__('Assign selected to…')" :options="$agents"/>
                        <button wire:click="assign" class="btn-primary" @disabled(! count($selected))>{{ __('Assign') }} ({{ count($selected) }})</button>
                    </div>
                @endif
            @endif
        </div>
        @error('selected')<p class="field-error px-3 pt-2">{{ __('Select at least one case.') }}</p>@enderror
        <x-table>
            <x-slot:head>@if($canAssign && $scope === 'all')<x-th></x-th>@endif<x-th>{{ __('Account') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th sort="dpd" :current="$sort" :dir="$dir">{{ __('DPD') }}</x-th>
                <x-th align="right">{{ __('Overdue') }}</x-th><x-th align="right" sort="principal_outstanding" :current="$sort" :dir="$dir">{{ __('Outstanding') }}</x-th><x-th>{{ __('Last contact') }}</x-th><x-th>{{ __('Agent') }}</x-th></x-slot:head>
            @forelse($cases as $l)
                @php($last = $l->recoveryContacts->first())
                <tr wire:key="c{{ $l->id }}">
                    @if($canAssign && $scope === 'all')<td><input type="checkbox" value="{{ $l->id }}" wire:model.live="selected" class="h-4 w-4 rounded border-neutral-300 text-brand-700"></td>@endif
                    <td><a class="link" href="{{ route('ops.recovery.show', $l) }}" wire:navigate>{{ $l->account_no }}</a><div class="text-xs text-neutral-500">{{ $l->branch?->name }}</div></td>
                    <td>{{ $l->customer->full_name }}</td>
                    <td><x-badge :tone="$l->dpd > 30 ? 'red' : 'amber'">{{ $l->dpd }}</x-badge> <span class="text-[11px] uppercase text-neutral-500">{{ $l->classification }}</span></td>
                    <td class="text-right font-semibold text-red-700"><x-money :amount="$l->overdueAmount()" :currency="false"/></td>
                    <td class="text-right"><x-money :amount="$l->totalOutstanding()" :currency="false"/></td>
                    <td class="text-xs">@if($last){{ $last->contacted_at->diffForHumans() }} · <x-status :value="$last->outcome"/>@else<span class="text-neutral-400">{{ __('Never') }}</span>@endif</td>
                    <td class="text-xs">{{ $l->recoveryAgent?->name ?? __('Unassigned') }}</td>
                </tr>
            @empty
                <x-empty :colspan="8" :title="__('No delinquent cases')" :description="$scope === 'mine' ? __('No overdue cases are assigned to you.') : __('All accounts are current.')"/>
            @endforelse
        </x-table>
        {{ $cases->links() }}
    </x-card>
</div>
