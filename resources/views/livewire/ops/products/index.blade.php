<div>
    <x-page-header :title="__('Financing products')" :subtitle="__('Shariah-compliant product catalogue. Terms are versioned – existing loans keep the version they were approved under.')">
        <x-slot:actions>@can('products.manage')<a href="{{ route('ops.products.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="h-4 w-4"/>{{ __('New product') }}</a>@endcan</x-slot:actions>
    </x-page-header>
    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-stat :label="__('Products')" :value="$stats->total"/><x-stat :label="__('Active')" :value="(int) $stats->active"/>
        <x-stat :label="__('Shariah board approved')" :value="(int) $stats->approved" tone="black"/>
    </div>
    <x-card :padding="false">
        <div class="flex flex-wrap gap-2 border-b border-neutral-100 p-3">
            <x-input class="w-60" wire:model.live.debounce.400ms="search" :placeholder="__('Name or code…')"/>
            <x-select wire:model.live="filterCategory" :placeholder="__('All categories')" :options="\App\Enums\ProductCategory::options()"/>
            <x-select wire:model.live="filterActive" :placeholder="__('Active & inactive')" :options="['1' => __('Active'), '0' => __('Inactive')]"/>
        </div>
        <x-table>
            <x-slot:head><x-th>{{ __('Product') }}</x-th><x-th>{{ __('Structure') }}</x-th><x-th>{{ __('Amount') }}</x-th><x-th>{{ __('Tenure') }}</x-th><x-th>{{ __('Rate') }}</x-th><x-th>{{ __('Disbursement') }}</x-th><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
            @forelse($products as $p)
                @php($v = $p->currentVersion)
                <tr wire:key="p{{ $p->id }}">
                    <td class="{{ $p->parent_id ? 'pl-10' : '' }}"><a href="{{ route('ops.products.show', $p) }}" wire:navigate class="link">{{ $p->name }}</a>
                        <div class="text-xs text-neutral-500">{{ $p->code }} · {{ $p->category->label() }}@if($p->parent) · {{ __('sub-product of') }} {{ $p->parent->name }}@endif</div>
                        @if($p->name_ur)<div class="font-urdu text-xs text-neutral-500">{{ $p->name_ur }}</div>@endif</td>
                    <td>{{ $p->shariah_structure->label() }}@if($p->shariah_board_approved) <x-badge tone="green">{{ __('Approved') }}</x-badge>@endif</td>
                    <td class="text-xs">@if($v){{ \App\Support\Money::format($v->min_amount, false) }} – {{ \App\Support\Money::format($v->max_amount, false) }}@endif</td>
                    <td class="text-xs">@if($v){{ $v->min_tenure }}–{{ $v->max_tenure }} {{ $v->isWeekly() ? 'wks' : 'mths' }}@endif</td>
                    <td class="text-xs">@if($v){{ (float) $v->profit_rate }}% {{ $v->profit_method }}@endif</td>
                    <td class="text-xs">{{ $v ? \Illuminate\Support\Str::headline($v->disbursement_mode) : '' }}</td>
                    <td class="text-xs">v{{ $v?->version }} ({{ $p->versions_count }})</td>
                    <td>@if($p->is_active)<x-badge tone="green">{{ __('Active') }}</x-badge>@else<x-badge>{{ __('Inactive') }}</x-badge>@endif @unless($p->show_in_catalog)<x-badge tone="amber">{{ __('Hidden') }}</x-badge>@endunless</td>
                </tr>
            @empty
                <x-empty :colspan="8" :title="__('No products yet')" :description="__('Create your first financing product to start accepting applications.')"/>
            @endforelse
        </x-table>
        {{ $products->links() }}
    </x-card>
</div>
