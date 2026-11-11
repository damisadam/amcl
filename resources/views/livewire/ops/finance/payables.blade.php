<div>
    <x-page-header title="Accounts payable" subtitle="Vendor bills, payment requests (finance approval hierarchy) and release. A vendor is marked paid only after the simulated provider confirms."/>
    <x-tabs :tabs="['bills' => 'Vendor bills', 'requests' => 'Payment requests', 'release' => 'Ready to release', 'history' => 'Payment history', 'aging' => 'AP aging', 'ledger' => 'Vendor ledger']" :active="$tab"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror

    @if($tab === 'bills')
        <div class="grid gap-6 lg:grid-cols-3">
            @can('fms.payables.manage')
            <x-card title="Record vendor bill">
                <form wire:submit="createBill" class="space-y-3">
                    <x-select label="Vendor" wire:model.live="bill.vendor_id" placeholder="Select vendor" :options="$vendors"/>
                    <div class="grid grid-cols-2 gap-3">
                        <x-select label="Kind" wire:model="bill.kind" :options="['operational' => 'Operational', 'disbursement' => 'Asset / disbursement']"/>
                        <x-input label="Vendor invoice ref" wire:model="bill.vendor_invoice_ref"/>
                        <x-input type="date" label="Bill date" wire:model="bill.bill_date"/>
                        <x-input type="date" label="Due date" wire:model="bill.due_date"/>
                        <x-input label="Gross amount" wire:model.live.debounce.400ms="bill.amount" inputmode="decimal"/>
                        <x-input label="WHT rate %" wire:model.live.debounce.400ms="bill.wht_rate" :placeholder="$whtRate"/>
                    </div>
                    <p class="text-xs text-neutral-600">WHT @ {{ $whtRate }}% = <strong>{{ \App\Support\Money::format($whtPreview) }}</strong>; payable to vendor
                        <strong>{{ \App\Support\Money::format(is_numeric($bill['amount']) ? \App\Support\Money::sub($bill['amount'], $whtPreview) : 0) }}</strong></p>
                    <x-select label="Expense account" wire:model="bill.expense_account_id" placeholder="General & administrative (default)" :options="$expenseAccounts"/>
                    <x-select label="Cost center" wire:model="bill.cost_center_id" placeholder="—" :options="$costCenters"/>
                    <x-textarea label="Description" wire:model="bill.description" rows="2"/>
                    <button class="btn-primary w-full">Record & post bill</button>
                </form>
            </x-card>
            @endcan
            <x-card title="Bills" class="lg:col-span-2" :padding="false">
                <x-table>
                    <x-slot:head><x-th>Bill</x-th><x-th>Vendor</x-th><x-th>Due</x-th><x-th align="right">Gross</x-th><x-th align="right">WHT</x-th><x-th align="right">Paid</x-th><x-th>Status</x-th></x-slot:head>
                    @forelse($bills as $b)
                        <tr><td class="tabular">{{ $b->bill_no }}<p class="text-xs text-neutral-500">{{ $b->vendor_invoice_ref }}</p></td><td>{{ $b->vendor->business_name }}<p class="text-xs text-neutral-500">{{ $b->description }}</p></td>
                            <td @class(['text-red-600' => $b->due_date->isPast() && $b->status !== 'paid'])>{{ $b->due_date->format('d M Y') }}</td>
                            <td class="text-right"><x-money :amount="$b->amount"/></td><td class="text-right"><x-money :amount="$b->wht_amount" :currency="false"/></td>
                            <td class="text-right"><x-money :amount="$b->paid_amount" :currency="false"/></td><td><x-status :value="$b->status"/></td></tr>
                    @empty
                        <x-empty colspan="7" title="No vendor bills yet"/>
                    @endforelse
                </x-table>
                @if(method_exists($bills, 'links')){{ $bills->links() }}@endif
            </x-card>
        </div>
    @elseif(in_array($tab, ['requests', 'release', 'history']))
        <div class="grid gap-6 @if($tab === 'requests') lg:grid-cols-3 @endif">
            @if($tab === 'requests')
                @can('fms.payables.manage')
                <x-card title="New payment request">
                    <form wire:submit="requestPayment" class="space-y-3">
                        <x-select label="Vendor" wire:model.live="pay.vendor_id" placeholder="Select vendor" :options="$vendors"/>
                        <x-checkbox wire:model.live="pay.is_advance" label="Advance payment (no bill yet)"/>
                        @unless($pay['is_advance'])<x-select label="Bill" wire:model="pay.vendor_bill_id" placeholder="Select open bill" :options="$openBills"/>@endunless
                        <x-input label="Amount (blank = full open balance)" wire:model="pay.amount" inputmode="decimal"/>
                        <x-select label="Method" wire:model="pay.method" :options="['raast' => 'Raast', 'bank_transfer' => 'Bank transfer (IBFT)', 'cheque' => 'Cheque']"/>
                        <x-select label="Pay from" wire:model="pay.bank_account_id" placeholder="Operating account" :options="$bankAccounts"/>
                        <x-input label="Purpose" wire:model="pay.purpose"/>
                        <button class="btn-primary w-full">Submit for approval</button>
                    </form>
                </x-card>
                @endcan
            @endif
            <x-card :class="$tab === 'requests' ? 'lg:col-span-2' : ''" :padding="false">
                <x-slot:actions>
                    @if($tab === 'release' && \App\Services\Payments\Simulation::enabled())<x-select wire:model="scenario" placeholder="Scenario: tenant default" :options="config('integrations.payment_scenarios')"/>@endif
                    @if($tab === 'history')<x-select wire:model.live="filterStatus" placeholder="All statuses" :options="$statuses"/>@endif
                </x-slot:actions>
                @if($tab === 'requests')<div class="px-5 pt-4"><x-input wire:model="comment" placeholder="Approval comment / rejection reason"/></div>@endif
                <x-table>
                    <x-slot:head><x-th>Payment</x-th><x-th>Vendor</x-th><x-th align="right">Amount</x-th><x-th>Method</x-th><x-th>Requested by</x-th><x-th>Status</x-th><x-th></x-th></x-slot:head>
                    @forelse($payments as $p)
                        <tr wire:key="vp-{{ $p->id }}">
                            <td class="tabular">{{ $p->payment_no }}<p class="text-xs text-neutral-500">{{ $p->bill?->bill_no ?? ($p->is_advance ? 'Advance' : ($p->disbursement_id ? 'Financing disbursement' : '')) }}</p></td>
                            <td>{{ $p->vendor->business_name }}<p class="text-xs text-neutral-500">{{ $p->purpose }}</p></td>
                            <td class="text-right"><x-money :amount="$p->net_amount"/></td>
                            <td class="text-xs">{{ ucfirst(str_replace('_', ' ', $p->method)) }}<p class="text-neutral-500">{{ $p->paymentOrder?->reference }}</p></td>
                            <td class="text-xs">{{ $p->creator?->name }}</td>
                            <td><x-status :value="$p->status"/>@if($p->failure_reason)<p class="text-xs text-red-600">{{ $p->failure_reason }}</p>@endif</td>
                            <td class="whitespace-nowrap text-right">
                                @if(($canAct[$p->id] ?? false))
                                    <button wire:click="decide({{ $p->id }}, 'approve')" class="btn-primary btn-sm">Approve</button>
                                    <button wire:click="decide({{ $p->id }}, 'reject')" class="btn-danger btn-sm">Reject</button>
                                @endif
                                @if(in_array($p->status->value, ['approved', 'failed']))
                                    @can('fms.payables.execute')<button wire:click="release({{ $p->id }})" wire:confirm="Release {{ \App\Support\Money::format($p->net_amount) }} to {{ $p->vendor->business_name }}?" class="btn-primary btn-sm">{{ $p->status->value === 'failed' ? 'Retry' : 'Release' }}</button>@endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty colspan="7" title="Nothing here"/>
                    @endforelse
                </x-table>
                {{ $payments->links() }}
            </x-card>
        </div>
    @elseif($tab === 'aging')
        <x-card :padding="false" title="AP aging (open bills, net of WHT)">
            <x-table>
                <x-slot:head><x-th>Vendor</x-th><x-th align="right">Current</x-th><x-th align="right">1–30</x-th><x-th align="right">31–60</x-th><x-th align="right">61–90</x-th><x-th align="right">90+</x-th><x-th align="right">Total</x-th></x-slot:head>
                @forelse($aging as $r)
                    <tr><td>{{ $r['vendor'] }}</td>@foreach(['current', '1_30', '31_60', '61_90', 'over_90', 'total'] as $k)<td class="text-right"><x-money :amount="$r[$k]" :currency="false"/></td>@endforeach</tr>
                @empty
                    <x-empty colspan="7" title="No open payables"/>
                @endforelse
            </x-table>
        </x-card>
    @else
        <x-card title="Vendor ledger (payables account)" :padding="false">
            <x-slot:actions><x-select wire:model.live="ledgerVendor" placeholder="Select vendor" :options="$vendors"/></x-slot:actions>
            @if($ledger)
                @php($bal = '0.00')
                <x-table>
                    <x-slot:head><x-th>Date</x-th><x-th>Voucher</x-th><x-th>Narration</x-th><x-th align="right">Debit</x-th><x-th align="right">Credit</x-th><x-th align="right">Balance owed</x-th></x-slot:head>
                    @forelse($ledger as $l)
                        @php($bal = \App\Support\Money::add($bal, \App\Support\Money::sub($l->credit, $l->debit)))
                        <tr><td>{{ $l->voucher->voucher_date->format('d M Y') }}</td><td><a class="link" wire:navigate href="{{ route('ops.finance.journals.show', $l->journal_voucher_id) }}">{{ $l->voucher->voucher_no }}</a></td>
                            <td class="text-xs">{{ $l->voucher->narration }}</td><td class="text-right"><x-money :amount="$l->debit" :currency="false"/></td><td class="text-right"><x-money :amount="$l->credit" :currency="false"/></td><td class="text-right"><x-money :amount="$bal"/></td></tr>
                    @empty
                        <x-empty colspan="6" title="No payable postings for this vendor"/>
                    @endforelse
                </x-table>
            @else
                <p class="p-5 text-sm text-neutral-500">Select a vendor to see their running payable balance.</p>
            @endif
        </x-card>
    @endif
</div>
