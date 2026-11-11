<div class="space-y-5">
    <x-page-header :title="$product->localizedName()" :subtitle="__($product->shariah_structure->label())" :back="route('app.products')"/>
    <x-card>
        <p class="text-sm text-neutral-700">{{ $product->localizedDescription() }}</p>
        @include('livewire.customer.products._structure', ['structure' => $product->shariah_structure->value])
        @php($rep = $v->representativeQuote())
        <x-dl class="mt-4" :cols="3" :items="[
            __('Amount') => \App\Support\Money::format($v->min_amount).' – '.\App\Support\Money::format($v->max_amount),
            __('Tenure') => $v->min_tenure.'–'.$v->max_tenure.' '.($v->isWeekly() ? __('weeks') : __('months')),
            __('Disclosed profit rate') => rtrim(rtrim((string) $v->profit_rate, '0'), '.').'% '.__('p.a.').' ('.__($v->profit_method).')',
            __('From') => \App\Support\Money::format($rep['instalment']).' / '.($v->isWeekly() ? __('week') : __('month')).' · APR '.$rep['apr'].'%',
            __('Down payment') => (float) $v->down_payment_pct.'%',
            __('Minimum income') => \App\Support\Money::format($v->min_income),
            __('Guarantors') => $v->guarantors_required ?: __('Not required'),
            __('Cooling-off') => __(':h hours, cancel free of charge', ['h' => $v->cooling_off_hours]),
            __('Late payment') => __($v->latePaymentDisclosure()),
        ]"/>
    </x-card>

    <x-card :title="__('Financing calculator')" :subtitle="__('Indicative only')">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-input :label="__('Amount to finance (PKR)')" type="number" step="1000" wire:model.live.debounce.400ms="amount"/>
            <div>
                <label class="field-label">{{ __('Tenure') }}: <span class="tabular">{{ $tenure }}</span> {{ $v->isWeekly() ? __('weeks') : __('months') }}</label>
                <input type="range" min="{{ $v->min_tenure }}" max="{{ $v->max_tenure }}" wire:model.live="tenure" class="mt-2 w-full accent-brand-700">
            </div>
        </div>
        @if($quote)
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-stat :label="__('Instalment')" :value="\App\Support\Money::format($quote['instalment'])" :hint="$v->isWeekly() ? __('per week') : __('per month')"/>
                <x-stat :label="__('Total profit')" :value="\App\Support\Money::format($quote['profit_total'])" tone="black"/>
                <x-stat :label="__('Total payable')" :value="\App\Support\Money::format($quote['total_payable'])" tone="black"/>
                <x-stat :label="__('APR (incl. fees)')" :value="$quote['apr'].'%'" tone="amber"/>
            </div>
            @if($quote['charge_lines'])
                <p class="mt-3 text-xs text-neutral-500">{{ __('Charges') }}:
                    @foreach($quote['charge_lines'] as $ch){{ $ch['name'] }} {{ $ch['amount'] !== null ? \App\Support\Money::format($ch['amount']) : ($ch['basis'] ?? '') }}@if(!$loop->last), @endif @endforeach</p>
            @endif
        @endif
        @if($issues)
            <x-alert class="mt-4" :title="__('Before you apply')"><ul class="list-disc space-y-0.5 pl-5">@foreach($issues as $i)<li>{{ $i }}</li>@endforeach</ul></x-alert>
        @endif
        @if($docs->isNotEmpty())
            <p class="mt-4 text-sm font-medium">{{ __('Documents you will need') }}</p>
            <ul class="mt-1 list-disc pl-5 text-sm text-neutral-600">@foreach($docs as $d)<li>{{ app()->getLocale() === 'ur' && $d->documentType->name_ur ? $d->documentType->name_ur : $d->documentType->name }}@if(! $d->is_mandatory) ({{ __('optional') }})@endif</li>@endforeach</ul>
        @endif
        <div class="mt-5">
            @if($customer->kyc_status->value === 'passed')
                @if($quote)
                    <a href="{{ route('app.apply', $product) }}?amount={{ (int) $amount }}&tenure={{ $tenure }}" wire:navigate class="btn-primary">{{ __('Start application') }}</a>
                @else
                    {{-- QA H2: no application from an invalid calculator state --}}
                    <button type="button" class="btn-primary" disabled aria-disabled="true">{{ __('Start application') }}</button>
                    <p class="mt-1 text-xs text-red-600">{{ __('Fix the amount or tenure above to continue.') }}</p>
                @endif
            @else
                <a href="{{ route('app.onboarding') }}" wire:navigate class="btn-secondary">{{ __('Complete verification to apply') }}</a>
            @endif
        </div>
    </x-card>
</div>
