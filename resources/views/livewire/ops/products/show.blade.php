<div>
    <x-page-header :title="$product->name" :subtitle="$product->code.' · '.$product->category->label().' · '.$product->shariah_structure->label()" :back="route('ops.products.index')">
        <x-slot:actions>
            @if($product->is_active)<x-badge tone="green">{{ __('Active') }}</x-badge>@else<x-badge>{{ __('Inactive') }}</x-badge>@endif
            @if($canManage)<button wire:click="toggleActive" wire:confirm="{{ __('Change product availability?') }}" class="btn-secondary">{{ $product->is_active ? __('Deactivate') : __('Activate') }}</button>@endif
        </x-slot:actions>
    </x-page-header>

    <x-tabs :tabs="['terms' => __('Current terms').' (v'.($version?->version ?? '-').')', 'versions' => __('Version history')] + ($canManage ? ['new' => __('New version'), 'details' => __('Details & branches')] : [])" :active="$tab"/>

    @if($tab === 'terms' && $version)
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <x-card :title="__('Terms')">
                    <x-dl :cols="3" :items="[
                        __('Amount') => \App\Support\Money::format($version->min_amount).' – '.\App\Support\Money::format($version->max_amount),
                        __('Tenure') => $version->min_tenure.'–'.$version->max_tenure.' '.($version->isWeekly() ? 'weeks' : 'months'),
                        __('Profit rate') => (float) $version->profit_rate.'% p.a. ('.$version->profit_method.')',
                        __('Down payment') => (float) $version->down_payment_pct.'%', __('Min income') => \App\Support\Money::format($version->min_income),
                        __('Max DBR') => (float) $version->max_dbr.'% '.($version->dbr_hard_gate ? '(hard gate)' : '(advisory)'),
                        __('Age') => $version->min_age.'–'.$version->max_age, __('Guarantors') => $version->guarantors_required,
                        __('Vendor required') => $version->requires_vendor ? 'Yes' : 'No', __('Disbursement to') => \Illuminate\Support\Str::headline($version->disbursement_mode),
                        __('Cooling-off') => $version->cooling_off_hours.'h'.($version->cooling_off_waivable ? ' (waivable)' : ''),
                        __('Late payment') => $version->late_payment_treatment === 'charity' ? \App\Support\Money::format($version->late_charge_per_day).'/day after '.$version->grace_days.' days → charity' : 'None',
                        __('Allocation') => implode(' → ', $version->allocationOrder()), __('Partial / prepayment') => ($version->allow_partial_payment ? 'Partial ✓' : 'Partial ✗').' · '.($version->allow_prepayment ? 'Prepay ✓' : 'Prepay ✗'),
                        __('Min grade') => $version->min_credit_grade ?? '—', __('Effective from') => $version->effective_from->format('d M Y'),
                    ]"/>
                </x-card>
                <x-card :title="__('Charges')" :padding="false">
                    <x-table><x-slot:head><x-th>{{ __('Charge') }}</x-th><x-th>{{ __('Type') }}</x-th><x-th>{{ __('Value') }}</x-th><x-th>{{ __('Applied') }}</x-th></x-slot:head>
                        @forelse($version->charges as $c)<tr><td>{{ $c->name }} <span class="text-xs text-neutral-500">{{ $c->code }}</span></td><td>{{ $c->type->label() }}</td>
                            <td>{{ $c->calculation === 'percentage' ? (float) $c->value.'%' : \App\Support\Money::format($c->value) }}</td><td>{{ \Illuminate\Support\Str::headline($c->apply_at) }}</td></tr>
                        @empty<x-empty :colspan="4" :title="__('No charges')"/>@endforelse
                    </x-table>
                </x-card>
                <x-card :title="__('Required documents')" :padding="false">
                    <x-table><x-slot:head><x-th>{{ __('Document') }}</x-th><x-th>{{ __('For') }}</x-th><x-th>{{ __('Mandatory') }}</x-th><x-th>{{ __('Signing') }}</x-th><x-th>{{ __('From amount') }}</x-th></x-slot:head>
                        @forelse($version->requiredDocuments as $d)<tr><td>{{ $d->documentType->name }}</td><td>{{ ucfirst($d->applies_to) }}</td><td>{{ $d->is_mandatory ? 'Yes' : 'Optional' }}</td>
                            <td>{{ $d->documentType->signing_method }}</td><td>{{ $d->min_amount ? \App\Support\Money::format($d->min_amount) : 'Any' }}</td></tr>
                        @empty<x-empty :colspan="5" :title="__('None')"/>@endforelse
                    </x-table>
                </x-card>
            </div>
            <x-card :title="__('Pricing preview')">
                <div class="grid grid-cols-2 gap-2">
                    <x-input :label="__('Amount')" type="number" wire:model.live.debounce.500ms="previewAmount"/>
                    <x-input :label="__('Tenure')" type="number" wire:model.live.debounce.500ms="previewTenure"/>
                </div>
                @if($preview)
                    <x-dl class="mt-4" :cols="1" :items="[__('Instalment') => \App\Support\Money::format($preview['instalment']), __('Profit total') => \App\Support\Money::format($preview['profit_total']),
                        __('Charges (spread / upfront)') => \App\Support\Money::format($preview['charges_spread']).' / '.\App\Support\Money::format($preview['charges_upfront']),
                        __('Total payable') => \App\Support\Money::format($preview['total_payable']), __('APR') => $preview['apr'].'%', ($version?->disbursement_mode === 'vendor' ? __('Amount paid to vendor') : __('Net amount disbursed')) => \App\Support\Money::format($preview['net_disbursed'])]"/>
                @endif
                @if($children->isNotEmpty())
                    <p class="mt-4 text-xs font-semibold uppercase text-neutral-500">{{ __('Sub-products') }}</p>
                    <ul class="mt-1 text-sm">@foreach($children as $c)<li><a class="link" href="{{ route('ops.products.show', $c) }}" wire:navigate>{{ $c->name }}</a></li>@endforeach</ul>
                @endif
            </x-card>
        </div>
    @elseif($tab === 'versions')
        <x-card :padding="false">
            <x-table><x-slot:head><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Effective') }}</x-th><x-th>{{ __('Amount') }}</x-th><x-th>{{ __('Rate') }}</x-th><x-th>{{ __('Charges') }}</x-th><x-th>{{ __('Applications') }}</x-th></x-slot:head>
                @foreach($versions as $v)
                    <tr><td class="font-medium">v{{ $v->version }}</td><td><x-status :value="$v->status"/></td><td class="text-xs">{{ $v->effective_from->format('d M Y') }}</td>
                        <td class="text-xs">{{ \App\Support\Money::format($v->min_amount, false) }}–{{ \App\Support\Money::format($v->max_amount, false) }}</td><td>{{ (float) $v->profit_rate }}%</td>
                        <td class="text-xs">{{ $v->charges->map(fn($c) => $c->name.' '.($c->calculation === 'percentage' ? (float) $c->value.'%' : (float) $c->value))->join(', ') }}</td><td>{{ $usage[$v->id] ?? 0 }}</td></tr>
                @endforeach
            </x-table>
        </x-card>
    @elseif($tab === 'new' && $canManage)
        <x-alert tone="blue" class="mb-4">{{ __('Publishing creates version :n and retires the current one. In-flight applications and existing loans keep the terms they were created/approved under.', ['n' => ($version?->version ?? 0) + 1]) }}</x-alert>
        <form wire:submit="createVersion">
            @include('livewire.ops.products._terms')
            @error('action')<x-alert tone="red" class="mt-4">{{ $message }}</x-alert>@enderror
            <div class="mt-4 flex justify-end"><button class="btn-primary" wire:confirm="{{ __('Publish a new product version?') }}">{{ __('Publish new version') }}</button></div>
        </form>
    @elseif($tab === 'details' && $canManage)
        <div class="grid gap-4 lg:grid-cols-2">
            <x-card :title="__('Details')">
                <form wire:submit="saveInfo" class="space-y-3">
                    <x-input :label="__('Name (English)')" wire:model="info.name"/>
                    <x-input :label="__('Name (Urdu)')" wire:model="info.name_ur" dir="rtl"/>
                    <x-textarea :label="__('Description (English)')" wire:model="info.description"/>
                    <x-textarea :label="__('Description (Urdu)')" wire:model="info.description_ur" dir="rtl"/>
                    <x-select :label="__('Loan category')" wire:model="info.loan_category_id" placeholder="—" :options="$categories"/>
                    <x-input :label="__('Catalogue sort order')" type="number" wire:model="info.sort"/>
                    <x-checkbox wire:model="info.shariah_board_approved" :label="__('Approved by Shariah board')"/>
                    <x-input :label="__('Shariah approval reference')" wire:model="info.shariah_approval_ref"/>
                    <x-checkbox wire:model="info.show_in_catalog" :label="__('Show in customer catalogue')"/>
                    <button class="btn-primary">{{ __('Save details') }}</button>
                </form>
            </x-card>
            <x-card :title="__('Available at branches (office loan products)')">
                <div class="space-y-2">@foreach($branches as $b)<x-checkbox wire:model="branchIds" value="{{ $b->id }}" label="{{ $b->name }}"/>@endforeach</div>
                <button wire:click="saveBranches" class="btn-primary mt-4">{{ __('Save availability') }}</button>
            </x-card>
        </div>
    @endif
</div>
