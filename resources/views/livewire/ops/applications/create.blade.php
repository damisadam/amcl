<div>
    <x-page-header title="New financing application" subtitle="Capture a branch, call-centre or vendor-assisted application. Submission, documents and guarantors are completed on the case file." :back="route('ops.applications.index')"/>
    @error('action')<x-alert tone="red" class="mb-4">{{ $message }}</x-alert>@enderror
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="1. Customer">
                @if($selected)
                    <div class="flex items-center justify-between rounded-lg bg-neutral-50 p-3">
                        <div><p class="font-semibold">{{ $selected->full_name }}</p><p class="text-xs text-neutral-500">{{ $selected->cif_no }} · {{ $selected->maskedCnic() }} · income {{ \App\Support\Money::format($selected->totalIncome()) }}</p></div>
                        <div class="flex items-center gap-2"><x-status :value="$selected->kyc_status"/><button wire:click="$set('customer', null)" class="btn-ghost btn-sm">Change</button></div>
                    </div>
                    @unless($selected->isKycPassed())<x-alert tone="amber" class="mt-3">KYC is not passed. The application can be drafted but cannot be submitted until KYC passes.</x-alert>@endunless
                @else
                    <x-input wire:model.live.debounce.300ms="customerSearch" placeholder="Search by name, CIF, mobile or CNIC (min 2 characters)"/>
                    <div class="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-100">
                        @foreach($matches as $m)
                            <button wire:click="pickCustomer({{ $m->id }})" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-brand-50"><span><strong>{{ $m->full_name }}</strong> · {{ $m->cif_no }}</span><x-status :value="$m->kyc_status"/></button>
                        @endforeach
                    </div>
                    @error('customer')<p class="field-error">Select a customer.</p>@enderror
                    <p class="field-hint">Not found? <a class="link" href="{{ route('ops.customers.create') }}" wire:navigate>Onboard a new customer</a>.</p>
                @endif
            </x-card>
            <x-card title="2. Product & terms">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select label="Product" wire:model.live="product_id" :options="$products" placeholder="Select product" required/>
                    <x-select label="Channel" wire:model="channel" :options="['branch' => 'Branch', 'vendor' => 'Vendor (point of sale)', 'call_center' => 'Call centre']"/>
                    @if($v)
                        @if($v->requires_vendor)
                            <x-select label="Vendor" wire:model.live="vendor_id" :options="$vendors" placeholder="Select vendor" required/>
                            <x-select label="Asset" wire:model.live="vendor_item_id" :options="$items" placeholder="Select item" required/>
                            <x-input label="Asset price" :value="$asset_price ? \App\Support\Money::format($asset_price) : ''" disabled/>
                            <x-input label="Down payment (min {{ $v->down_payment_pct }}%)" type="number" step="0.01" wire:model.live.debounce.500ms="down_payment"/>
                        @else
                            <x-input label="Financing amount ({{ \App\Support\Money::format($v->min_amount, false) }} – {{ \App\Support\Money::format($v->max_amount, false) }})" type="number" step="0.01" wire:model.live.debounce.500ms="requested_amount" required/>
                        @endif
                        <x-input label="Tenure ({{ $v->min_tenure }}–{{ $v->max_tenure }} {{ $v->isWeekly() ? 'weeks' : 'months' }})" type="number" wire:model.live.debounce.500ms="tenure" required/>
                        <x-select label="Funding source" wire:model="fund_id" :options="$funds" placeholder="Default"/>
                        <x-textarea class="sm:col-span-2" label="Purpose" wire:model="purpose" required/>
                    @endif
                </div>
            </x-card>
            <div class="flex justify-end"><button wire:click="save" class="btn-primary" wire:loading.attr="disabled">Create draft application</button></div>
        </div>
        <x-card title="Indicative quote" subtitle="Final terms appear in the Key Fact Statement after approval.">
            @if($quote)
                <x-dl :cols="1" :items="['Financed amount' => \App\Support\Money::format($quote['principal']), 'Instalment' => \App\Support\Money::format($quote['instalment']).' / '.($quote['frequency'] === 'weekly' ? 'week' : 'month'),
                    'Profit ('.$quote['profit_rate'].'% '.$quote['profit_method'].')' => \App\Support\Money::format($quote['profit_total']), 'Charges in instalments' => \App\Support\Money::format($quote['charges_spread']),
                    'Deducted upfront' => \App\Support\Money::format($quote['charges_upfront']), 'Total payable' => \App\Support\Money::format($quote['total_payable']), 'APR' => $quote['apr'].'%']"/>
                @if($selected && \App\Support\Money::isPositive($selected->totalIncome()))
                    @php($dbr = app(\App\Services\Products\EligibilityService::class)->dbr($selected->totalIncome(), $selected->existing_obligations, $quote['instalment']))
                    <div class="mt-4 rounded-lg p-3 text-sm {{ (float) $dbr > (float) $v->max_dbr ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-800' }}">Indicative DBR {{ $dbr }}% (limit {{ $v->max_dbr }}%)</div>
                @endif
            @else
                <p class="text-sm text-neutral-500">Select a product and enter the amount and tenure to see the quote.</p>
            @endif
        </x-card>
    </div>
</div>
