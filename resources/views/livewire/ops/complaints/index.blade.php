<div wire:poll.30s>
    <x-page-header :title="__('Complaints & disputes')" :subtitle="__('Structured grievance redressal with named owners and response timelines.')"/>
    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="__('Open')" :value="(int) $counts->open" tone="amber"/>
        <x-stat :label="__('SLA overdue')" :value="(int) $counts->overdue" tone="red"/>
        <x-stat :label="__('Open disputes / refunds')" :value="(int) $counts->disputes" tone="black"/>
        <x-stat :label="__('Escalated')" :value="(int) $counts->escalated" tone="red"/>
    </div>
    <x-card :padding="false">
        <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-6">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Ticket / subject') }}" class="field-input lg:col-span-2">
            <select wire:model.live="filterStatus" class="field-input"><option value="">{{ __('All') }}</option><option value="open">{{ __('All open') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterCategory" class="field-input"><option value="">{{ __('All categories') }}</option>@foreach($categories as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterPriority" class="field-input"><option value="">{{ __('Any priority') }}</option><option value="high">{{ __('High') }}</option><option value="normal">{{ __('Normal') }}</option></select>
            <div class="flex items-center gap-3 text-sm"><x-checkbox wire:model.live="filterOverdue" :label="__('Overdue')"/><x-checkbox wire:model.live="filterMine" :label="__('Mine')"/></div>
        </div>
        <x-table>
            <x-slot:head><x-th sort="id" :current="$sort" :dir="$dir">{{ __('Ticket') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Category') }}</x-th><x-th sort="priority" :current="$sort" :dir="$dir">{{ __('Priority') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Owner') }}</x-th><x-th sort="sla_due_at" :current="$sort" :dir="$dir">{{ __('SLA due') }}</x-th></x-slot:head>
            @forelse($rows as $c)
                <tr wire:key="c{{ $c->id }}">
                    <td><a href="{{ route('ops.complaints.show', $c) }}" wire:navigate class="link tabular text-xs">{{ $c->ticket_no }}</a><div class="max-w-xs truncate text-xs text-neutral-600">{{ $c->subject }}</div></td>
                    <td class="text-xs">{{ $c->customer?->full_name }}<div class="text-neutral-500">{{ $c->customer?->cif_no }}</div></td>
                    <td class="text-xs">{{ $c->category->label() }}</td>
                    <td><x-badge :tone="$c->priority === 'high' ? 'red' : 'gray'">{{ ucfirst($c->priority) }}</x-badge></td>
                    <td><x-status :value="$c->status"/></td>
                    <td class="text-xs">{{ $c->assignee?->name ?? __('Unassigned') }}</td>
                    <td @class(['text-xs', 'font-semibold text-red-600' => $c->isOverdue(), 'text-neutral-500' => ! $c->isOverdue()])>{{ $c->sla_due_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <x-empty :colspan="7" :title="__('No complaints in this view')"/>
            @endforelse
        </x-table>
        {{ $rows->links() }}
    </x-card>
</div>
