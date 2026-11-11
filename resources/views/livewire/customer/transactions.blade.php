<div class="space-y-5">
    <x-page-header :title="__('Transaction History')" :subtitle="__('View all your wallet transactions and financing repayment history.')"/>
    @if($summary && ($view === 'wallet' && $hasWallet))
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="flex items-center gap-4 rounded-xl border border-brand-100 bg-brand-50/70 px-5 py-4"><span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-100 text-brand-700"><x-icon name="document-text" class="h-6 w-6"/></span>
                <div><p class="text-sm text-neutral-600">{{ __('Total transactions') }}</p><p class="text-2xl font-bold text-ink tabular">{{ number_format($summary['count']) }}</p></div></div>
            <div class="flex items-center gap-4 rounded-xl border border-neutral-200 bg-white px-5 py-4"><span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon name="arrow-right" class="h-6 w-6 -rotate-90"/></span>
                <div><p class="text-sm text-neutral-600">{{ __('Money in') }}</p><p class="text-2xl font-bold text-brand-700"><x-money :amount="$summary['in']"/></p></div></div>
            <div class="flex items-center gap-4 rounded-xl border border-red-100 bg-red-50/60 px-5 py-4"><span class="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600"><x-icon name="arrow-right" class="h-6 w-6 rotate-90"/></span>
                <div><p class="text-sm text-neutral-600">{{ __('Money out') }}</p><p class="text-2xl font-bold text-red-600"><x-money :amount="$summary['out']"/></p></div></div>
        </div>
        <p class="-mt-2 text-xs text-neutral-500">{{ __('Money in / out count successful transactions matching your filters.') }}</p>
    @endif
    <div class="flex gap-2">
        @if($hasWallet)<button wire:click="$set('view', 'wallet')" @class(['rounded-full px-6 py-2 text-sm font-semibold transition', 'bg-brand-800 text-white shadow-sm' => $view === 'wallet', 'border border-neutral-200 bg-white text-neutral-700 hover:border-brand-300' => $view !== 'wallet'])>{{ __('Wallet') }}</button>@endif
        <button wire:click="$set('view', 'loans')" @class(['rounded-full px-6 py-2 text-sm font-semibold transition', 'bg-brand-800 text-white shadow-sm' => $view === 'loans' || ! $hasWallet, 'border border-neutral-200 bg-white text-neutral-700 hover:border-brand-300' => $view !== 'loans' && $hasWallet])>{{ __('Financing repayments') }}</button>
    </div>
    <div class="card flex flex-wrap items-end gap-3 p-4">
        @if($view === 'wallet' && $hasWallet)<x-select class="min-w-44 flex-1" :label="__('Transaction type')" wire:model.live="type" :placeholder="__('All types')" :options="$types"/>@endif
        <x-select class="min-w-40 flex-1" :label="__('Status')" wire:model.live="status" :placeholder="__('All statuses')" :options="$view === 'wallet' ? $statuses : \App\Enums\LoanPaymentStatus::options()"/>
        <x-input class="min-w-36 flex-1" :label="__('Start date')" type="date" wire:model.live="from"/>
        <x-input class="min-w-36 flex-1" :label="__('End date')" type="date" wire:model.live="to"/>
        <div class="flex gap-2">
            <button type="button" wire:click="resetFilters" class="btn-secondary"><x-icon name="refresh" class="h-4 w-4"/>{{ __('Reset') }}</button>
            @if($view === 'wallet' && $hasWallet)<button type="button" wire:click="export" class="btn-primary bg-brand-800 hover:bg-brand-900"><x-icon name="download" class="h-4 w-4"/>{{ __('Export CSV') }}</button>@endif
        </div>
    </div>

    @php($txIcon = ['topup' => 'plus', 'transfer_out' => 'arrow-right', 'transfer_in' => 'arrow-right', 'bill_payment' => 'document-text', 'merchant_payment' => 'qr', 'merchant_receipt' => 'qr', 'instalment_payment' => 'cash', 'disbursement_credit' => 'bank', 'withdrawal' => 'bank', 'refund' => 'refresh', 'reversal' => 'refresh'])
    @if($view === 'wallet' && $hasWallet)
        <div class="card overflow-hidden">
            @if($wallet->isEmpty())
                <x-empty :title="__('No wallet transactions')"/>
            @else
                <x-table>
                    <x-slot:head><x-th>{{ __('Description') }}</x-th><x-th>{{ __('Type / reference') }}</x-th><x-th>{{ __('Date & time') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                    @foreach($wallet as $t)
                        <tr wire:key="wt-{{ $t->id }}" wire:click="show({{ $t->id }})" class="cursor-pointer">
                            <td><div class="flex items-center gap-3"><span @class(['flex h-9 w-9 shrink-0 items-center justify-center rounded-full', 'bg-brand-50 text-brand-700' => $t->isCredit(), 'bg-neutral-100 text-neutral-600' => ! $t->isCredit()])><x-icon :name="$txIcon[$t->type->value] ?? 'arrows'" class="h-4 w-4 rtl:rotate-180"/></span>
                                <div class="min-w-0"><p class="max-w-md truncate font-medium text-ink">{{ $t->description ?? __($t->type->label()) }}</p><p class="text-xs text-neutral-500"><bdi>{{ $t->reference }}</bdi></p></div></div></td>
                            <td class="text-neutral-600">{{ __($t->type->label()) }}</td>
                            <td class="text-neutral-600 tabular">{{ $t->created_at->format('d M Y H:i') }}</td>
                            <td @class(['text-end font-semibold tabular', 'text-brand-700' => $t->isCredit(), 'text-red-600' => ! $t->isCredit()])><bdi>{{ $t->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($t->amount, false) }}</bdi></td>
                            <td><x-status :value="$t->status"/></td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
            {{ $wallet->links() }}
        </div>
    @else
        <div class="card overflow-hidden">
            @if($loanPayments->isEmpty())
                <x-empty :title="__('No repayments yet')"/>
            @else
                <x-table>
                    <x-slot:head><x-th>{{ __('Description') }}</x-th><x-th>{{ __('Channel / reference') }}</x-th><x-th>{{ __('Date') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th><span class="sr-only">{{ __('Receipt') }}</span></x-th></x-slot:head>
                    @foreach($loanPayments as $p)
                        <tr wire:key="lp-{{ $p->id }}">
                            <td><div class="flex items-center gap-3"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-600"><x-icon name="cash" class="h-4 w-4"/></span>
                                <div><p class="font-medium text-ink">{{ __('Instalment payment') }} · <bdi>{{ $p->loanAccount->account_no }}</bdi></p><p class="text-xs text-neutral-500"><bdi>{{ $p->reference }}</bdi></p></div></div></td>
                            <td class="text-neutral-600">{{ __($p->channel->label()) }}</td>
                            <td class="text-neutral-600 tabular">{{ $p->created_at->format('d M Y') }}</td>
                            <td class="text-end font-semibold tabular"><x-money :amount="$p->amount"/></td>
                            <td><x-status :value="$p->status"/></td>
                            <td>@if(in_array($p->status->value, ['succeeded', 'reversed']))<a href="{{ route('app.receipt', $p) }}" target="_blank" class="link text-sm">{{ __('Receipt') }}</a>@endif</td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
            {{ $loanPayments->links() }}
        </div>
    @endif

    @if($d)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 sm:items-center" wire:click.self="$set('detail', null)">
            <div class="w-full max-w-md rounded-t-2xl bg-white p-5 sm:rounded-2xl">
                <div class="flex items-start justify-between"><h3 class="font-semibold">{{ __($d->type->label()) }}</h3><button wire:click="$set('detail', null)" class="text-neutral-400">✕</button></div>
                <p class="mt-2 text-2xl font-bold tabular">{{ $d->isCredit() ? '+' : '−' }}{{ \App\Support\Money::format($d->amount) }}</p>
                <x-dl class="mt-3" :cols="1" :items="[
                    __('Status') => __($d->status->label()), __('Reference') => $d->reference, __('Description') => $d->description,
                    __('Date') => $d->created_at->format('d M Y H:i:s'), __('Completed') => $d->completed_at?->format('d M Y H:i:s'),
                    __('Channel') => $d->is_external ? strtoupper((string) $d->provider).' ('.__('simulated').')' : __('Internal wallet ledger'),
                    __('Provider reference') => $d->paymentOrder?->provider_ref, __('Reason') => $d->failure_reason,
                ]"/>
                <div class="mt-4 flex gap-2">
                    @if($d->status->value === 'succeeded')<a href="{{ route('app.wallet.receipt', $d) }}" target="_blank" class="btn-secondary btn-sm">{{ __('Receipt') }}</a>@endif
                    <a href="{{ route('app.support') }}?transaction={{ $d->id }}" wire:navigate class="btn-ghost btn-sm">{{ __('Report a problem') }}</a>
                </div>
            </div>
        </div>
    @endif
</div>
