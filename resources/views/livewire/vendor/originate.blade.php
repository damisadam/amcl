<div>
    <x-page-header :title="__('New customer financing request')" :subtitle="__('For a customer at your premises who wants to buy your item on Shariah-compliant financing. The institution verifies identity and decides on credit.')"/>
    @if(! $vendor->isActive())
        <x-alert tone="amber">{{ __('Your vendor account must be approved by the institution before you can submit requests.') }}</x-alert>
    @elseif($result)
        <x-card>
            <div class="text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full {{ $result['submitted'] ? 'bg-brand-100 text-brand-700' : 'bg-amber-100 text-amber-700' }}"><x-icon :name="$result['submitted'] ? 'check' : 'clipboard'"/></div>
                <h2 class="mt-3 text-lg font-semibold">{{ $result['submitted'] ? __('Request submitted') : __('Draft saved') }} – {{ $result['no'] }}</h2>
                <p class="mt-1 text-sm text-neutral-600">{{ __($result['message']) }}</p>
                <div class="mt-4 flex justify-center gap-2"><a href="{{ route('vendor.orders.show', $result['id']) }}" wire:navigate class="btn-primary">{{ __('View order') }}</a>
                    <button wire:click="startAnother" class="btn-secondary">{{ __('Start another') }}</button></div>
            </div>
        </x-card>
    @else
        <form wire:submit="submit" class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-card :title="__('1. Customer details')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input :label="__('Full name (as on CNIC)')" wire:model="full_name" required/>
                        <x-input :label="__('CNIC')" wire:model="cnic" placeholder="35202-1234567-1" required/>
                        <x-input :label="__('Mobile number')" wire:model="mobile" placeholder="03001234567" inputmode="tel" required/>
                        <x-input :label="__('Date of birth')" type="date" wire:model="date_of_birth" required/>
                        <x-input :label="__('Address')" wire:model="address" class="sm:col-span-2" required/>
                        <x-input :label="__('City')" wire:model="city" required/>
                        <x-select :label="__('Employment')" wire:model="employment_type" :options="['salaried' => __('Salaried'), 'self_employed' => __('Self-employed'), 'business' => __('Business owner')]"/>
                        <x-input :label="__('Monthly income (PKR)')" type="number" wire:model="monthly_income" required/>
                    </div>
                </x-card>
                <x-card :title="__('2. Item & financing')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select :label="__('Your item')" wire:model.live="vendor_item_id" :placeholder="__('Select item')" :options="$items->mapWithKeys(fn ($i) => [$i->id => $i->name.' – '.\App\Support\Money::format($i->price)])->all()" required/>
                        <x-select :label="__('Financing product')" wire:model.live="product_id" :placeholder="__('Select product')" :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->shariah_structure->label().')'])->all()" required/>
                        <x-input :label="__('Tenure (months)')" type="number" wire:model.live.debounce.400ms="tenure" :hint="$product ? __('Allowed: :a–:b', ['a' => $product->currentVersion->min_tenure, 'b' => $product->currentVersion->max_tenure]) : null" required/>
                        <x-input :label="__('Down payment (PKR, optional)')" type="number" wire:model.live.debounce.400ms="down_payment" :hint="$product ? __('Minimum :p%', ['p' => $product->currentVersion->down_payment_pct]) : null"/>
                    </div>
                    @if($items->isEmpty())<x-alert class="mt-3">{{ __('You have no active items. Add items under Products & items first.') }}</x-alert>@endif
                </x-card>
                <x-card :title="__('3. Documents')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="field-label">{{ __('CNIC front (photo or PDF)') }} <span class="text-red-600">*</span></label>
                            <input type="file" wire:model="cnic_front" accept="image/*,application/pdf" capture="environment" class="text-sm">@error('cnic_front')<p class="field-error">{{ $message }}</p>@enderror</div>
                        <div><x-select :label="__('Income proof type')" wire:model="income_doc_type" :options="['SALARY_SLIP' => __('Salary slip'), 'BANK_STATEMENT' => __('Bank statement'), 'BUSINESS_PROOF' => __('Business proof')]"/>
                            <input type="file" wire:model="income_proof" accept="image/*,application/pdf" class="mt-2 text-sm">@error('income_proof')<p class="field-error">{{ $message }}</p>@enderror</div>
                    </div>
                </x-card>
                <x-card :title="__('4. Customer consent (read to the customer)')">
                    <x-alert tone="blue" class="mb-3">{{ __('These consents are recorded as captured at your premises, with your name, time and device.') }}</x-alert>
                    <div class="space-y-2">
                        @foreach($consents as $type => $v)
                            <x-checkbox wire:model="consents.{{ $type }}" :label="__('consents.'.$type)"/>
                            @error('consents.'.$type)<p class="field-error">{{ $message }}</p>@enderror
                        @endforeach
                    </div>
                </x-card>
                @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
            </div>
            <div>
                <x-card :title="__('Indicative terms')" class="sticky top-24">
                    @if($quote)
                        <x-dl :cols="1" :items="[__('Down payment') => \App\Support\Money::format($quote['down']), __('Financed amount') => \App\Support\Money::format($quote['principal']),
                            __('Monthly instalment') => \App\Support\Money::format($quote['instalment']), __('Total payable') => \App\Support\Money::format($quote['total_payable']),
                            __('Profit rate') => $quote['profit_rate'].'% p.a.', __('APR') => $quote['apr'].'%']"/>
                        <p class="mt-3 text-xs text-neutral-500">{{ __('Indicative only. The institution issues the final Key Fact Statement to the customer.') }}</p>
                    @else
                        <p class="text-sm text-neutral-500">{{ __('Select an item, product and tenure to see indicative terms.') }}</p>
                    @endif
                    <button class="btn-primary mt-4 w-full" wire:loading.attr="disabled" wire:target="submit,cnic_front,income_proof">{{ __('Create request') }}</button>
                </x-card>
            </div>
        </form>
    @endif
</div>
