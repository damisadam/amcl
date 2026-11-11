<div wire:poll.30s>
    <x-page-header :title="__('Wallets')" :subtitle="__('Customer and merchant e-money wallets. Balances are customer liabilities held in the safeguarded account (SIMULATED).')"/>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-stat :label="__('Wallets')" :value="number_format($totals['count'])"/>
        <x-stat :label="__('Total wallet liability')" :value="\App\Support\Money::format($totals['balance'])" tone="black" :hint="__('Safeguarding model: :m', ['m' => \Illuminate\Support\Str::headline($model)])"/>
        <x-stat :label="__('Held for pending payouts')" :value="\App\Support\Money::format($totals['held'])" tone="amber"/>
    </div>

    <x-card :padding="false">
        <div class="flex flex-col gap-2 border-b border-neutral-100 p-4 sm:flex-row">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Search wallet no, name, mobile…') }}" class="field-input sm:max-w-xs">
            <select wire:model.live="filterStatus" class="field-input sm:w-44"><option value="">{{ __('All statuses') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterOwner" class="field-input sm:w-44"><option value="">{{ __('All owners') }}</option><option value="customer">{{ __('Customers') }}</option><option value="merchant">{{ __('Merchants') }}</option></select>
        </div>
        <x-table>
            <x-slot:head>
                <x-th sort="wallet_no" :current="$sort" :dir="$dir">{{ __('Wallet') }}</x-th>
                <x-th>{{ __('Owner') }}</x-th>
                <x-th sort="status" :current="$sort" :dir="$dir">{{ __('Status') }}</x-th>
                <x-th sort="balance" :current="$sort" :dir="$dir" align="right">{{ __('Balance') }}</x-th>
                <x-th align="right">{{ __('Held') }}</x-th>
                <x-th align="right">{{ __('Daily limit') }}</x-th>
                <x-th sort="created_at" :current="$sort" :dir="$dir">{{ __('Opened') }}</x-th>
            </x-slot:head>
            @forelse($wallets as $w)
                <tr wire:key="w{{ $w->id }}">
                    <td><a href="{{ route('ops.wallets.show', $w) }}" wire:navigate class="link tabular">{{ $w->wallet_no }}</a></td>
                    <td>{{ $w->title }}<div class="text-xs text-neutral-500">{{ $w->owner instanceof \App\Models\Customer ? __('Customer').' · '.$w->owner->cif_no : __('Merchant') }}</div></td>
                    <td><x-status :value="$w->status"/></td>
                    <td class="text-right"><x-money :amount="$w->balance" tone="auto"/></td>
                    <td class="text-right"><x-money :amount="$w->held_balance"/></td>
                    <td class="text-right"><x-money :amount="$w->daily_limit"/></td>
                    <td class="text-xs text-neutral-500">{{ $w->created_at->format('d M Y') }}</td>
                </tr>
            @empty
                <x-empty :colspan="7" :title="__('No wallets found')" :description="__('Wallets are opened automatically when a customer passes KYC, or for merchants from the vendor screen.')"/>
            @endforelse
        </x-table>
        {{ $wallets->links() }}
    </x-card>
</div>
