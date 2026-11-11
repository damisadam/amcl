<div>
    <x-page-header :title="__('Documents')" :subtitle="__('Registration and bank documents. The institution verifies each upload; new uploads keep the previous version in history.')"/>
    <div class="grid gap-6 lg:grid-cols-3">
        <x-card :title="__('Upload')">
            <form wire:submit="saveDocument" class="space-y-4">
                <x-select :label="__('Document type')" wire:model="type" :options="$types->all()"/>
                <div><label class="field-label">{{ __('File (JPG, PNG, WEBP or PDF, max 5 MB)') }}</label><input type="file" wire:model="file" accept="image/*,application/pdf" class="text-sm">
                    @error('file')<p class="field-error">{{ $message }}</p>@enderror</div>
                <x-input :label="__('Expiry date (if any)')" type="date" wire:model="expires_on"/>
                <button class="btn-primary w-full" wire:loading.attr="disabled" wire:target="file,saveDocument">{{ __('Upload') }}</button>
            </form>
        </x-card>
        <x-card class="lg:col-span-2" :title="__('Your documents')" :padding="false">
            <x-table>
                <x-slot:head><x-th>{{ __('Document') }}</x-th><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Expiry') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($documents as $d)
                    <tr><td>{{ $d->type?->name ?? $d->title }}<div class="text-xs text-neutral-500">{{ $d->created_at->format('d M Y') }}</div></td><td>v{{ $d->version }}</td>
                        <td><x-status :value="$d->status"/>@if($d->rejection_reason)<div class="text-[11px] text-red-600">{{ $d->rejection_reason }}</div>@endif</td>
                        <td class="text-xs">@if($d->expires_on)<span class="{{ $d->isExpired() ? 'text-red-600' : ($d->expiresSoon() ? 'text-amber-700' : '') }}">{{ $d->expires_on->format('d M Y') }}</span>@else — @endif</td>
                        <td><a href="{{ route('documents.show', $d) }}" target="_blank" class="link text-xs">{{ __('View') }}</a></td></tr>
                @empty
                    <x-empty colspan="5" :title="__('No documents uploaded')"/>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
