<div class="space-y-5">
    <x-page-header :title="__('Documents')" :subtitle="__('Stored privately. Only you and authorised staff can open them.')"/>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror
    <section class="card p-5">
        <div class="flex items-start gap-4">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon name="document-plus" class="h-6 w-6"/></span>
            <div><h2 class="font-semibold text-ink">{{ __('Upload a document') }}</h2><p class="text-sm text-neutral-500">{{ __('Upload supporting documents for your financing, verification or account.') }}</p></div>
        </div>
        <form wire:submit="saveDocument" class="mt-4 grid gap-3 md:grid-cols-[1fr_1.3fr_1fr_auto] md:items-end">
            <x-select :label="__('Type')" wire:model="typeId" :placeholder="__('Select')" :options="$types->mapWithKeys(fn ($t) => [$t->id => app()->getLocale() === 'ur' && $t->name_ur ? $t->name_ur : $t->name])->all()"/>
            <div x-data="{ name: '' }">
                <label class="field-label" for="doc-file">{{ __('File') }}</label>
                <label class="flex cursor-pointer items-center overflow-hidden rounded-lg border border-neutral-300 bg-white text-sm shadow-xs focus-within:border-brand-600 focus-within:ring-2 focus-within:ring-brand-600/20">
                    <span class="flex items-center gap-2 border-e border-neutral-200 bg-neutral-50 px-3 py-2 font-medium text-ink"><x-icon name="document-plus" class="h-4 w-4"/>{{ __('Choose file') }}</span>
                    <span class="truncate px-3 text-neutral-500" x-text="name || @js(__('No file chosen'))"></span>
                    <input id="doc-file" type="file" wire:model="file" accept="image/*,application/pdf" class="sr-only" x-on:change="name = $event.target.files[0]?.name ?? ''">
                </label>
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <x-input :label="__('Expiry date (if any)')" type="date" wire:model="expiresOn"/>
            <button class="btn-primary bg-brand-800 px-8 py-2.5 hover:bg-brand-900" wire:loading.attr="disabled"><x-icon name="download" class="h-4 w-4 rotate-180"/>{{ __('Upload') }}</button>
        </form>
        <p class="mt-2 text-xs text-neutral-500">{{ __('Uploading the same type again creates a new version; the old one is kept in history.') }}</p>
    </section>
    <section class="card">
        <div class="flex items-start gap-4 px-5 pt-5">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon name="document-text" class="h-6 w-6"/></span>
            <div><h2 class="font-semibold text-ink">{{ __('My documents') }}</h2><p class="text-sm text-neutral-500">{{ __('View all your uploaded documents and their status.') }}</p></div>
        </div>
        <x-table dense inset class="mt-4">
            <x-slot:head><x-th>{{ __('Document') }}</x-th><x-th>{{ __('Version') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Expiry') }}</x-th><x-th>{{ __('Uploaded') }}</x-th><x-th>{{ __('Action') }}</x-th></x-slot:head>
            @forelse($current as $d)
                <tr wire:key="doc-{{ $d->id }}">
                    <td><div class="flex items-center gap-3"><x-icon name="document-text" class="h-5 w-5 text-neutral-500"/><span class="font-medium text-ink">{{ $d->title }}</span></div></td>
                    <td>v{{ $d->version }}</td>
                    <td><x-status :value="$d->status"/></td>
                    <td>@if($d->expires_on)<span @class(['text-red-600 font-medium' => $d->isExpired(), 'text-amber-700' => $d->expiresSoon()])>{{ $d->expires_on->format('d M Y') }}</span>@else — @endif</td>
                    <td class="tabular">{{ $d->created_at->format('d M Y') }}</td>
                    <td><a href="{{ route('documents.show', $d) }}" target="_blank" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-900">{{ __('Open') }}<svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M11 3h6v6M17 3l-8 8M15 11v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg><span class="sr-only">{{ __('(opens in a new tab)') }}</span></a></td>
                </tr>
            @empty
                <x-empty :colspan="6" :title="__('No documents yet')"/>
            @endforelse
        </x-table>
    </section>
    @if($previous->isNotEmpty())
        <x-card :title="__('Previous versions')">
            <ul class="space-y-1 text-sm">@foreach($previous as $d)<li class="flex justify-between"><span>{{ $d->title }} v{{ $d->version }} · {{ $d->created_at->format('d M Y') }}</span><a href="{{ route('documents.show', $d) }}" target="_blank" class="link text-xs">{{ __('Open') }}</a></li>@endforeach</ul>
        </x-card>
    @endif
</div>
