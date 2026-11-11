<div wire:poll.30s>
    <x-page-header title="Field visits" :subtitle="$seeAll ? 'Residence, business and collateral verification visits.' : 'Visits assigned to you.'"/>
    <x-tabs :active="$tab" :tabs="['today' => 'Due today / overdue', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'all' => 'All']"/>
    <x-card :padding="false">
        <div class="grid gap-3 border-b border-neutral-100 p-4 sm:grid-cols-3">
            <x-input class="sm:col-span-2" wire:model.live.debounce.400ms="search" placeholder="Search customer or address"/>
            @if($seeAll)<x-select wire:model.live="filterOfficer" :options="$officers" placeholder="All officers"/>@endif
        </div>
        {{-- Card list on mobile, table on desktop --}}
        <div class="divide-y divide-neutral-100 sm:hidden">
            @forelse($visits as $v)
                <a href="{{ route('ops.visits.show', $v) }}" wire:navigate class="block p-4">
                    <div class="flex items-center justify-between"><span class="font-semibold">{{ $v->customer->full_name }}</span><x-status :value="$v->status"/></div>
                    <p class="text-xs text-neutral-500">{{ ucfirst($v->visit_type) }} · {{ $v->scheduled_for->format('d M Y') }}</p><p class="mt-1 text-sm">{{ $v->address }}</p>
                </a>
            @empty <x-empty title="No visits"/> @endforelse
        </div>
        <div class="hidden sm:block">
            <x-table>
                <x-slot:head><x-th>Date</x-th><x-th>Customer</x-th><x-th>Type</x-th><x-th>Address</x-th>@if($seeAll)<x-th>Officer</x-th>@endif<x-th>Status</x-th><x-th>Result</x-th></x-slot:head>
                @forelse($visits as $v)
                    <tr><td class="whitespace-nowrap">{{ $v->scheduled_for->format('d M Y') }} @if($v->scheduled_for->isPast() && $v->status->value === 'scheduled' && ! $v->scheduled_for->isToday())<x-badge tone="red">overdue</x-badge>@endif</td>
                        <td><a href="{{ route('ops.visits.show', $v) }}" wire:navigate class="link">{{ $v->customer->full_name }}</a><div class="text-xs text-neutral-500">{{ $v->application?->application_no }}</div></td>
                        <td>{{ ucfirst($v->visit_type) }}</td><td class="max-w-xs text-sm">{{ $v->address }}</td>@if($seeAll)<td>{{ $v->officer?->name }}</td>@endif
                        <td><x-status :value="$v->status"/></td><td>@if($v->recommendation)<x-status :value="$v->recommendation"/>@else—@endif</td></tr>
                @empty <x-empty :colspan="$seeAll ? 7 : 6" title="No visits in this view"/> @endforelse
            </x-table>
        </div>
        {{ $visits->links() }}
    </x-card>
</div>
