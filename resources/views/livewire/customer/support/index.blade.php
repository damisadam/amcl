<div class="space-y-4">
    <x-page-header :title="__('Complaints & Support')" :subtitle="__('Every complaint gets a ticket number and a response within :d working days.', ['d' => $days])">
        <x-slot:actions>@unless($creating)<button wire:click="$set('creating', true)" class="btn-primary px-5 py-2.5"><x-icon name="plus" class="h-4 w-4"/>{{ __('New complaint') }}</button>@endunless</x-slot:actions>
    </x-page-header>
    @error('action')<x-alert tone="red">{{ $message }}</x-alert>@enderror

    @if($creating)
        <x-card :title="__('Raise a complaint')">
            <form wire:submit="submit" class="space-y-4">
                <x-select :label="__('Category')" wire:model.live="category" :options="$categories"/>
                @if(in_array($category, ['transaction_dispute', 'refund_request', 'wallet', 'loan']))
                    <x-select :label="__('Related to')" wire:model="related" :placeholder="__('Select')">
                        @foreach($txns as $t)<option value="wallet:{{ $t->id }}">{{ $t->reference }} – {{ __($t->type->label()) }} {{ \App\Support\Money::format($t->amount) }} ({{ $t->created_at->format('d M') }})</option>@endforeach
                        @foreach($loans as $l)<option value="loan:{{ $l->id }}">{{ $l->account_no }} – {{ __('financing') }}</option>@endforeach
                    </x-select>
                @endif
                <x-input :label="__('Subject')" wire:model="subject"/>
                <x-textarea :label="__('Describe the issue')" wire:model="description" rows="4"/>
                <div class="flex gap-2"><button class="btn-primary" wire:loading.attr="disabled">{{ __('Submit') }}</button><button type="button" wire:click="$set('creating', false)" class="btn-secondary">{{ __('Cancel') }}</button></div>
            </form>
        </x-card>
    @endif

    <x-card :title="__('My complaints')" :subtitle="__('Track the status of your complaints and support tickets.')" icon="document-text" :padding="false" :divider="false">
        @if($complaints->isNotEmpty())
            {{-- Table on tablet/desktop (ref: docs/ui-references/complaints-support.png), tappable list on phones. --}}
            <x-table class="mx-5 mb-5 mt-2 hidden rounded-lg border border-neutral-200 sm:block [&_th]:bg-neutral-50 [&_th]:text-xs [&_th]:font-medium [&_th]:normal-case [&_th]:tracking-normal [&_th]:text-neutral-600">
                <x-slot:head><x-th>{{ __('Issue') }}</x-th><x-th>{{ __('Ticket number') }}</x-th><x-th>{{ __('Type') }}</x-th><x-th>{{ __('Date') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th align="right">{{ __('Actions') }}</x-th></x-slot:head>
                @foreach($complaints as $c)
                    <tr class="cursor-pointer" wire:key="c-{{ $c->id }}">
                        <td class="font-semibold"><a href="{{ route('app.support.show', $c) }}" wire:navigate class="hover:text-brand-800 hover:underline">{{ $c->subject }}</a></td>
                        <td class="tabular"><bdi>{{ $c->ticket_no }}</bdi></td>
                        <td>{{ __($c->category->label()) }}</td>
                        <td class="whitespace-nowrap tabular">{{ $c->created_at->format('d M Y') }}</td>
                        <td><x-status :value="$c->status" class="px-3.5! py-1! text-sm! font-semibold"/></td>
                        <td class="text-end"><a href="{{ route('app.support.show', $c) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-900">{{ __('View') }}<x-icon name="chevron-right" class="h-4 w-4 rtl:rotate-180"/></a></td>
                    </tr>
                @endforeach
            </x-table>
        @endif
        <ul @class(['divide-y divide-neutral-100', 'sm:hidden' => $complaints->isNotEmpty()])>
            @forelse($complaints as $c)
                <li><a href="{{ route('app.support.show', $c) }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-neutral-50">
                    <div class="min-w-0"><p class="truncate text-sm font-medium">{{ $c->subject }}</p><p class="text-xs text-neutral-500"><bdi>{{ $c->ticket_no }}</bdi> · {{ __($c->category->label()) }} · {{ $c->created_at->format('d M Y') }}</p></div>
                    <x-status :value="$c->status"/></a></li>
            @empty
                <li><x-empty :title="__('No complaints')" :description="__('We hope it stays that way! Raise one any time.')"/></li>
            @endforelse
        </ul>
    </x-card>

    <x-card :title="__('Contact us')" :subtitle="__('Get in touch with our support team for any assistance.')" icon="phone" :divider="false">
        @php($contacts = [
            ['phone', __('Helpline'), $currentTenant->support_phone ?? '', __('Call us about your financing, wallet or a complaint.')],
            ['mail', __('Email'), $currentTenant->support_email ?? '', __('Every complaint gets a response within :d working days.', ['d' => $days])],
            ['user', __('Grievance officer'), __('Head of Customer Grievances, :t', ['t' => $currentTenant->name ?? '']), __('For formal complaints and escalations.')],
            ['bank', __('Escalation'), __('If unresolved, you may approach the SECP complaint portal.'), null],
        ])
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach($contacts as [$icon, $label, $value, $note])
                <div class="flex items-start gap-3.5 rounded-xl border border-neutral-200 p-4">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700"><x-icon :name="$icon" class="h-5 w-5"/></span>
                    <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500">{{ $label }}</p>
                        <p class="mt-0.5 break-words font-semibold text-ink">@if(in_array($icon, ['phone', 'mail']))<bdi>{{ $value ?: '—' }}</bdi>@else{{ $value }}@endif</p>
                        @if($note)<p class="mt-0.5 text-xs text-neutral-500">{{ $note }}</p>@endif</div>
                </div>
            @endforeach
        </div>
        <x-alert tone="blue" icon="info" class="mt-4">{{ __('Live chat is not available in this demo; please use complaints or the helpline.') }}</x-alert>
    </x-card>

    @if($faqs->isNotEmpty())
        <x-card :title="__('Help centre')" :subtitle="__('Find answers to common questions about financing, payments and support.')" icon="document-text" :divider="false">
            <div class="-mt-1 space-y-2">@foreach($faqs as $f)<details class="group rounded-lg border border-neutral-200 bg-white px-4 py-2.5"><summary class="flex cursor-pointer list-none items-center gap-4 text-[15px] font-medium text-ink [&::-webkit-details-marker]:hidden"><svg viewBox="0 0 10 10" class="h-2.5 w-2.5 shrink-0 fill-ink transition group-open:rotate-90 rtl:rotate-180 rtl:group-open:rotate-90" aria-hidden="true"><path d="M2 1l7 4-7 4z"/></svg>{{ app()->getLocale() === 'ur' && $f->title_ur ? $f->title_ur : $f->title }}</summary><p class="mt-2 ps-7 text-sm text-neutral-600">{{ app()->getLocale() === 'ur' && $f->body_ur ? $f->body_ur : $f->body }}</p></details>@endforeach</div>
        </x-card>
    @endif
</div>
