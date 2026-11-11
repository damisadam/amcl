{{-- Shared product terms form (create product / new version). --}}
<div class="space-y-5">
    <x-card :title="__('Amounts, tenure & pricing')">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-input :label="__('Min amount (PKR)')" type="number" wire:model="terms.min_amount"/>
            <x-input :label="__('Max amount (PKR)')" type="number" wire:model="terms.max_amount"/>
            <x-select :label="__('Instalment frequency')" wire:model="terms.frequency" :options="['monthly' => 'Monthly', 'weekly' => 'Weekly']"/>
            <x-input :label="__('Min tenure (periods)')" type="number" wire:model="terms.min_tenure"/>
            <x-input :label="__('Max tenure (periods)')" type="number" wire:model="terms.max_tenure"/>
            <x-input :label="__('Down payment %')" type="number" step="0.01" wire:model="terms.down_payment_pct"/>
            <x-input :label="__('Disclosed profit rate % p.a.')" type="number" step="0.001" wire:model="terms.profit_rate"/>
            <x-select :label="__('Profit method')" wire:model="terms.profit_method" :options="['flat' => 'Flat (Murabaha markup)', 'reducing' => 'Reducing (Ijarah / Dim. Musharakah)']"/>
            <x-select :label="__('Disbursement to')" wire:model="terms.disbursement_mode" :options="['vendor' => 'Vendor (asset purchase)', 'customer_bank' => 'Customer bank account', 'wallet' => 'Customer wallet']"/>
        </div>
    </x-card>
    <x-card :title="__('Eligibility & credit policy')">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-input :label="__('Minimum monthly income')" type="number" wire:model="terms.min_income"/>
            <x-input :label="__('Max DBR %')" type="number" step="0.01" wire:model="terms.max_dbr"/>
            <x-select :label="__('Minimum credit grade')" wire:model="terms.min_credit_grade" :placeholder="__('None')" :options="['A' => 'A', 'B+' => 'B+', 'B' => 'B', 'C' => 'C']"/>
            <x-input :label="__('Min age')" type="number" wire:model="terms.min_age"/>
            <x-input :label="__('Max age')" type="number" wire:model="terms.max_age"/>
            <x-input :label="__('Guarantors required')" type="number" wire:model="terms.guarantors_required"/>
        </div>
        <div class="mt-3 flex flex-wrap gap-5">
            <x-checkbox wire:model="terms.dbr_hard_gate" :label="__('DBR is a hard eligibility gate')"/>
            <x-checkbox wire:model="terms.requires_vendor" :label="__('Vendor / supplier required')"/>
        </div>
    </x-card>
    <x-card :title="__('Servicing rules')">
        <div class="grid gap-3 sm:grid-cols-3">
            <x-input :label="__('Cooling-off hours')" type="number" wire:model="terms.cooling_off_hours"/>
            <x-select :label="__('Late payment treatment')" wire:model="terms.late_payment_treatment" :options="['charity' => 'Routed to charity fund', 'none' => 'No late amount']"/>
            <x-input :label="__('Late amount per day (PKR)')" type="number" step="0.01" wire:model="terms.late_charge_per_day"/>
            <x-input :label="__('Grace days')" type="number" wire:model="terms.grace_days"/>
            <x-input class="sm:col-span-2" :label="__('Payment allocation order')" wire:model="terms.allocation" :hint="__('Comma separated: late_charge, charges, profit, principal')"/>
        </div>
        <div class="mt-3 flex flex-wrap gap-5">
            <x-checkbox wire:model="terms.cooling_off_waivable" :label="__('Customer may waive cooling-off')"/>
            <x-checkbox wire:model="terms.allow_partial_payment" :label="__('Allow partial payments')"/>
            <x-checkbox wire:model="terms.allow_prepayment" :label="__('Allow prepayment')"/>
        </div>
    </x-card>
    <x-card :title="__('Charges')" :padding="false">
        <x-slot:actions><button type="button" wire:click="addCharge" class="btn-secondary btn-sm">+ {{ __('Charge') }}</button></x-slot:actions>
        <div class="space-y-2 p-4">
            @forelse($charges as $i => $c)
                <div class="grid items-end gap-2 sm:grid-cols-12" wire:key="ch{{ $i }}">
                    <x-input class="sm:col-span-1" :label="$i === 0 ? __('Code') : null" wire:model="charges.{{ $i }}.code"/>
                    <x-input class="sm:col-span-3" :label="$i === 0 ? __('Name') : null" wire:model="charges.{{ $i }}.name"/>
                    <x-select class="sm:col-span-2" :label="$i === 0 ? __('Type') : null" wire:model="charges.{{ $i }}.type" :options="\App\Enums\ChargeType::options()"/>
                    <x-select class="sm:col-span-2" :label="$i === 0 ? __('Calculation') : null" wire:model="charges.{{ $i }}.calculation" :options="['flat' => 'Flat PKR', 'percentage' => '% of financed']"/>
                    <x-input class="sm:col-span-1" :label="$i === 0 ? __('Value') : null" type="number" step="0.001" wire:model="charges.{{ $i }}.value"/>
                    <x-select class="sm:col-span-2" :label="$i === 0 ? __('Applied') : null" wire:model="charges.{{ $i }}.apply_at" :options="['upfront_deducted' => 'Deducted from payout', 'added_to_instalments' => 'Spread in instalments', 'on_event' => 'On event']"/>
                    <button type="button" wire:click="removeCharge({{ $i }})" class="btn-ghost btn-sm text-red-600 sm:col-span-1">✕</button>
                </div>
            @empty
                <p class="text-sm text-neutral-500">{{ __('No charges.') }}</p>
            @endforelse
            <p class="field-hint">{{ __('For vendor-paid products, "deducted from payout" charges are spread into instalments automatically (DECISIONS D-009).') }}</p>
        </div>
    </x-card>
    <x-card :title="__('Required documents')" :padding="false">
        <x-slot:actions><button type="button" wire:click="addDoc" class="btn-secondary btn-sm">+ {{ __('Document') }}</button></x-slot:actions>
        <div class="space-y-2 p-4">
            @forelse($docs as $i => $d)
                <div class="grid items-end gap-2 sm:grid-cols-12" wire:key="dc{{ $i }}">
                    <x-select class="sm:col-span-5" :label="$i === 0 ? __('Document type') : null" wire:model="docs.{{ $i }}.code" :options="$documentTypes"/>
                    <x-select class="sm:col-span-2" :label="$i === 0 ? __('For') : null" wire:model="docs.{{ $i }}.applies_to" :options="['applicant' => 'Applicant', 'guarantor' => 'Guarantor']"/>
                    <x-input class="sm:col-span-2" :label="$i === 0 ? __('From amount') : null" type="number" wire:model="docs.{{ $i }}.min_amount" :placeholder="__('Any')"/>
                    <x-checkbox class="sm:col-span-2 pb-2" wire:model="docs.{{ $i }}.mandatory" :label="__('Mandatory')"/>
                    <button type="button" wire:click="removeDoc({{ $i }})" class="btn-ghost btn-sm text-red-600 sm:col-span-1">✕</button>
                </div>
            @empty
                <p class="text-sm text-neutral-500">{{ __('No required documents.') }}</p>
            @endforelse
        </div>
    </x-card>
</div>
