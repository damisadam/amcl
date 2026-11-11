<div>
    <x-page-header title="Support access" subtitle="Exceptional, time-limited access to a tenant's operational summary. Requires a justification and approval by a different platform user. Every use is audited."/>
    @if(auth()->user()->hasPermission('platform.support_access.request'))
        <x-card title="Request access">
            <form wire:submit="requestAccess" class="grid gap-4 sm:grid-cols-4">
                <x-select label="Tenant" wire:model="tenantId" :options="$tenants->all()" placeholder="Select…" required/>
                <x-input label="Ticket reference" wire:model="ticket" placeholder="SUP-1234"/>
                <x-select label="Duration" wire:model="hours" :options="[1 => '1 hour', 4 => '4 hours', 8 => '8 hours', 24 => '24 hours']"/>
                <div class="sm:col-span-4"><x-textarea label="Justification" wire:model="reason" hint="Why is access needed? (min 15 characters)" required/></div>
                <div class="sm:col-span-4 flex justify-end"><button class="btn-primary">Submit request</button></div>
            </form>
        </x-card>
    @endif

    <x-card class="mt-6" title="Requests & grants" :padding="false">
        <x-table>
            <x-slot:head><x-th>Tenant</x-th><x-th>Requested by</x-th><x-th>Reason</x-th><x-th>Duration</x-th><x-th>Status</x-th><x-th>Expires</x-th><x-th></x-th></x-slot:head>
            @forelse($grants as $row)
                @php($g = $row['g'])
                <tr>
                    <td>{{ $g->tenant?->name }}</td>
                    <td>{{ $g->platformUser?->name }}<div class="text-[11px] text-neutral-500">{{ $g->created_at->format('d M H:i') }}</div></td>
                    <td class="max-w-xs text-xs">{{ $g->reason }} @if($g->ticket_ref)<span class="text-neutral-500">({{ $g->ticket_ref }})</span>@endif</td>
                    <td>{{ $g->duration_hours }}h</td>
                    <td><x-badge :tone="['active' => 'green', 'pending' => 'amber', 'revoked' => 'red'][$row['status']] ?? 'gray'">{{ ucfirst($row['status']) }}</x-badge>
                        @if($g->grantor)<div class="text-[11px] text-neutral-500">by {{ $g->grantor->name }}</div>@endif</td>
                    <td class="text-xs">{{ $row['status'] === 'active' ? $g->expires_at->diffForHumans() : '—' }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if($row['status'] === 'pending' && auth()->user()->hasPermission('platform.support_access.grant') && $g->platform_user_id !== auth()->id())
                            <button wire:click="grant({{ $g->id }})" wire:confirm="Grant {{ $g->duration_hours }}h support access to {{ $g->tenant?->name }}?" class="btn-primary btn-sm">Grant</button>
                        @endif
                        @if($row['status'] === 'active' && $g->platform_user_id === auth()->id())
                            <button wire:click="openView({{ $g->id }})" class="btn-dark btn-sm">Open support view</button>
                        @endif
                        @if(in_array($row['status'], ['active', 'pending']))
                            <button wire:click="revoke({{ $g->id }})" wire:confirm="Revoke this access?" class="btn-ghost btn-sm text-red-600">Revoke</button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-empty colspan="7" title="No support access requests"/>
            @endforelse
        </x-table>
    </x-card>

    @if($summary && $viewTenant)
        <x-card class="mt-6" :title="'Support view – '.$viewTenant->name" subtitle="Read-only operational summary. No customer identities, balances or books.">
            <x-slot:actions><button wire:click="$set('summary', null)" class="btn-ghost btn-sm">Close</button></x-slot:actions>
            <div class="grid gap-6 lg:grid-cols-3">
                <div><p class="mb-2 text-xs font-semibold uppercase text-neutral-500">Applications by status</p>
                    @forelse($summary['applications_by_status'] as $s => $c)<div class="flex justify-between text-sm"><span>{{ \Illuminate\Support\Str::headline($s) }}</span><span class="tabular">{{ $c }}</span></div>@empty<p class="text-sm text-neutral-500">None</p>@endforelse</div>
                <div><p class="mb-2 text-xs font-semibold uppercase text-neutral-500">Loan accounts by status</p>
                    @forelse($summary['loans_by_status'] as $s => $c)<div class="flex justify-between text-sm"><span>{{ \Illuminate\Support\Str::headline($s) }}</span><span class="tabular">{{ $c }}</span></div>@empty<p class="text-sm text-neutral-500">None</p>@endforelse
                    <p class="mb-2 mt-4 text-xs font-semibold uppercase text-neutral-500">Users by status</p>
                    @foreach($summary['users_by_status'] as $s => $c)<div class="flex justify-between text-sm"><span>{{ ucfirst($s) }}</span><span class="tabular">{{ $c }}</span></div>@endforeach</div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span>Open complaints</span><strong>{{ $summary['open_complaints'] }}</strong></div>
                    <div class="flex justify-between"><span>Complaints past SLA</span><strong class="{{ $summary['overdue_complaints'] ? 'text-red-600' : '' }}">{{ $summary['overdue_complaints'] }}</strong></div>
                    <div class="flex justify-between"><span>Pending payment orders</span><strong>{{ $summary['pending_payments'] }}</strong></div>
                    <p class="pt-2 text-xs font-semibold uppercase text-neutral-500">Integration errors (24h)</p>
                    @forelse($summary['integration_errors'] as $e)<div class="flex justify-between text-xs"><span>{{ $e->provider }} · {{ $e->error_code }}</span><span>{{ $e->c }}</span></div>@empty<p class="text-xs text-neutral-500">None</p>@endforelse
                </div>
            </div>
        </x-card>
    @endif
</div>
