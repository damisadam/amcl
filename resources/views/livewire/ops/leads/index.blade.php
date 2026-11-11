<div>
    <x-page-header title="Leads" subtitle="Inbound financing interest from all channels.">
        <x-slot:actions>@can('leads.manage')<button wire:click="$set('showCreate', true)" class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>New lead</button>@endcan</x-slot:actions>
    </x-page-header>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @foreach(\App\Services\Lending\LeadService::STATUSES as $s)
            <button wire:click="$set('filterStatus', '{{ $filterStatus === $s ? '' : $s }}')" @class(['card p-3 text-left', 'ring-2 ring-brand-600' => $filterStatus === $s])>
                <p class="text-xs uppercase text-neutral-500">{{ ucfirst($s) }}</p><p class="text-xl font-bold tabular">{{ $pipeline[$s] ?? 0 }}</p></button>
        @endforeach
    </div>
    <x-card :padding="false">
        <div class="grid gap-3 border-b border-neutral-100 p-4 sm:grid-cols-4">
            <x-input class="sm:col-span-2" wire:model.live.debounce.400ms="search" placeholder="Search name, mobile, lead no…"/>
            <x-select wire:model.live="filterChannel" :options="$channels" placeholder="All channels"/>
            <x-select wire:model.live="filterMine" :options="['1' => 'Assigned to me']" placeholder="All owners"/>
        </div>
        <x-table>
            <x-slot:head><x-th sort="lead_no" :current="$sort" :dir="$dir">Lead</x-th><x-th sort="name" :current="$sort" :dir="$dir">Prospect</x-th><x-th>Channel</x-th><x-th>Product</x-th>
                <x-th sort="requested_amount" :current="$sort" :dir="$dir" align="right">Amount</x-th><x-th>Owner</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
            @forelse($leads as $l)
                <tr><td class="tabular">{{ $l->lead_no }}<div class="text-xs text-neutral-500">{{ $l->created_at->format('d M Y') }}</div></td>
                    <td class="font-medium">{{ $l->name }}<div class="text-xs text-neutral-500">{{ $l->mobile }}</div></td>
                    <td>{{ $channels[$l->channel] ?? $l->channel }}</td><td>{{ $l->product?->name ?? '—' }}</td>
                    <td class="text-right">@if($l->requested_amount)<x-money :amount="$l->requested_amount"/>@else—@endif</td><td>{{ $l->assignee?->name }}</td>
                    <td><x-status :value="$l->status"/>@if($l->converted_application_id)<a class="link block text-xs" href="{{ route('ops.applications.show', $l->converted_application_id) }}" wire:navigate>View application</a>@endif</td>
                    <td class="whitespace-nowrap text-right">@can('leads.manage')@if(! in_array($l->status, ['converted']))
                        <button wire:click="openMove({{ $l->id }})" class="btn-secondary btn-sm">Update</button>
                        @if($l->status !== 'dropped')<button wire:click="convert({{ $l->id }})" wire:confirm="Create the customer (if new) and a draft application?" class="btn-primary btn-sm">Convert</button>@endif
                    @endif @endcan</td></tr>
            @empty <x-empty colspan="8" title="No leads" description="Capture a walk-in, call-centre or vendor referral lead."/> @endforelse
        </x-table>
        {{ $leads->links() }}
    </x-card>

    <x-modal show="showCreate" title="New lead" maxWidth="xl">
        <div class="grid gap-3 sm:grid-cols-2">
            <x-input label="Name" wire:model="form.name" required/><x-input label="Mobile" wire:model="form.mobile" placeholder="03001234567" required/>
            <x-input label="CNIC (optional)" wire:model="form.cnic"/><x-select label="Channel" wire:model="form.channel" :options="$channels"/>
            <x-select label="Product" wire:model="form.product_id" :options="$products" placeholder="Not decided"/><x-input label="Requested amount" type="number" wire:model="form.requested_amount"/>
            <x-select class="sm:col-span-2" label="Referring vendor" wire:model="form.vendor_id" :options="$vendors" placeholder="None"/>
            <x-textarea class="sm:col-span-2" label="Notes" wire:model="form.notes"/>
        </div>
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">Cancel</button><button wire:click="create" class="btn-primary">Save lead</button></x-slot:footer>
    </x-modal>
    <x-modal show="showMove" title="Update lead">
        <div class="space-y-3">
            <x-select label="Move to" wire:model="moveTo" placeholder="Select status" :options="['contacted' => 'Contacted', 'qualified' => 'Qualified', 'dropped' => 'Dropped', 'new' => 'Re-open (new)']"/>
            <x-textarea label="Note" wire:model="moveNote"/>
            <button wire:click="move" class="btn-primary w-full">Update status</button>
            <div class="border-t border-neutral-100 pt-3"><x-select label="Assign to" wire:model="assignTo" :options="$staff"/><button wire:click="assign" class="btn-secondary mt-2 w-full">Assign</button></div>
        </div>
    </x-modal>
</div>
