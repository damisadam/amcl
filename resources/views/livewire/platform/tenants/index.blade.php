<div>
    <x-page-header title="Tenants" subtitle="Institutions operating on the platform">
        <x-slot:actions>@if(auth()->user()->hasPermission('platform.tenants.manage'))<a href="{{ route('platform.tenants.create') }}" wire:navigate class="btn-primary">+ Provision tenant</a>@endif</x-slot:actions>
    </x-page-header>
    <x-card :padding="false">
        <div class="flex flex-wrap gap-3 border-b border-neutral-100 p-4">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, code, city…" class="field-input max-w-xs">
            <select wire:model.live="filterStatus" class="field-input max-w-[180px]"><option value="">All statuses</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
        </div>
        <x-table>
            <x-slot:head>
                <x-th sort="code" :current="$sort" :dir="$dir">Code</x-th><x-th sort="name" :current="$sort" :dir="$dir">Institution</x-th><x-th>City</x-th><x-th>Licence ref</x-th>
                <x-th sort="status" :current="$sort" :dir="$dir">Status</x-th><x-th>Features</x-th><x-th sort="created_at" :current="$sort" :dir="$dir">Created</x-th>
            </x-slot:head>
            @forelse($tenants as $t)
                <tr>
                    <td class="font-mono text-xs">{{ $t->code }}</td>
                    <td><a href="{{ route('platform.tenants.show', $t) }}" wire:navigate class="link">{{ $t->name }}</a>@if($t->is_demo) <x-badge tone="amber">Demo</x-badge>@endif
                        <div class="text-xs text-neutral-500">{{ $t->legal_name }}</div></td>
                    <td>{{ $t->city }}</td>
                    <td class="text-xs">{{ $t->license_ref ?: '—' }}</td>
                    <td><x-status :value="$t->status"/></td>
                    <td class="text-xs text-neutral-600">{{ count($t->features ?? []) }} / {{ count(\App\Models\Tenant::FEATURES) }}</td>
                    <td class="text-xs text-neutral-500">{{ $t->created_at->format('d M Y') }}</td>
                </tr>
            @empty
                <x-empty colspan="7" title="No tenants match" description="Adjust the filters or provision a new tenant."/>
            @endforelse
        </x-table>
        {{ $tenants->links() }}
    </x-card>
</div>
