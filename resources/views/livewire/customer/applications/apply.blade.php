<div class="space-y-5">
    <x-page-header :title="__('Apply: :p', ['p' => $product->localizedName()])" :subtitle="$app ? __('Draft :no – saved automatically when you continue', ['no' => $app->application_no]) : __('Takes about 5 minutes')" :back="route('app.products.show', $product)"/>

    @php($steps = array_filter(['terms' => __('Financing'), 'guarantors' => $v->guarantors_required ? __('Guarantors') : null, 'documents' => __('Documents'), 'review' => __('Review & submit')]))
    <ol class="flex gap-1 overflow-x-auto text-xs">
        @foreach($steps as $k => $l)
            <li><button type="button" wire:click="goTo('{{ $k }}')" @disabled(! $app && $k !== 'terms') @class(['rounded-full px-3 py-1.5 font-medium', 'bg-brand-700 text-white' => $step === $k, 'bg-white border border-neutral-200 text-neutral-600' => $step !== $k])>{{ $loop->iteration }}. {{ $l }}</button></li>
        @endforeach
    </ol>

    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror

    @if($step === 'terms')
        <x-card :title="__('Financing details')">
            <form wire:submit="saveTerms" class="space-y-4">
                @if($v->requires_vendor)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select :label="__('Vendor / dealer')" wire:model.live="vendor_id" :placeholder="__('Select vendor')" :options="$vendors->mapWithKeys(fn ($x) => [$x->id => $x->business_name.' – '.$x->city])->all()" required/>
                        <x-select :label="__('Item')" wire:model.live="vendor_item_id" :placeholder="$items->isEmpty() ? __('Select a vendor first') : __('Select item (or enter details)')" :options="$items->mapWithKeys(fn ($i) => [$i->id => $i->name.' – '.\App\Support\Money::format($i->price)])->all()"/>
                        <x-input :label="__('Asset description')" wire:model="asset_description" required/>
                        <x-input :label="__('Asset price (PKR)')" type="number" step="1" wire:model.live.debounce.500ms="asset_price" required/>
                        <x-input :label="__('Down payment (PKR)')" type="number" step="1" wire:model.live.debounce.500ms="down_payment" :hint="__('Minimum :p% of price, paid to the vendor.', ['p' => (float) $v->down_payment_pct])" required/>
                        <div><p class="field-label">{{ __('Amount to finance') }}</p><p class="text-xl font-bold tabular">{{ \App\Support\Money::format($financed) }}</p></div>
                    </div>
                    <p class="text-xs text-neutral-500">{{ __('Under Murabaha the institution pays the vendor directly; no cash is disbursed to you.') }}</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input :label="__('Amount (PKR)')" type="number" step="1000" wire:model.live.debounce.500ms="requested_amount" :hint="\App\Support\Money::format($v->min_amount).' – '.\App\Support\Money::format($v->max_amount)" required/>
                        <x-input :label="__('Description (optional)')" wire:model="asset_description"/>
                    </div>
                @endif
                <div>
                    <label class="field-label">{{ __('Tenure') }}: <span class="tabular">{{ $tenure }}</span> {{ $v->isWeekly() ? __('weeks') : __('months') }}</label>
                    <input type="range" min="{{ $v->min_tenure }}" max="{{ $v->max_tenure }}" wire:model.live="tenure" class="w-full accent-brand-700">
                </div>
                <x-textarea :label="__('Purpose of financing')" wire:model="purpose" rows="2" required/>
                @if($quote)
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <x-stat :label="__('Instalment')" :value="\App\Support\Money::format($quote['instalment'])"/>
                        <x-stat :label="__('Total profit')" :value="\App\Support\Money::format($quote['profit_total'])" tone="black"/>
                        <x-stat :label="__('Total payable')" :value="\App\Support\Money::format($quote['total_payable'])" tone="black"/>
                        <x-stat :label="__('APR')" :value="$quote['apr'].'%'" tone="amber"/>
                    </div>
                @endif
                <button class="btn-primary" wire:loading.attr="disabled">{{ __('Save & continue') }}</button>
            </form>
        </x-card>

    @elseif($step === 'guarantors')
        <x-card :title="__('Guarantors')" :subtitle="__('This product needs :n guarantor(s). They will be verified and sign electronically.', ['n' => $v->guarantors_required])">
            @foreach($guarantors as $g)
                <div class="mb-2 flex items-center justify-between rounded-lg border border-neutral-200 p-3 text-sm">
                    <div><p class="font-medium">{{ $g->name }} <span class="text-xs text-neutral-500">({{ $g->relation }})</span></p><p class="text-xs text-neutral-500">{{ substr($g->cnic, 0, 5) }}-*******-{{ substr($g->cnic, -1) }} · {{ $g->mobile }}</p></div>
                    <button wire:click="removeGuarantor({{ $g->id }})" wire:confirm="{{ __('Remove this guarantor?') }}" class="btn-ghost btn-sm text-red-600">{{ __('Remove') }}</button>
                </div>
            @endforeach
            <form wire:submit="addGuarantor" class="mt-4 grid gap-4 border-t border-neutral-100 pt-4 sm:grid-cols-2">
                <x-input :label="__('Name')" wire:model="guarantor.name" required/>
                <x-input :label="__('CNIC')" wire:model="guarantor.cnic" placeholder="35202-1234567-1" required/>
                <x-input :label="__('Mobile')" wire:model="guarantor.mobile" required/>
                <x-input :label="__('Relation to you')" wire:model="guarantor.relation" required/>
                <x-input :label="__('Occupation')" wire:model="guarantor.occupation"/>
                <x-input :label="__('Monthly income (PKR)')" type="number" wire:model="guarantor.monthly_income"/>
                <x-textarea class="sm:col-span-2" :label="__('Address')" wire:model="guarantor.address" rows="2"/>
                <x-checkbox class="sm:col-span-2" wire:model="guarantor.consent" :label="__('The guarantor has consented to identity verification and a credit bureau (Tasdeeq) check.')"/>
                @error('guarantor.consent')<p class="field-error sm:col-span-2">{{ $message }}</p>@enderror
                <div class="sm:col-span-2 flex gap-2"><button class="btn-secondary">{{ __('Add guarantor') }}</button>
                    <button type="button" wire:click="goTo('documents')" class="btn-primary" @disabled($guarantors->count() < $v->guarantors_required)>{{ __('Continue') }}</button></div>
            </form>
        </x-card>

    @elseif($step === 'documents')
        <x-card :title="__('Required documents')" :subtitle="__('JPG, PNG or PDF up to 5 MB. Your CNIC from onboarding is reused.')">
            <ul class="divide-y divide-neutral-100">
                @forelse($requiredDocs as $r)
                    @php($have = $haveDocs->get($r->document_type_id))
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div><p class="text-sm font-medium">{{ app()->getLocale() === 'ur' && $r->documentType->name_ur ? $r->documentType->name_ur : $r->documentType->name }} @if(! $r->is_mandatory)<span class="text-xs text-neutral-500">({{ __('optional') }})</span>@endif</p>
                            @if($have)<p class="text-xs text-brand-700">✓ {{ __('Uploaded') }} · v{{ $have->version }} · <x-status :value="$have->status"/></p>@else<p class="text-xs text-amber-700">{{ __('Not uploaded') }}</p>@endif</div>
                        <div class="flex items-center gap-2">
                            <input type="file" wire:model="uploads.{{ $r->document_type_id }}" accept="image/*,application/pdf" class="block w-full max-w-[14rem] text-xs file:mr-2 file:rounded-md file:border-0 file:bg-neutral-100 file:px-2 file:py-1">
                            <button wire:click="saveDocument({{ $r->document_type_id }})" class="btn-secondary btn-sm" wire:loading.attr="disabled">{{ $have ? __('Replace') : __('Upload') }}</button>
                        </div>
                        @error('uploads.'.$r->document_type_id)<p class="field-error">{{ $message }}</p>@enderror
                    </li>
                @empty
                    <li class="py-3 text-sm text-neutral-500">{{ __('No additional documents are required for this product.') }}</li>
                @endforelse
            </ul>
            <button wire:click="goTo('review')" class="btn-primary mt-4">{{ __('Continue to review') }}</button>
        </x-card>

    @else
        <x-card :title="__('Review & submit')">
            @if($app)
            <x-dl :items="[
                __('Application') => $app->application_no,
                __('Product') => $product->localizedName(),
                __('Amount to finance') => \App\Support\Money::format($app->requested_amount),
                __('Tenure') => $app->tenure.' '.($v->isWeekly() ? __('weeks') : __('months')),
                __('Indicative instalment') => \App\Support\Money::format($app->indicative_instalment),
                __('Vendor') => $app->vendor?->business_name,
                __('Asset') => $app->asset_description,
                __('Guarantors') => $guarantors->pluck('name')->join(', '),
            ]"/>
            @endif
            @if($checklist)
                <x-alert class="mt-4" :title="__('Please complete the following before submitting')"><ul class="list-disc space-y-1 pl-5">@foreach($checklist as $c)<li>{{ $c }}</li>@endforeach</ul></x-alert>
            @else
                <x-alert tone="green" class="mt-4">{{ __('Everything is in place. By submitting you confirm the information is true and complete.') }}</x-alert>
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button wire:click="submit" class="btn-primary" @disabled($checklist) wire:loading.attr="disabled">{{ __('Submit application') }}</button>
                <a href="{{ route('app.applications') }}" wire:navigate class="btn-secondary">{{ __('Save as draft & exit') }}</a>
            </div>
        </x-card>
    @endif
</div>
