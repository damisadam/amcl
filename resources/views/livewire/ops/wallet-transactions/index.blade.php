<div wire:poll.30s>
    <x-page-header :title="__('Wallet transactions')" :subtitle="__('Internal transfers move only the wallet ledger; external transactions settle through simulated providers.')"/>
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat :label="__('Transactions')" :value="number_format($sums['count'])"/>
        <x-stat :label="__('Credits (succeeded)')" :value="\App\Support\Money::format($sums['credit'])"/>
        <x-stat :label="__('Debits (succeeded)')" :value="\App\Support\Money::format($sums['debit'])" tone="black"/>
        <x-stat :label="__('Pending')" :value="$sums['pending']" tone="amber"/>
        <x-stat :label="__('Failed')" :value="$sums['failed']" tone="red"/>
    </div>
    <x-card :padding="false">
        <div class="grid gap-2 border-b border-neutral-100 p-4 sm:grid-cols-3 xl:grid-cols-7">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Reference / description') }}" class="field-input xl:col-span-2">
            <select wire:model.live="filterType" class="field-input"><option value="">{{ __('All types') }}</option>@foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterStatus" class="field-input"><option value="">{{ __('All statuses') }}</option>@foreach($statuses as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
            <select wire:model.live="filterExternal" class="field-input"><option value="">{{ __('Internal & external') }}</option><option value="0">{{ __('Internal only') }}</option><option value="1">{{ __('External only') }}</option></select>
            <div class="flex gap-1 sm:col-span-2"><input type="date" wire:model.live="filterFrom" class="field-input min-w-0" title="{{ __('From') }}"><input type="date" wire:model.live="filterTo" class="field-input min-w-0" title="{{ __('To') }}"></div>
        </div>
        <x-table>
            <x-slot:head>
                <x-th sort="id" :current="$sort" :dir="$dir">{{ __('Reference') }}</x-th><x-th>{{ __('Wallet') }}</x-th>
                <x-th sort="type" :current="$sort" :dir="$dir">{{ __('Type') }}</x-th><x-th sort="status" :current="$sort" :dir="$dir">{{ __('Status') }}</x-th>
                <x-th sort="amount" :current="$sort" :dir="$dir" align="right">{{ __('Amount') }}</x-th><x-th sort="created_at" :current="$sort" :dir="$dir">{{ __('Date') }}</x-th><x-th></x-th>
            </x-slot:head>
            @forelse($rows as $t)
                <tr wire:key="tx{{ $t->id }}">
                    <td><button wire:click="view({{ $t->id }})" class="link tabular text-xs">{{ $t->reference }}</button>
                        <div class="text-xs text-neutral-500">{{ $t->is_external ? __('External').' · '.($t->provider ?? '') : __('Internal ledger') }}</div></td>
                    <td class="text-xs"><a class="link" wire:navigate href="{{ route('ops.wallets.show', $t->wallet_id) }}">{{ $t->wallet?->wallet_no }}</a><div class="text-neutral-500">{{ $t->wallet?->title }}</div></td>
                    <td>{{ $t->type->label() }}</td>
                    <td><x-status :value="$t->status"/></td>
                    <td @class(['text-right tabular', 'text-brand-700' => $t->isCredit(), 'text-red-600' => ! $t->isCredit()])>{{ $t->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($t->amount, false) }}</td>
                    <td class="text-xs text-neutral-500">{{ $t->created_at->format('d M Y H:i') }}</td>
                    <td class="text-right">
                        @if($canReverse && \App\Livewire\Ops\WalletTransactions\Index::reversible($t))
                            <button wire:click="askReverse({{ $t->id }})" class="btn-secondary btn-sm">{{ $t->type->value === 'merchant_payment' ? __('Refund') : __('Reverse') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <x-empty :colspan="7" :title="__('No transactions match these filters')"/>
            @endforelse
        </x-table>
        {{ $rows->links() }}
    </x-card>

    <x-modal show="showDetail" :title="__('Transaction detail')" max-width="2xl">
        @if($detail)
            <x-dl :items="[
                __('Reference') => $detail->reference, __('Type') => $detail->type->label(), __('Status') => $detail->status->label(),
                __('Direction') => ucfirst($detail->direction), __('Amount') => \App\Support\Money::format($detail->amount), __('Fee') => \App\Support\Money::format($detail->fee),
                __('Wallet') => $detail->wallet?->wallet_no.' · '.$detail->wallet?->title, __('Counterparty wallet') => $detail->counterpartyWallet?->wallet_no,
                __('Related transaction') => $detail->related?->reference, __('Provider') => $detail->provider, __('Idempotency key') => $detail->idempotency_key,
                __('Failure reason') => $detail->failure_reason, __('Description') => $detail->description, __('Completed') => $detail->completed_at?->format('d M Y H:i:s'),
            ]"/>
            <h4 class="mt-4 text-sm font-semibold">{{ __('Ledger entries') }}</h4>
            <ul class="mt-1 text-xs text-neutral-700">@forelse($detail->ledgerEntries as $e)<li>{{ ucfirst($e->entry_type) }} {{ \App\Support\Money::format($e->amount) }} · {{ __('balance') }} {{ $e->balance_before }} → {{ $e->balance_after }}</li>@empty<li>{{ __('No ledger entries (not settled).') }}</li>@endforelse</ul>
            @if($detail->paymentOrder)
                <h4 class="mt-4 text-sm font-semibold">{{ __('Payment order') }}</h4>
                <p class="text-xs"><a href="{{ route('ops.payments.show', $detail->paymentOrder) }}" wire:navigate class="link">{{ $detail->paymentOrder->reference }}</a> · {{ $detail->paymentOrder->provider }} · {{ $detail->paymentOrder->status->label() }} · {{ __('scenario') }} {{ $detail->paymentOrder->scenario }}</p>
                <ul class="mt-1 text-xs text-neutral-700">@foreach($detail->paymentOrder->callbacks as $cb)<li>{{ $cb->received_at->format('H:i:s') }} · {{ $cb->event_id }} · <x-status :value="$cb->result"/> {{ $cb->error }}</li>@endforeach</ul>
            @endif
            @if($canReverse && \App\Livewire\Ops\WalletTransactions\Index::reversible($detail))
                <div class="mt-4 flex justify-end"><button wire:click="askReverse({{ $detail->id }})" class="btn-danger btn-sm">{{ __('Reverse / refund') }}</button></div>
            @endif
        @endif
    </x-modal>

    <x-modal show="showReverse" :title="__('Reverse internal transaction')">
        <form wire:submit="reverse" class="space-y-3">
            <x-alert>{{ __('Compensating ledger entries will debit the receiving wallet and credit the payer. Posted history is never edited. You cannot reverse a transaction you initiated.') }}</x-alert>
            @error('action')<p class="field-error">{{ $message }}</p>@enderror
            <x-textarea :label="__('Reason (audited)')" wire:model="reverseReason" required/>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-danger">{{ __('Reverse') }}</button></div>
        </form>
    </x-modal>
</div>
