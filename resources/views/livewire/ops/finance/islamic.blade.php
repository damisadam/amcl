<div>
    <x-page-header title="Islamic finance & charity fund" subtitle="Late-payment amounts are routed to charity – never company income. Profit is recognised from Murabaha / Ijarah / Musharakah structures.">
        <x-slot:actions><x-input type="date" wire:model.live="from"/><x-input type="date" wire:model.live="to"/></x-slot:actions>
    </x-page-header>
    <x-tabs :tabs="['charity' => 'Charity fund', 'profit' => 'Profit recognition', 'audit' => 'Shariah audit support']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    @if($tab === 'charity')
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6">
                <x-stat label="Charity fund balance" :value="\App\Support\Money::format($charityBalance)" tone="amber" hint="Collected late-payment amounts awaiting disbursement"/>
                @can('fms.islamic.manage')
                <x-card title="Request charity disbursement">
                    <form wire:submit="requestCharity" class="space-y-3">
                        <x-input label="Beneficiary (registered charity)" wire:model="form.beneficiary"/>
                        <x-input label="Amount" wire:model="form.amount"/>
                        <x-textarea label="Purpose" wire:model="form.purpose" rows="2"/>
                        <button class="btn-primary w-full">Submit for approval</button>
                    </form>
                </x-card>
                @endcan
            </div>
            <div class="space-y-6 lg:col-span-2">
                <x-card title="Disbursement requests" :padding="false">
                    <x-table>
                        <x-slot:head><x-th>Ref</x-th><x-th>Beneficiary</x-th><x-th align="right">Amount</x-th><x-th>Requested by</x-th><x-th>Status</x-th></x-slot:head>
                        @forelse($requests as $r)
                            <tr><td class="tabular">{{ $r->reference }}</td><td>{{ $r->beneficiary }}<p class="text-xs text-neutral-500">{{ $r->purpose }}</p></td><td class="text-right"><x-money :amount="$r->amount"/></td><td class="text-xs">{{ $r->requester?->name }}</td><td><x-status :value="$r->status"/></td></tr>
                        @empty
                            <x-empty colspan="5" title="No charity disbursements"/>
                        @endforelse
                    </x-table>
                </x-card>
                @if($charityLedger)
                <x-card title="Charity fund ledger" :padding="false">
                    <x-table>
                        <x-slot:head><x-th>Date</x-th><x-th>Voucher</x-th><x-th>Narration</x-th><x-th align="right">In</x-th><x-th align="right">Out</x-th><x-th align="right">Balance</x-th></x-slot:head>
                        @forelse($charityLedger['rows'] as $r)
                            <tr><td>{{ \Carbon\Carbon::parse($r['line']->voucher_date)->format('d M Y') }}</td><td><a class="link" wire:navigate href="{{ route('ops.finance.journals.show', $r['line']->jv_id) }}">{{ $r['line']->voucher_no }}</a></td>
                                <td class="text-xs">{{ $r['line']->narration }}</td><td class="text-right"><x-money :amount="$r['line']->credit" :currency="false"/></td><td class="text-right"><x-money :amount="$r['line']->debit" :currency="false"/></td><td class="text-right"><x-money :amount="$r['balance']"/></td></tr>
                        @empty
                            <x-empty colspan="6" title="No charity postings in this range"/>
                        @endforelse
                    </x-table>
                </x-card>
                @endif
            </div>
        </div>
    @elseif($tab === 'profit')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Collected profit & late amounts by Shariah structure" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Structure</x-th><x-th>Component</x-th><x-th align="right">Amount</x-th></x-slot:head>
                    @forelse($profitByStructure as $r)
                        <tr><td>{{ \Illuminate\Support\Str::headline($r->s) }}</td><td>{{ $r->c === 'late_charge' ? 'Late payment → charity' : 'Profit / rental income' }}</td><td class="text-right"><x-money :amount="$r->v"/></td></tr>
                    @empty
                        <x-empty colspan="3" title="No collections in this range"/>
                    @endforelse
                </x-table>
            </x-card>
            <x-card title="Income per general ledger" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Account</x-th><x-th align="right">Amount</x-th></x-slot:head>
                    @forelse($glProfit as $r)<tr><td>{{ $r['account']->label() }}</td><td class="text-right"><x-money :amount="$r['amount']"/></td></tr>@empty<x-empty colspan="2" title="No income"/>@endforelse
                </x-table>
            </x-card>
        </div>
        <x-alert tone="blue" class="mt-6">Recognition basis in this release: profit is recognised as instalments are collected (proxy for recognition over the tenor). See docs/ACCOUNTING_RULES.md – the final Shariah / IFAS policy must be confirmed.</x-alert>
    @else
        <div class="space-y-6">
            <x-alert :tone="$interestLike ? 'red' : 'green'">{{ $interestLike ? $interestLike.' income account(s) are named as interest – review with the Shariah board.' : 'No interest-income accounts exist in the chart of accounts.' }}</x-alert>
            <x-card title="Products & Shariah board approval" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Product</x-th><x-th>Structure</x-th><x-th>Board approval</x-th><x-th>Reference</x-th><x-th>Status</x-th></x-slot:head>
                    @foreach($products as $p)
                        <tr><td>{{ $p->name }}</td><td>{{ $p->shariah_structure->label() }}</td><td>@if($p->shariah_board_approved)<x-badge tone="green">Approved</x-badge>@else<x-badge tone="amber">Pending</x-badge>@endif</td>
                            <td class="text-xs">{{ $p->shariah_approval_ref }}</td><td><x-status :value="$p->is_active ? 'active' : 'inactive'"/></td></tr>
                    @endforeach
                </x-table>
            </x-card>
            <x-card title="Charity routing check">
                <p class="text-sm">Charity fund balance <strong>{{ \App\Support\Money::format($charityBalance) }}</strong>. All late-payment collections are credited to the charity fund liability account and leave it only through approved charity disbursements.</p>
            </x-card>
        </div>
    @endif
</div>
