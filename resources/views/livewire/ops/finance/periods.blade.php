<div>
    <x-page-header title="Fiscal years & accounting periods" subtitle="Closed periods reject new postings. Reopening is restricted and audited.">
        <x-slot:actions>@can('fms.periods.manage')<button wire:click="createNextYear" wire:confirm="Create the next fiscal year with 12 monthly periods?" class="btn-primary">Create next fiscal year</button>@endcan</x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="space-y-6">
        @forelse($years as $fy)
            <x-card :title="$fy->name" :subtitle="$fy->start_date->format('d M Y').' – '.$fy->end_date->format('d M Y')" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Period</x-th><x-th>Dates</x-th><x-th>Status</x-th><x-th>Closed by</x-th><x-th>Reopen history</x-th><x-th></x-th></x-slot:head>
                    @foreach($fy->periods as $p)
                        <tr wire:key="p-{{ $p->id }}">
                            <td class="font-medium tabular">{{ $p->name }}</td>
                            <td class="text-xs">{{ $p->start_date->format('d M') }} – {{ $p->end_date->format('d M Y') }}</td>
                            <td><x-status :value="$p->status"/></td>
                            <td class="text-xs">{{ $p->closer?->name }} {{ $p->closed_at?->format('d M Y H:i') }}</td>
                            <td class="max-w-xs text-xs text-neutral-500">{{ $p->reopen_reason }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if($p->status === 'open')
                                    <button wire:click="check({{ $p->id }})" class="link text-xs">Checklist</button>
                                    @can('fms.periods.manage')<button wire:click="close({{ $p->id }})" wire:confirm="Close {{ $p->name }}? Postings into it will be blocked." class="btn-dark btn-sm ml-2">Close</button>@endcan
                                @else
                                    @can('fms.periods.reopen')<button wire:click="askReopen({{ $p->id }})" class="btn-secondary btn-sm">Reopen</button>@endcan
                                @endif
                            </td>
                        </tr>
                        @if($checkId === $p->id)
                            <tr><td colspan="6" class="bg-neutral-50">
                                @if($checklist)
                                    <ul class="list-disc pl-5 text-sm text-red-700">@foreach($checklist as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                                @else
                                    <p class="text-sm text-brand-800">✓ Ready to close: earlier periods closed, no drafts / pending vouchers, all vouchers balanced.</p>
                                @endif
                            </td></tr>
                        @endif
                    @endforeach
                </x-table>
            </x-card>
        @empty
            <x-empty title="No fiscal years defined" description="Create the fiscal year to start posting."/>
        @endforelse
    </div>
    <x-modal show="showReopen" title="Reopen accounting period">
        <x-alert>Reopening allows back-dated postings and changes reported figures. State the business reason; this is recorded in the audit log.</x-alert>
        <x-textarea label="Reason (min. 10 characters)" wire:model="reason" class="mt-3"/>
        @error('action')<p class="field-error">{{ $message }}</p>@enderror
        <x-slot:footer><button wire:click="$set('showReopen', false)" class="btn-secondary">Cancel</button><button wire:click="reopen" class="btn-danger">Reopen period</button></x-slot:footer>
    </x-modal>
</div>
