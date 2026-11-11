<div>
    <x-page-header :title="$app->application_no" :subtitle="$app->customer->full_name.' · '.$app->asset_description" :back="route('vendor.orders')">
        <x-slot:actions><x-badge :tone="\App\Livewire\Vendor\Concerns\VendorOrderStatus::tone($app->status)">{{ \App\Livewire\Vendor\Concerns\VendorOrderStatus::label($app->status) }}</x-badge></x-slot:actions>
    </x-page-header>
    @if($app->status->value === 'draft')
        <x-alert class="mb-4" :tone="$kycPending ? 'amber' : 'blue'">
            {{ $kycPending ? __(\App\Services\Lending\VendorOriginationService::KYC_PENDING_MESSAGE) : __('This request is a draft. Submit it when the documents are complete.') }}
        </x-alert>
    @endif
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2" :title="__('Order')">
            <x-dl :items="[
                __('Customer') => $app->customer->full_name, __('CNIC') => $app->customer->maskedCnic(), __('Product') => $app->product->name,
                __('Item') => $app->asset_description, __('Asset price') => \App\Support\Money::format($app->asset_price), __('Down payment') => \App\Support\Money::format($app->down_payment),
                __('Financed amount') => \App\Support\Money::format($app->requested_amount), __('Tenure') => $app->tenure.' '.__('months'),
                __('Submitted') => $app->submitted_at?->format('d M Y H:i'), __('Disbursed') => $app->disbursed_at?->format('d M Y H:i'),
            ]"/>
            @if($app->status->value === 'draft')<div class="mt-4 flex justify-end"><button wire:click="submit" class="btn-primary">{{ __('Submit to institution') }}</button></div>@endif
        </x-card>
        <x-card :title="__('Status timeline')">
            <ol class="space-y-3 text-sm">
                @forelse($app->histories as $h)
                    <li class="border-l-2 border-brand-600 pl-3"><p class="font-medium">{{ $h->to_status?->label() }}</p><p class="text-xs text-neutral-500">{{ $h->created_at?->format('d M Y H:i') }}</p></li>
                @empty
                    <li class="text-neutral-500">{{ __('No updates yet.') }}</li>
                @endforelse
            </ol>
        </x-card>
    </div>
    <x-card class="mt-6" :title="__('Payout to your account')" :padding="false">
        <x-table>
            <x-slot:head><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Net amount') }}</x-th><x-th>{{ __('Completed') }}</x-th><x-th>{{ __('Note') }}</x-th></x-slot:head>
            @forelse($app->disbursements as $d)
                <tr><td>{{ $d->reference }}</td><td><x-status :value="$d->status"/></td><td class="text-right"><x-money :amount="$d->net_amount"/></td>
                    <td class="text-xs">{{ $d->completed_at?->format('d M Y H:i') }}</td><td class="text-xs text-neutral-500">{{ $d->failure_reason ?? __('SIMULATED payout') }}</td></tr>
            @empty
                <x-empty colspan="5" :title="__('Not disbursed yet')" :description="__('The institution pays you directly after approval, signing and the cooling-off period.')"/>
            @endforelse
        </x-table>
    </x-card>
    <x-card class="mt-6" :title="__('Documents you uploaded')">
        <ul class="divide-y divide-neutral-100 text-sm">
            @forelse($documents as $doc)
                <li class="flex justify-between py-2"><span>{{ $doc->type?->name ?? $doc->title }}</span><a href="{{ route('documents.show', $doc) }}" target="_blank" class="link">{{ __('View') }}</a></li>
            @empty
                <li class="py-2 text-neutral-500">{{ __('None') }}</li>
            @endforelse
        </ul>
        @if($app->status->value === 'draft')
            <form wire:submit="uploadDocument" class="mt-4 flex flex-wrap items-end gap-3">
                <x-select :label="__('Document type')" wire:model="uploadType" :options="['SALARY_SLIP' => __('Salary slip'), 'BANK_STATEMENT' => __('Bank statement'), 'BUSINESS_PROOF' => __('Business proof'), 'UTILITY_BILL' => __('Utility bill'), 'QUOTATION' => __('Quotation / invoice')]"/>
                <div><input type="file" wire:model="docFile" accept="image/*,application/pdf" class="text-sm">@error('docFile')<p class="field-error">{{ $message }}</p>@enderror</div>
                <button class="btn-secondary" wire:loading.attr="disabled" wire:target="docFile,uploadDocument">{{ __('Upload') }}</button>
            </form>
        @endif
    </x-card>
</div>
