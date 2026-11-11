<div wire:poll.60s>
    <x-page-header :title="__('Welcome, :name', ['name' => $vendor->business_name])" :subtitle="__('Vendor code :c · :city', ['c' => $vendor->code, 'city' => $vendor->city ?: __('city not set')])">
        <x-slot:actions><x-status :value="$vendor->status"/><a href="{{ route('vendor.originate') }}" wire:navigate class="btn-primary">+ {{ __('New customer request') }}</a></x-slot:actions>
    </x-page-header>
    @if(! $vendor->isActive())<x-alert class="mb-4">{{ __('Your vendor account is not active yet. The institution must approve it before you can submit financing requests.') }}</x-alert>@endif
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-stat :label="__('In progress')" :value="$pending" tone="amber" :href="route('vendor.orders')"/>
        <x-stat :label="__('Approved')" :value="$approved" tone="blue"/>
        <x-stat :label="__('Disbursed')" :value="$disbursed" tone="green"/>
        <x-stat :label="__('Paid this month')" :value="\App\Support\Money::format($paidMonth)" tone="black" :href="route('vendor.payments')"/>
        <x-stat :label="__('Payout in progress')" :value="\App\Support\Money::format($inFlight)" tone="gray"/>
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-2" :title="__('Latest financing requests')">
            <x-slot:actions><a href="{{ route('vendor.orders') }}" wire:navigate class="link text-xs">{{ __('All') }}</a></x-slot:actions>
            <ul class="divide-y divide-neutral-100 text-sm">
                @forelse($recent as $a)
                    <li class="flex items-center justify-between gap-3 py-2.5"><a href="{{ route('vendor.orders.show', $a) }}" wire:navigate class="min-w-0"><p class="truncate font-medium">{{ $a->customer->full_name }}</p>
                        <p class="text-xs text-neutral-500">{{ $a->application_no }} · {{ $a->asset_description }}</p></a>
                        <div class="text-right"><x-badge :tone="\App\Livewire\Vendor\Concerns\VendorOrderStatus::tone($a->status)">{{ \App\Livewire\Vendor\Concerns\VendorOrderStatus::label($a->status) }}</x-badge><p class="text-xs"><x-money :amount="$a->requested_amount"/></p></div></li>
                @empty
                    <li><x-empty :title="__('No requests yet')" :description="__('Start a financing request for a customer at your shop.')"/></li>
                @endforelse
            </ul>
        </x-card>
        <x-card :title="__('Profile completeness')">
            <div class="mb-3 h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-brand-600" style="width: {{ $completeness }}%"></div></div>
            <p class="mb-3 text-sm font-semibold">{{ $completeness }}%</p>
            <ul class="space-y-1 text-sm">@foreach($checks as $label => $ok)<li class="flex items-center gap-2"><span class="{{ $ok ? 'text-brand-700' : 'text-neutral-300' }}">{{ $ok ? '✔' : '○' }}</span>{{ __($label) }}</li>@endforeach</ul>
            <a href="{{ route('vendor.documents') }}" wire:navigate class="link mt-3 inline-block text-sm">{{ __('Upload documents') }}</a>
        </x-card>
    </div>
    <x-card class="mt-6" :title="__('Latest payments received')" :padding="false">
        <x-table>
            <x-slot:head><x-th>{{ __('Payment') }}</x-th><x-th>{{ __('Purpose') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Amount') }}</x-th></x-slot:head>
            @forelse($payments as $p)
                <tr><td>{{ $p->payment_no }}<div class="text-xs text-neutral-500">{{ $p->paid_at?->format('d M Y') }}</div></td><td class="text-xs">{{ $p->purpose }}</td><td><x-status :value="$p->status"/></td><td class="text-right"><x-money :amount="$p->net_amount"/></td></tr>
            @empty
                <x-empty colspan="4" :title="__('No payments yet')"/>
            @endforelse
        </x-table>
    </x-card>
</div>
