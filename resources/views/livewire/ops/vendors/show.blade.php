<div>
    <x-page-header :title="$v->business_name" :subtitle="$v->code.' · '.(\App\Livewire\Ops\Vendors\VendorCategories::ALL[$v->category] ?? $v->category).' · '.ucfirst($v->kind).' supplier'" :back="route('ops.vendors.index')">
        <x-slot:actions>
            <x-status :value="$v->status"/>
            @if($v->status->value === 'pending' && $canApprove)
                <button wire:click="approve" wire:confirm="{{ __('Approve this vendor? It will be able to receive disbursements.') }}" class="btn-primary">{{ __('Approve') }}</button>
                <button wire:click="openStatus('rejected')" class="btn-danger">{{ __('Reject') }}</button>
            @endif
            @if($canManage)
                @foreach(array_diff($transitions, ['rejected']) as $to)
                    @if(! ($v->status->value === 'pending' && $to === 'active'))
                        <button wire:click="openStatus('{{ $to }}')" class="{{ $to === 'blacklisted' ? 'btn-danger' : 'btn-secondary' }}">{{ $to === 'active' ? __('Reactivate') : \Illuminate\Support\Str::headline($to === 'inactive' ? 'deactivate' : 'blacklist') }}</button>
                    @endif
                @endforeach
            @endif
        </x-slot:actions>
    </x-page-header>
    @if($v->rejection_reason && $v->status->value === 'rejected')<x-alert tone="red" class="mb-4">{{ __('Rejected:') }} {{ $v->rejection_reason }}</x-alert>@endif
    @if($v->source === 'web_request' && $v->status->value === 'pending')<x-alert class="mb-4">{{ __('Onboarding request submitted via the website. Verify documents and bank details before approval.') }}</x-alert>@endif

    <x-tabs :tabs="['overview' => __('Overview'), 'items' => __('Products & items'), 'documents' => __('Documents'), 'users' => __('Portal users'), 'cases' => __('Financed cases'), 'payments' => __('Payments')]" :active="$tab"/>

    @if($tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :title="__('Vendor profile')" class="lg:col-span-2">
                <x-slot:actions>@if($canManage && ! $editing)<button wire:click="$set('editing', true)" class="btn-secondary btn-sm">{{ __('Edit') }}</button>@endif</x-slot:actions>
                @if($editing)
                    <form wire:submit="save">@include('livewire.ops.vendors._form')
                        @error('action')<p class="field-error mt-2">{{ $message }}</p>@enderror
                        <div class="mt-4 flex justify-end gap-2"><button type="button" wire:click="$set('editing', false)" class="btn-secondary">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div></form>
                @else
                    <x-dl :cols="3" :items="[
                        __('Owner') => $v->owner_name, __('Owner CNIC') => $v->cnic ? substr($v->cnic, 0, 5).'-*******-'.substr($v->cnic, -1) : null, __('NTN') => $v->ntn,
                        __('Registration') => $v->registration_no, __('Phone') => $v->phone, __('Email') => $v->email, __('City') => $v->city, __('Address') => $v->address,
                        __('Bank') => $v->bank_name, __('IBAN') => $v->iban, __('Account title') => $v->account_title, __('Payment method') => \Illuminate\Support\Str::headline($v->preferred_payment_method),
                        __('Markup share') => $v->markup_share_pct ? (float) $v->markup_share_pct.'%' : null, __('Approved by') => $v->approved_at ? \App\Models\User::find($v->approved_by)?->name.' · '.$v->approved_at->format('d M Y') : null,
                        __('Source') => $v->source === 'web_request' ? 'Website request' : 'Internal',
                    ]"/>
                    @if($v->pricing_notes)<p class="mt-4 text-sm text-neutral-600">{{ $v->pricing_notes }}</p>@endif
                @endif
            </x-card>
            <x-card :title="__('Merchant wallet')">
                @if(! $v->is_merchant)
                    <p class="text-sm text-neutral-500">{{ __('Not a wallet merchant. Enable "accepts wallet QR payments" and set a merchant code to accept customer QR payments.') }}</p>
                @elseif($wallet)
                    <x-dl :cols="1" :items="[__('Merchant code') => $v->merchant_code, __('Wallet') => $wallet->wallet_no, __('Status') => $wallet->status->label(), __('Balance') => \App\Support\Money::format($wallet->balance)]"/>
                    <p class="mt-3 text-xs text-neutral-500">{{ __('Customer QR payments to this merchant move only the wallet ledger (no bank movement).') }}</p>
                @else
                    <p class="text-sm text-neutral-600">{{ __('Merchant code :c. Without a wallet, QR payments are paid out to the merchant\'s bank by Raast (external).', ['c' => $v->merchant_code]) }}</p>
                    @if($canManage && $v->isActive())<button wire:click="enableMerchant" class="btn-primary mt-3 w-full">{{ __('Open merchant wallet') }}</button>@endif
                @endif
            </x-card>
        </div>
    @elseif($tab === 'items')
        @if(auth()->user()->hasPermission('vendors.manage'))
            <div class="mb-4"><livewire:ops.vendor-price-approvals :vendor-id="$vendor->id" :key="'price-approvals-'.$vendor->id"/></div>
        @endif
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :padding="false" class="lg:col-span-2">
                <x-table><x-slot:head><x-th>{{ __('Item') }}</x-th><x-th>{{ __('SKU') }}</x-th><x-th>{{ __('Category') }}</x-th><x-th align="right">{{ __('Price') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                    @forelse($items as $i)
                        <tr wire:key="it{{ $i->id }}"><td class="font-medium">{{ $i->name }}</td><td class="text-xs">{{ $i->sku }}</td><td class="text-xs">{{ $i->product_category }}</td>
                            <td class="text-right"><x-money :amount="$i->price"/></td><td>@if($i->is_active)<x-badge tone="green">{{ __('Active') }}</x-badge>@else<x-badge>{{ __('Inactive') }}</x-badge>@endif</td>
                            <td class="text-right">@if($canManage)<button wire:click="editItem({{ $i->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button>@endif</td></tr>
                    @empty<x-empty :colspan="6" :title="__('No items')" :description="__('Add the goods this vendor supplies so customers can pick them when applying.')"/>@endforelse
                </x-table>
            </x-card>
            @if($canManage)
                <x-card :title="$item['id'] ? __('Edit item') : __('Add item')">
                    <form wire:submit="saveItem" class="space-y-3">
                        <x-input :label="__('Name')" wire:model="item.name"/><x-input :label="__('SKU')" wire:model="item.sku"/>
                        <x-select :label="__('Product category')" wire:model="item.product_category" placeholder="—" :options="\App\Enums\ProductCategory::options()"/>
                        <x-input :label="__('Price (PKR)')" type="number" step="0.01" wire:model="item.price"/>
                        <x-checkbox wire:model="item.is_active" :label="__('Active')"/>
                        <button class="btn-primary w-full">{{ __('Save item') }}</button>
                    </form>
                </x-card>
            @endif
        </div>
    @elseif($tab === 'documents')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :padding="false" class="lg:col-span-2">
                <x-table><x-slot:head><x-th>{{ __('Document') }}</x-th><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Expiry') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                    @forelse($documents as $d)
                        <tr wire:key="doc{{ $d->id }}"><td><a href="{{ route('documents.show', $d) }}" target="_blank" class="link">{{ $d->title }}</a><div class="text-xs text-neutral-500">{{ $d->original_name }}</div></td>
                            <td>v{{ $d->version }}</td>
                            <td class="text-xs">@if($d->expires_on){{ $d->expires_on->format('d M Y') }} @if($d->isExpired())<x-badge tone="red">{{ __('Expired') }}</x-badge>@elseif($d->expiresSoon())<x-badge tone="amber">{{ __('Expiring') }}</x-badge>@endif @else — @endif</td>
                            <td><x-status :value="$d->status"/>@if($d->rejection_reason)<div class="text-xs text-red-600">{{ $d->rejection_reason }}</div>@endif</td>
                            <td class="whitespace-nowrap text-right">@if($d->status === 'pending')@can('documents.verify')
                                <button wire:click="verifyDoc({{ $d->id }}, true)" class="btn-ghost btn-sm text-brand-700">{{ __('Verify') }}</button>
                                <button wire:click="verifyDoc({{ $d->id }}, false)" class="btn-ghost btn-sm text-red-600">{{ __('Reject') }}</button>@endcan @endif</td></tr>
                    @empty<x-empty :colspan="5" :title="__('No documents uploaded')"/>@endforelse
                </x-table>
                @if($rejectDocId)<div class="border-t border-neutral-100 p-3"><x-input :label="__('Rejection reason')" wire:model="rejectReason"/></div>@endif
            </x-card>
            <x-card :title="__('Upload document')">
                <form wire:submit="uploadDoc" class="space-y-3">
                    <x-select :label="__('Type')" wire:model="uploadType" :options="['VENDOR_REG' => 'Registration / licence', 'VENDOR_BANK' => 'Bank letter']"/>
                    <div><label class="field-label">{{ __('File (JPG, PNG, PDF ≤ 5 MB)') }}</label><input type="file" wire:model="docFile" class="block w-full text-sm">@error('docFile')<p class="field-error">{{ $message }}</p>@enderror</div>
                    <x-input :label="__('Expiry date (optional)')" type="date" wire:model="uploadExpiry"/>
                    <button class="btn-primary w-full" wire:loading.attr="disabled" wire:target="docFile,uploadDoc">{{ __('Upload') }}</button>
                </form>
            </x-card>
        </div>
    @elseif($tab === 'users')
        <div class="grid gap-4 lg:grid-cols-3">
            <x-card :padding="false" class="lg:col-span-2">
                <x-table><x-slot:head><x-th>{{ __('Name') }}</x-th><x-th>{{ __('Email') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Last login') }}</x-th></x-slot:head>
                    @forelse($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td><x-status :value="$u->status"/></td><td class="text-xs">{{ $u->last_login_at?->diffForHumans() ?? __('Never') }}</td></tr>
                    @empty<x-empty :colspan="4" :title="__('No portal users')"/>@endforelse
                </x-table>
            </x-card>
            @if($canManage)
                <x-card :title="__('Grant vendor portal access')">
                    @if($tempPassword)<x-alert tone="green" class="mb-3" :title="__('Temporary password')"><span class="font-mono">{{ $tempPassword }}</span><br><span class="text-xs">{{ __('Share securely. The user must change it at first sign-in (organisation code: :c).', ['c' => $currentTenant->code ?? '']) }}</span></x-alert>@endif
                    <form wire:submit="createUser" class="space-y-3">
                        <x-input :label="__('Name')" wire:model="userName"/><x-input :label="__('Email')" type="email" wire:model="userEmail"/>
                        @error('action')<p class="field-error">{{ $message }}</p>@enderror
                        <button class="btn-primary w-full">{{ __('Create user') }}</button>
                    </form>
                </x-card>
            @endif
        </div>
    @elseif($tab === 'cases')
        <x-card :padding="false">
            <x-table><x-slot:head><x-th>{{ __('Application') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Product') }}</x-th><x-th>{{ __('Asset') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Channel') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                @forelse($cases as $a)
                    <tr><td>@if(Route::has('ops.applications.show'))<a class="link" href="{{ route('ops.applications.show', $a) }}" wire:navigate>{{ $a->application_no }}</a>@else{{ $a->application_no }}@endif</td>
                        <td>{{ $a->customer->full_name }}</td><td>{{ $a->product->name }}</td><td class="text-xs">{{ $a->asset_description }}</td><td class="text-right"><x-money :amount="$a->financedAmount()" :currency="false"/></td>
                        <td class="text-xs">{{ ucfirst($a->channel) }}</td><td><x-status :value="$a->status"/></td></tr>
                @empty<x-empty :colspan="7" :title="__('No financing cases linked to this vendor')"/>@endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'payments')
        <x-card :title="__('Financing disbursements to vendor')" :padding="false" class="mb-4">
            <x-table><x-slot:head><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Application') }}</x-th><x-th align="right">{{ __('Net paid') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Date') }}</x-th></x-slot:head>
                @forelse($disbursements as $d)<tr><td>{{ $d->reference }}</td><td>{{ $d->application->application_no }}</td><td class="text-right"><x-money :amount="$d->net_amount"/></td><td><x-status :value="$d->status"/></td><td class="text-xs">{{ $d->created_at->format('d M Y') }}</td></tr>
                @empty<x-empty :colspan="5" :title="__('None')"/>@endforelse
            </x-table>
        </x-card>
        <x-card :title="__('Payables payments (bills / advances)')" :padding="false">
            <x-table><x-slot:head><x-th>{{ __('Payment') }}</x-th><x-th>{{ __('Bill') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Method') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Paid') }}</x-th></x-slot:head>
                @forelse($payments as $p)<tr><td>{{ $p->payment_no }}@if($p->is_advance) <x-badge tone="blue">{{ __('Advance') }}</x-badge>@endif</td><td class="text-xs">{{ $p->bill?->bill_no }}</td><td class="text-right"><x-money :amount="$p->net_amount"/></td>
                    <td class="text-xs">{{ ucfirst($p->method) }}</td><td><x-status :value="$p->status"/></td><td class="text-xs">{{ $p->paid_at?->format('d M Y') }}</td></tr>
                @empty<x-empty :colspan="6" :title="__('None')" :description="__('Vendor bills and payments are managed in Finance › Payables.')"/>@endforelse
            </x-table>
        </x-card>
    @endif

    <x-modal show="showStatus" :title="__('Change vendor status')">
        <p class="mb-3 text-sm">{{ __('New status:') }} <strong>{{ \Illuminate\Support\Str::headline($statusTo) }}</strong>. {{ __('Vendor portal users are suspended when a vendor is not active.') }}</p>
        <x-textarea :label="__('Reason')" wire:model="statusReason" required/>
        @error('action')<p class="field-error mt-2">{{ $message }}</p>@enderror
        <x-slot:footer><button class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary" wire:click="changeStatus">{{ __('Confirm') }}</button></x-slot:footer>
    </x-modal>
</div>
