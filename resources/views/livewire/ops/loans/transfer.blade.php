<div>
    <x-page-header :title="__('Portfolio transfer')" :subtitle="__('Move loan cases to another branch and/or officer (staff transfer, branch closure). Full audit history is kept.')" :back="route('ops.loans.index')"/>
    <div class="grid gap-4 lg:grid-cols-3">
        <x-card class="lg:col-span-2" :padding="false">
            <div class="flex flex-wrap gap-2 border-b border-neutral-100 p-3">
                <x-input class="w-48" wire:model.live.debounce.400ms="search" :placeholder="__('Account no…')"/>
                <x-select wire:model.live="fromBranch" :placeholder="__('From any branch')" :options="$branches"/>
                <x-select wire:model.live="fromOfficer" :placeholder="__('From any officer')" :options="$officers"/>
                <button class="btn-secondary btn-sm" wire:click="selectAllVisible(@js($loans->pluck('id')->all()))">{{ __('Select page') }}</button>
                <button class="btn-ghost btn-sm" wire:click="$set('selected', [])">{{ __('Clear') }}</button>
            </div>
            <x-table>
                <x-slot:head><x-th></x-th><x-th>{{ __('Account') }}</x-th><x-th>{{ __('Customer') }}</x-th><x-th>{{ __('Branch') }}</x-th><x-th>{{ __('Officer') }}</x-th><x-th align="right">{{ __('Outstanding') }}</x-th></x-slot:head>
                @forelse($loans as $l)
                    <tr wire:key="t{{ $l->id }}"><td><input type="checkbox" value="{{ $l->id }}" wire:model.live="selected" class="h-4 w-4 rounded border-neutral-300 text-brand-700"></td>
                        <td class="font-medium">{{ $l->account_no }}</td><td>{{ $l->customer->full_name }}</td><td>{{ $l->branch?->name }}</td><td>{{ $l->officer?->name }}</td>
                        <td class="text-right"><x-money :amount="$l->totalOutstanding()" :currency="false"/></td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No open loans match')"/>
                @endforelse
            </x-table>
            {{ $loans->links() }}
        </x-card>
        <div class="space-y-4">
            <x-card :title="__('Destination')">
                <p class="mb-3 text-sm text-neutral-600">{{ trans_choice(':n account selected|:n accounts selected', count($selected), ['n' => count($selected)]) }}</p>
                <div class="space-y-3">
                    <x-select :label="__('To branch')" wire:model="toBranch" :placeholder="__('Keep current')" :options="$branches"/>
                    <x-select :label="__('To officer')" wire:model="toOfficer" :placeholder="__('Keep current')" :options="$officers"/>
                    <x-textarea :label="__('Reason')" wire:model="reason" required/>
                    @error('selected')<p class="field-error">{{ __('Select at least one account.') }}</p>@enderror
                    @error('action')<p class="field-error">{{ $message }}</p>@enderror
                    <button class="btn-primary w-full" wire:click="transfer" wire:confirm="{{ __('Transfer the selected accounts?') }}">{{ __('Transfer') }}</button>
                </div>
            </x-card>
            <x-card :title="__('Recent transfers')" :padding="false">
                <ul class="divide-y divide-neutral-100 text-sm">
                    @forelse($history as $h)
                        <li class="px-4 py-2.5"><p class="font-medium">{{ $h->reference }} · {{ count($h->loan_account_ids) }} {{ __('accounts') }}</p>
                            <p class="text-xs text-neutral-500">{{ $h->fromBranch?->name }} → {{ $h->toBranch?->name ?? __('same branch') }} @if($h->toOfficer)· {{ $h->toOfficer->name }}@endif · {{ $h->actor->name }} · {{ $h->created_at->format('d M Y') }}</p>
                            <p class="text-xs text-neutral-600">{{ $h->reason }}</p></li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-neutral-500">{{ __('No transfers yet') }}</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>
</div>
