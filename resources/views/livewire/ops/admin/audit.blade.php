<div>
    <x-page-header :title="__('Audit log')" :subtitle="__('Append-only record of sensitive actions in this institution. Secrets, passwords and OTPs are never recorded; CNICs are masked.')">
        <x-slot:actions>@if($canExport)<a href="{{ $exportUrl }}" class="btn-secondary">{{ __('Export CSV') }}</a>@endif</x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-6">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Action or reason') }}" class="field-input">
            <select wire:model.live="filterActor" class="field-input"><option value="">{{ __('All actors') }}</option>@foreach($actors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <input wire:model.live.debounce.400ms="filterAction" placeholder="{{ __('Action prefix e.g. wallet.') }}" class="field-input">
            <select wire:model.live="filterSubject" class="field-input"><option value="">{{ __('All subjects') }}</option>@foreach($subjects as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select>
            <div class="flex min-w-0 flex-wrap gap-1 sm:col-span-2 lg:col-span-2"><input type="date" wire:model.live="filterFrom" class="field-input min-w-[9rem] flex-1"><input type="date" wire:model.live="filterTo" class="field-input min-w-[9rem] flex-1"></div>
            <input wire:model.live.debounce.400ms="filterCorrelation" placeholder="{{ __('Correlation id') }}" class="field-input">
        </div>
        <x-table>
            <x-slot:head><x-th>{{ __('Time') }}</x-th><x-th>{{ __('Actor') }}</x-th><x-th>{{ __('Action') }}</x-th><x-th>{{ __('Subject') }}</x-th><x-th>{{ __('Reason') }}</x-th><x-th>{{ __('Correlation') }}</x-th></x-slot:head>
            @forelse($logs as $l)
                <tr wire:key="a{{ $l->id }}" class="cursor-pointer" wire:click="view({{ $l->id }})">
                    <td class="whitespace-nowrap text-xs">{{ $l->created_at->format('d M Y H:i:s') }}</td>
                    <td class="text-xs">{{ $l->actor?->name ?? __('System') }}<div class="text-neutral-500">{{ $l->actor_type }}</div></td>
                    <td class="font-mono text-xs">{{ $l->action }}</td>
                    <td class="text-xs">{{ $l->auditable_type ? class_basename($l->auditable_type).' #'.$l->auditable_id : '—' }}</td>
                    <td class="max-w-xs truncate text-xs">{{ $l->reason }}</td>
                    <td><button wire:click.stop="$set('filterCorrelation', '{{ $l->correlation_id }}')" class="font-mono text-[11px] text-brand-700 hover:underline">{{ \Illuminate\Support\Str::limit($l->correlation_id, 12, '…') }}</button></td>
                </tr>
            @empty
                <x-empty :colspan="6" :title="__('No audit events match these filters')"/>
            @endforelse
        </x-table>
        {{ $logs->links() }}
    </x-card>

    <x-modal show="showDetail" :title="__('Audit event')" max-width="2xl">
        @if($detail)
            <x-dl :items="[__('Action') => $detail->action, __('Time') => $detail->created_at->format('d M Y H:i:s'), __('Actor') => $detail->actor?->name ?? __('System'),
                __('Subject') => $detail->auditable_type ? class_basename($detail->auditable_type).' #'.$detail->auditable_id : null, __('IP') => $detail->ip,
                __('Correlation id') => $detail->correlation_id, __('Reason') => $detail->reason]"/>
            @php($keys = array_unique(array_merge(array_keys($detail->old_values ?? []), array_keys($detail->new_values ?? []))))
            @if($keys)
                <h4 class="mt-4 text-sm font-semibold">{{ __('Changes') }}</h4>
                <div class="relative mt-1 overflow-x-auto"><table class="table-base"><thead><tr><th>{{ __('Field') }}</th><th>{{ __('Before') }}</th><th>{{ __('After') }}</th></tr></thead><tbody>
                    @foreach($keys as $k)
                        @php($o = $detail->old_values[$k] ?? null)@php($n = $detail->new_values[$k] ?? null)
                        <tr><td class="font-mono text-xs">{{ $k }}</td><td class="text-xs text-red-700">{{ is_array($o) ? json_encode($o) : var_export($o, true) }}</td><td class="text-xs text-brand-800">{{ is_array($n) ? json_encode($n) : var_export($n, true) }}</td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
        @endif
    </x-modal>
</div>
