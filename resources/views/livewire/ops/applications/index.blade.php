<div wire:poll.30s>
    <x-page-header title="Financing applications" subtitle="Verification, assessment and approval queues.">
        <x-slot:actions>
            @can('reports.export')<button wire:click="export" class="btn-secondary">Export CSV</button>@endcan
            @can('applications.create')<a href="{{ route('ops.applications.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>New application</a>@endcan
        </x-slot:actions>
    </x-page-header>
    <x-tabs :active="$tab" :tabs="collect(['active' => 'In progress', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'pending_approval' => 'Pending approval', 'returned' => 'Returned',
        'approved' => 'Approved / pre-disbursement', 'disbursed' => 'Disbursed', 'rejected' => 'Rejected', 'closed' => 'Withdrawn / cancelled', 'draft' => 'Drafts', 'all' => 'All'])->map(fn ($l, $k) => $l.' ('.$tabCounts[$k].')')->all()"/>
    <x-card :padding="false">
        <div class="grid gap-3 border-b border-neutral-100 p-4 sm:grid-cols-3 lg:grid-cols-7">
            <x-input class="lg:col-span-2" wire:model.live.debounce.400ms="search" placeholder="Application no, customer, CIF, mobile…"/>
            <x-select wire:model.live="filterProduct" :options="$products" placeholder="All products"/>
            <x-select wire:model.live="filterBranch" :options="$branches" placeholder="All branches"/>
            <x-select wire:model.live="filterChannel" :options="['web' => 'Web / app', 'branch' => 'Branch', 'vendor' => 'Vendor', 'call_center' => 'Call centre']" placeholder="All channels"/>
            <x-input type="date" wire:model.live="from" title="Created from"/><x-input type="date" wire:model.live="to" title="Created to"/>
        </div>
        <x-table wire:loading.class="opacity-60">
            <x-slot:head><x-th sort="application_no" :current="$sort" :dir="$dir">Application</x-th><x-th>Customer</x-th><x-th>Product</x-th><x-th sort="requested_amount" :current="$sort" :dir="$dir" align="right">Amount</x-th>
                <x-th>Grade / risk</x-th><x-th>Status</x-th><x-th>Current stage</x-th><x-th sort="submitted_at" :current="$sort" :dir="$dir">Submitted</x-th></x-slot:head>
            @forelse($applications as $a)
                <tr>
                    <td><a href="{{ route('ops.applications.show', $a) }}" wire:navigate class="link tabular">{{ $a->application_no }}</a><div class="text-xs text-neutral-500">{{ ucfirst($a->channel) }} · {{ $a->branch?->name }}</div></td>
                    <td class="font-medium">{{ $a->customer->full_name }}<div class="text-xs text-neutral-500">{{ $a->customer->cif_no }}</div></td>
                    <td>{{ $a->product->name }}<div class="text-xs text-neutral-500">{{ $a->tenure }} {{ $a->frequency === 'weekly' ? 'wks' : 'mths' }}</div></td>
                    <td class="text-right"><x-money :amount="$a->requested_amount"/></td>
                    <td>@if($a->credit_grade)<x-badge tone="black">{{ $a->credit_grade }}</x-badge>@endif @if($a->risk_rating)<x-status :value="$a->risk_rating"/>@endif @if($a->dbr)<div class="text-xs text-neutral-500">DBR {{ $a->dbr }}%</div>@endif</td>
                    <td><x-status :value="$a->status"/></td>
                    <td class="text-xs">@if($a->status === \App\Enums\ApplicationStatus::PendingApproval && $a->approvalRequest?->currentStage){{ $a->approvalRequest->currentStage->name }}
                        @if($a->approvalRequest->isOverdue())<x-badge tone="red">SLA</x-badge>@endif @else—@endif</td>
                    <td class="whitespace-nowrap text-xs">{{ $a->submitted_at?->format('d M Y H:i') ?? '—' }}</td>
                </tr>
            @empty <x-empty colspan="8" title="No applications in this queue"/> @endforelse
        </x-table>
        {{ $applications->links() }}
    </x-card>
</div>
