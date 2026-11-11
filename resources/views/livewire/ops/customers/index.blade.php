<div>
    <x-page-header :title="__('Customers')" :subtitle="__('Unified Customer Information File (CIF) shared by LMS, DFS, Wallet and FMS.')">
        <x-slot:actions>
            @can('customers.export')<button wire:click="export" class="btn-secondary"><x-icon name="download" class="h-4 w-4"/>{{ __('Export CSV') }}</button>@endcan
            @can('customers.create')<a href="{{ route('ops.customers.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New customer') }}</a>@endcan
        </x-slot:actions>
    </x-page-header>

    <x-card :padding="false">
        <div class="grid gap-3 border-b border-neutral-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <x-input class="lg:col-span-2" icon="search" wire:model.live.debounce.400ms="search" :placeholder="__('Search name, CIF, mobile, CNIC…')"/>
            <x-select wire:model.live="filterKyc" :options="$kycOptions" :placeholder="__('All KYC')"/>
            @if($canAml)<x-select wire:model.live="filterAml" :options="$amlOptions" :placeholder="__('All AML')"/>@endif
            <x-select wire:model.live="filterStatus" :options="$statusOptions" :placeholder="__('All statuses')"/>
            <x-select wire:model.live="filterBranch" :options="$branches" :placeholder="__('All branches')"/>
        </div>
        <x-table wire:loading.class="opacity-60">
            <x-slot:head>
                <x-th sort="cif_no" :current="$sort" :dir="$dir">CIF</x-th><x-th sort="full_name" :current="$sort" :dir="$dir">Name</x-th><x-th>CNIC</x-th><x-th>Mobile</x-th>
                <x-th>Branch</x-th><x-th sort="kyc_status" :current="$sort" :dir="$dir">KYC</x-th>@if($canAml)<x-th>AML</x-th>@endif<x-th>Status</x-th><x-th>Wallet</x-th><x-th align="right">Apps / Loans</x-th><x-th><span class="sr-only">{{ __('Actions') }}</span></x-th>
            </x-slot:head>
            @forelse($customers as $c)
                <tr>
                    <td class="whitespace-nowrap"><a href="{{ route('ops.customers.show', $c) }}" wire:navigate class="link tabular">{{ $c->cif_no }}</a></td>
                    <td class="font-medium">{{ $c->full_name }} @if($c->is_blacklisted)<x-badge tone="red">Blacklisted</x-badge>@endif<div class="text-xs text-neutral-500">{{ ucfirst($c->onboarding_channel) }} · {{ $c->city }}</div></td>
                    @php($show = isset($revealedIds[$c->id]))
                    <td class="whitespace-nowrap tabular">{{ $show ? $c->cnic : $c->maskedCnic() }}</td>
                    <td class="whitespace-nowrap tabular">{{ $show ? $c->mobile : substr($c->mobile, 0, 4).'*****'.substr($c->mobile, -2) }}
                        @if($sensitive && ! $show)<button wire:click="reveal({{ $c->id }})" class="ml-1 text-xs text-brand-700 hover:underline" title="Reveal full CNIC and mobile (audited)">Reveal</button>@endif</td>
                    <td>{{ $c->branch?->name }}</td>
                    <td><x-status :value="$c->kyc_status"/></td>
                    @if($canAml)<td><x-status :value="$c->aml_status"/></td>@endif
                    <td><x-status :value="$c->status"/></td>
                    <td>@if($c->wallet)<x-status :value="$c->wallet->status"/>@else<span class="text-neutral-400">—</span>@endif</td>
                    <td class="text-right tabular">{{ $c->applications_count }} / {{ $c->loan_accounts_count }}</td>
                    <td class="w-10 text-right">
                        <div x-data="{ o: false }" class="relative inline-block">
                            <button type="button" x-on:click="o=!o" class="rounded-md p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-ink" aria-label="{{ __('Actions for :n', ['n' => $c->cif_no]) }}"><x-icon name="dots-vertical" class="h-5 w-5"/></button>
                            <div x-show="o" x-cloak x-on:click.outside="o=false" @class(['absolute right-0 z-20 w-48 overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 text-left text-sm shadow-lg', 'bottom-full mb-1' => $up = $loop->remaining < 3 && $loop->count > 3, 'mt-1' => ! $up])>
                                <a href="{{ route('ops.customers.show', $c) }}" wire:navigate class="block px-3 py-2 hover:bg-neutral-50">{{ __('Open customer file') }}</a>
                                @if($c->wallet && Route::has('ops.wallets.show') && auth()->user()->hasPermission('wallet.view'))<a href="{{ route('ops.wallets.show', $c->wallet) }}" wire:navigate class="block px-3 py-2 hover:bg-neutral-50">{{ __('View wallet') }}</a>@endif
                                @if($sensitive && ! $show)<button type="button" wire:click="reveal({{ $c->id }})" x-on:click="o=false" class="block w-full px-3 py-2 text-left hover:bg-neutral-50">{{ __('Reveal CNIC & mobile') }}</button>@endif
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <x-empty :colspan="$canAml ? 11 : 10" :title="__('No customers match your filters')" :description="__('Try clearing filters, or onboard a new customer.')"/>
            @endforelse
        </x-table>
        {{ $customers->links() }}
    </x-card>
</div>
