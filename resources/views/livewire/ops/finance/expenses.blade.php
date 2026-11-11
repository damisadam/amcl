<div>
    <x-page-header title="Expenses & purchase requests" subtitle="Claims follow the finance expense approval hierarchy; approval posts the expense to the general ledger."/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="grid gap-6 lg:grid-cols-3">
        @can('fms.expenses.create')
        <x-card title="New claim / request">
            <form wire:submit="submit" class="space-y-3">
                <x-select label="Type" wire:model="form.kind" :options="$kinds"/>
                <x-select label="Expense account" wire:model="form.expense_account_id" placeholder="Select" :options="$accounts"/>
                <div class="grid grid-cols-2 gap-3">
                    <x-input type="date" label="Date" wire:model="form.expense_date"/>
                    <x-input label="Amount" wire:model="form.amount" inputmode="decimal"/>
                    <x-select label="Branch" wire:model="form.branch_id" placeholder="—" :options="$branches"/>
                    <x-select label="Cost center" wire:model="form.cost_center_id" placeholder="—" :options="$costCenters"/>
                </div>
                <x-select label="Supplier (optional)" wire:model="form.vendor_id" placeholder="—" :options="$vendors"/>
                <x-textarea label="Description" wire:model="form.description" rows="2"/>
                <div><label class="field-label">Receipt</label><input type="file" wire:model="receipt" class="text-sm">@error('receipt')<p class="field-error">{{ $message }}</p>@enderror</div>
                <button class="btn-primary w-full" wire:loading.attr="disabled">Submit for approval</button>
            </form>
        </x-card>
        @endcan
        <x-card class="lg:col-span-2" :padding="false">
            <x-slot:actions>
                @can('fms.view')<x-select wire:model.live="scope" :options="['mine' => 'My claims', 'all' => 'All claims']"/>@endcan
                <x-select wire:model.live="filterKind" placeholder="All types" :options="$kinds"/>
                <x-select wire:model.live="filterStatus" placeholder="All statuses" :options="['pending_approval' => 'Pending approval', 'paid' => 'Paid', 'rejected' => 'Rejected']"/>
            </x-slot:actions>
            <x-table>
                <x-slot:head><x-th>Ref</x-th><x-th>Description</x-th><x-th>Account</x-th><x-th align="right">Amount</x-th><x-th>By</x-th><x-th>Status</x-th></x-slot:head>
                @forelse($claims as $c)
                    <tr><td class="tabular">{{ $c->reference }}<p class="text-xs text-neutral-500">{{ $c->expense_date->format('d M Y') }}</p></td>
                        <td>{{ $c->description }}<p class="text-xs text-neutral-500">{{ $kinds[$c->kind] ?? $c->kind }} · {{ $c->branch?->name }}</p>
                            @foreach($c->documents as $d)<a href="{{ route('documents.show', $d) }}" target="_blank" class="link text-xs">Receipt</a>@endforeach</td>
                        <td class="text-xs">{{ $c->expenseAccount?->label() }}</td><td class="text-right"><x-money :amount="$c->amount"/></td><td class="text-xs">{{ $c->requester?->name }}</td>
                        <td><x-status :value="$c->status"/>@if($c->journal_voucher_id)<a href="{{ route('ops.finance.journals.show', $c->journal_voucher_id) }}" wire:navigate class="link block text-xs">Voucher</a>@endif</td></tr>
                @empty
                    <x-empty colspan="6" title="No claims"/>
                @endforelse
            </x-table>
            {{ $claims->links() }}
        </x-card>
    </div>
</div>
