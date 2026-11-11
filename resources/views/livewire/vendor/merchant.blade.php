<div wire:poll.30s>
    <x-page-header :title="__('Merchant wallet')" :subtitle="__('Customers of this institution can pay you from their wallet using your merchant code.')"/>
    @if(! $vendor->is_merchant || ! $vendor->merchant_code)
        <x-empty :title="__('Merchant payments are not enabled')" :description="__('Ask the institution to register you as a wallet merchant.')"/>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <x-card :title="__('Your payment code')">
                <div class="mx-auto w-56 rounded-xl border-4 border-ink bg-white p-3">
                    <svg viewBox="0 0 21 21" class="h-full w-full" shape-rendering="crispEdges" aria-label="Simulated merchant code">
                        @foreach($cells as $i => $on)@if($on)<rect x="{{ $i % 21 }}" y="{{ intdiv($i, 21) }}" width="1" height="1" fill="#0a0a0a"/>@endif @endforeach
                    </svg>
                </div>
                <p class="mt-3 text-center font-mono text-xl font-bold tracking-widest">{{ $vendor->merchant_code }}</p>
                <p class="mt-1 text-center text-sm">{{ $vendor->business_name }}</p>
                <x-alert class="mt-3" tone="amber">{{ __('SIMULATED – this pattern is a demo placeholder, not a live Raast/bank QR payment instruction. Customers enter the merchant code in their wallet.') }}</x-alert>
                <button onclick="window.print()" class="btn-secondary mt-3 w-full">{{ __('Print') }}</button>
            </x-card>
            <div class="space-y-6 lg:col-span-2">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-stat :label="__('Wallet balance')" :value="$wallet ? \App\Support\Money::format($wallet->balance) : '—'" tone="green"/>
                    <x-stat :label="__('Received today')" :value="\App\Support\Money::format($today)" tone="black"/>
                    <x-stat :label="__('Wallet status')" :value="$wallet ? $wallet->status->label() : __('Not opened')" :tone="$wallet?->isActive() ? 'green' : 'amber'"/>
                </div>
                <x-card :title="__('Wallet activity')" :padding="false">
                    @if($receipts)
                        <x-table>
                            <x-slot:head><x-th>{{ __('Reference') }}</x-th><x-th>{{ __('Description') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th></x-slot:head>
                            @forelse($receipts as $t)
                                <tr><td>{{ $t->reference }}<div class="text-xs text-neutral-500">{{ $t->created_at->format('d M Y H:i') }}</div></td><td class="text-sm">{{ $t->description }}</td>
                                    <td><x-status :value="$t->status"/></td><td class="text-right {{ $t->isCredit() ? 'text-brand-700' : 'text-red-600' }}">{{ $t->isCredit() ? '+' : '−' }}<x-money :amount="$t->amount"/></td></tr>
                            @empty
                                <x-empty colspan="4" :title="__('No payments received yet')"/>
                            @endforelse
                        </x-table>
                        {{ $receipts->links() }}
                    @else
                        <x-empty :title="__('Merchant wallet not opened yet')" :description="__('The institution opens and activates your merchant wallet.')"/>
                    @endif
                </x-card>
            </div>
        </div>
    @endif
</div>
