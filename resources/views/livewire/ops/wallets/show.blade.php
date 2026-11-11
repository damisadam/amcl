<div>
    <x-page-header :title="$wallet->wallet_no.' · '.$wallet->title" :back="route('ops.wallets.index')"
        :subtitle="($wallet->owner instanceof \App\Models\Customer ? __('Customer wallet').' · '.$wallet->owner->cif_no : __('Merchant wallet')).' · '.__('Mobile').' '.$wallet->mobile">
        <x-slot:actions>
            <x-status :value="$wallet->status"/>
            @if($canManage)
                @foreach($transitions as $t)
                    <button wire:click="openStatus('{{ $t->value }}')" @class(['btn-sm', 'btn-danger' => in_array($t->value, ['suspended','closed']), 'btn-secondary' => ! in_array($t->value, ['suspended','closed'])])>{{ $t === \App\Enums\WalletStatus::Active ? __('Activate') : __('Mark :s', ['s' => strtolower($t->label())]) }}</button>
                @endforeach
                <button wire:click="$set('showLimits', true)" class="btn-dark btn-sm">{{ __('Edit limits') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-alert tone="blue" class="mb-4">{{ __('SIMULATED: wallet balances are customer liabilities (GL 2110) backed by the tenant\'s safeguarded bank account (GL 1140). No real e-money is held in this demo.') }}</x-alert>
    @if($unmet)<x-alert class="mb-4" :title="__('Activation prerequisites not met')">{{ implode(' · ', $unmet) }}</x-alert>@endif
    @if($wallet->status_reason)<p class="mb-4 text-xs text-neutral-500">{{ __('Last status reason') }}: {{ $wallet->status_reason }}</p>@endif

    <div class="mb-4 grid gap-3 sm:grid-cols-4">
        <x-stat :label="__('Balance')" :value="\App\Support\Money::format($wallet->balance)" tone="black"/>
        <x-stat :label="__('Held')" :value="\App\Support\Money::format($wallet->held_balance)" tone="amber"/>
        <x-stat :label="__('Available')" :value="\App\Support\Money::format($wallet->availableBalance())"/>
        <x-stat :label="__('Ledger integrity')" :value="$integrity['ok'] ? __('OK') : __('MISMATCH')" :tone="$integrity['ok'] ? 'green' : 'red'"
            :hint="__('Ledger').' '.\App\Support\Money::format($integrity['ledger']).' / '.__('held').' '.\App\Support\Money::format($integrity['held'])"/>
    </div>

    <x-card class="mb-4" :title="__('Limits')">
        <x-dl :cols="3" :items="[
            __('Per transaction') => \App\Support\Money::format($wallet->per_transaction_limit),
            __('Daily (used today)') => \App\Support\Money::format($wallet->daily_limit).' ('.\App\Support\Money::format($todayDebits).')',
            __('Monthly') => \App\Support\Money::format($wallet->monthly_limit),
            __('Maximum balance') => \App\Support\Money::format($wallet->max_balance),
            __('PIN set') => $wallet->pin_hash ? __('Yes') : __('No'),
            __('Activated') => $wallet->activated_at?->format('d M Y H:i'),
        ]"/>
    </x-card>

    <x-tabs :tabs="['transactions' => __('Transactions'), 'ledger' => __('Ledger entries')]" :active="$tab"/>
    @if($tab === 'transactions')
        <x-card :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Type') }}</x-th><x-th>{{ __('Description') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Date') }}</x-th></x-slot:head>
                @forelse($transactions as $t)
                    <tr wire:key="t{{ $t->id }}">
                        <td class="tabular text-xs">{{ $t->reference }}@if($t->is_external)<x-badge tone="blue" class="ml-1">{{ __('external') }}</x-badge>@endif</td>
                        <td>{{ $t->type->label() }}</td>
                        <td class="max-w-xs truncate text-xs">{{ $t->description }}</td>
                        <td><x-status :value="$t->status"/></td>
                        <td @class(['text-right tabular', 'text-brand-700' => $t->isCredit(), 'text-red-600' => ! $t->isCredit()])>{{ $t->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($t->amount, false) }}</td>
                        <td class="text-xs text-neutral-500">{{ $t->created_at->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <x-empty :colspan="6" :title="__('No transactions yet')"/>
                @endforelse
            </x-table>
            {{ $transactions->links() }}
        </x-card>
    @else
        <x-card :padding="false" :title="__('Immutable ledger entries')" :subtitle="__('Every balance movement with before/after balances. Holds reserve funds for pending payouts.')">
            <x-table>
                <x-slot:head><x-th>#</x-th><x-th>{{ __('Transaction') }}</x-th><x-th>{{ __('Entry') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th align="right">{{ __('Before') }}</x-th><x-th align="right">{{ __('After') }}</x-th><x-th align="right">{{ __('Held after') }}</x-th><x-th>{{ __('At') }}</x-th></x-slot:head>
                @forelse($entries as $e)
                    <tr wire:key="e{{ $e->id }}">
                        <td class="text-xs text-neutral-500">{{ $e->id }}</td>
                        <td class="tabular text-xs">{{ $e->transaction?->reference }}</td>
                        <td><x-status :value="$e->entry_type"/></td>
                        <td class="text-right"><x-money :amount="$e->amount" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$e->balance_before" :currency="false"/></td>
                        <td class="text-right"><x-money :amount="$e->balance_after" :currency="false" tone="auto"/></td>
                        <td class="text-right"><x-money :amount="$e->held_after" :currency="false"/></td>
                        <td class="text-xs text-neutral-500">{{ $e->created_at?->format('d M Y H:i:s') }}</td>
                    </tr>
                @empty
                    <x-empty :colspan="8" :title="__('No ledger entries')"/>
                @endforelse
            </x-table>
            {{ $entries->links() }}
        </x-card>
    @endif

    <x-modal show="showLimits" :title="__('Edit wallet limits')">
        <form wire:submit="saveLimits" class="grid gap-3 sm:grid-cols-2">
            <x-input :label="__('Per transaction')" wire:model="limits.per_transaction_limit" type="number" step="0.01"/>
            <x-input :label="__('Daily')" wire:model="limits.daily_limit" type="number" step="0.01"/>
            <x-input :label="__('Monthly')" wire:model="limits.monthly_limit" type="number" step="0.01"/>
            <x-input :label="__('Maximum balance')" wire:model="limits.max_balance" type="number" step="0.01"/>
            <x-textarea class="sm:col-span-2" :label="__('Reason (audited)')" wire:model="limitReason" required/>
            <div class="sm:col-span-2 flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save limits') }}</button></div>
        </form>
    </x-modal>

    <x-modal show="showStatus" :title="__('Change wallet status')">
        <form wire:submit="changeStatus" class="space-y-3">
            <p class="text-sm">{{ __('New status') }}: <strong>{{ $targetStatus ? \App\Enums\WalletStatus::from($targetStatus)->label() : '' }}</strong></p>
            @if($targetStatus === 'closed')<x-alert tone="red">{{ __('Closing is permanent and requires a zero balance with no pending transactions.') }}</x-alert>@endif
            <x-textarea :label="__('Reason (audited)')" wire:model="statusReason" required/>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Confirm') }}</button></div>
        </form>
    </x-modal>
</div>
