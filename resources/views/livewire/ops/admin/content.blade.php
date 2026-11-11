<div>
    <x-page-header :title="__('Content & templates')" :subtitle="__('Customer-facing FAQs, banners and notices, and notification templates in English and Urdu.')"/>
    <x-tabs :tabs="['content' => __('FAQs, banners & notices'), 'templates' => __('Notification templates'), 'log' => __('Notification log')]" :active="$tab"/>

    @if($tab === 'content')
        <x-card :padding="false">
            <x-slot:actions><button wire:click="editItem" class="btn-primary btn-sm">+ {{ __('Add item') }}</button></x-slot:actions>
            <x-table><x-slot:head><x-th>{{ __('Type') }}</x-th><x-th>{{ __('Title') }}</x-th><x-th>{{ __('Order') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($items as $c)
                    <tr><td><x-badge tone="blue">{{ ucfirst($c->type) }}</x-badge></td><td>{{ $c->title }}<div class="font-urdu text-xs text-neutral-500" dir="rtl">{{ $c->title_ur }}</div></td><td class="text-xs">{{ $c->sort }}</td>
                        <td><x-status :value="$c->is_active ? 'active' : 'inactive'"/></td>
                        <td class="text-right whitespace-nowrap"><button wire:click="editItem({{ $c->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button><button wire:click="deleteItem({{ $c->id }})" wire:confirm="{{ __('Delete this item?') }}" class="btn-ghost btn-sm text-red-600">{{ __('Delete') }}</button></td></tr>
                @empty
                    <x-empty :colspan="5" :title="__('No content yet')" :description="__('FAQs and banners appear on the institution\'s landing page and customer home.')"/>
                @endforelse
            </x-table>
        </x-card>
    @elseif($tab === 'templates')
        <x-card :padding="false" :subtitle="__('Use {placeholders} such as {application_no}, {status}, {body}. Missing channels fall back to the system text; failed WhatsApp/email falls back to SMS.')">
            <x-slot:actions><button wire:click="editTemplate" class="btn-primary btn-sm">+ {{ __('Template') }}</button></x-slot:actions>
            <x-table><x-slot:head><x-th>{{ __('Event') }}</x-th><x-th>{{ __('Channel') }}</x-th><x-th>{{ __('Subject / body') }}</x-th><x-th>{{ __('Urdu') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th></x-th></x-slot:head>
                @forelse($templates as $t)
                    <tr><td class="text-xs">{{ \Illuminate\Support\Str::headline($t->event) }}</td><td><x-badge>{{ strtoupper($t->channel) }}</x-badge></td>
                        <td class="max-w-sm text-xs"><p class="font-medium">{{ $t->subject }}</p><p class="truncate text-neutral-500">{{ $t->body }}</p></td>
                        <td class="text-xs">{{ $t->body_ur ? __('Yes') : '—' }}</td><td><x-status :value="$t->is_active ? 'active' : 'inactive'"/></td>
                        <td class="text-right"><button wire:click="editTemplate({{ $t->id }})" class="btn-ghost btn-sm">{{ __('Edit') }}</button></td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No templates')"/>
                @endforelse
            </x-table>
        </x-card>
    @else
        <x-card :padding="false" :subtitle="__('Simulated SMS / email / WhatsApp deliveries. OTP codes are never stored.')">
            <div class="flex gap-2 border-b border-neutral-100 p-4">
                <select wire:model.live="logChannel" class="field-input sm:w-40"><option value="">{{ __('All channels') }}</option>@foreach(['sms','email','whatsapp','push'] as $c)<option value="{{ $c }}">{{ strtoupper($c) }}</option>@endforeach</select>
                <select wire:model.live="logStatus" class="field-input sm:w-40"><option value="">{{ __('All statuses') }}</option><option value="sent">{{ __('Sent') }}</option><option value="failed">{{ __('Failed') }}</option></select>
            </div>
            <x-table><x-slot:head><x-th>{{ __('Time') }}</x-th><x-th>{{ __('Event') }}</x-th><x-th>{{ __('Channel') }}</x-th><x-th>{{ __('Recipient') }}</x-th><x-th>{{ __('Message') }}</x-th><x-th>{{ __('Status') }}</x-th></x-slot:head>
                @forelse($logs as $l)
                    <tr><td class="text-xs">{{ $l->created_at->format('d M H:i') }}</td><td class="text-xs">{{ $l->event }}</td><td class="text-xs">{{ strtoupper($l->channel) }}@if($l->fallback_of_id)<div class="text-[11px] text-amber-700">{{ __('fallback of #:id', ['id' => $l->fallback_of_id]) }}</div>@endif</td>
                        <td class="text-xs tabular">{{ strlen($l->recipient) > 6 ? substr($l->recipient, 0, 4).'****'.substr($l->recipient, -3) : $l->recipient }}</td>
                        <td class="max-w-sm truncate text-xs" title="{{ \App\Livewire\Ops\Admin\Content::safeBody($l->body) }}">{{ \App\Livewire\Ops\Admin\Content::safeBody($l->body) }}</td>
                        <td><x-status :value="$l->status"/>@if($l->simulated)<span class="text-[10px] text-neutral-400"> sim</span>@endif</td></tr>
                @empty
                    <x-empty :colspan="6" :title="__('No notifications sent yet')"/>
                @endforelse
            </x-table>
            @if($logs){{ $logs->links() }}@endif
        </x-card>
    @endif

    <x-modal show="showItem" :title="$itemId ? __('Edit content') : __('New content')" max-width="2xl">
        <form wire:submit="saveItem" class="grid gap-3 sm:grid-cols-2">
            <x-select :label="__('Type')" wire:model="item.type" :options="['faq' => 'FAQ', 'banner' => __('Banner'), 'notice' => __('Notice'), 'help' => __('Help article')]"/>
            <x-input :label="__('Display order')" type="number" wire:model="item.sort"/>
            <x-input :label="__('Title (English)')" wire:model="item.title" required/><x-input :label="__('Title (Urdu)')" wire:model="item.title_ur" dir="rtl"/>
            <x-textarea :label="__('Body (English)')" wire:model="item.body" rows="4"/><x-textarea :label="__('Body (Urdu)')" wire:model="item.body_ur" rows="4" dir="rtl"/>
            <x-checkbox wire:model="item.is_active" :label="__('Published')"/>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
    <x-modal show="showTemplate" :title="$templateId ? __('Edit template') : __('New template')" max-width="2xl">
        <form wire:submit="saveTemplate" class="grid gap-3 sm:grid-cols-2">
            <x-select :label="__('Event')" wire:model="tpl.event" :options="$events"/>
            <x-select :label="__('Channel')" wire:model="tpl.channel" :options="['sms' => 'SMS', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'in_app' => __('In-app'), 'push' => 'Push']"/>
            <x-input :label="__('Subject (English)')" wire:model="tpl.subject"/><x-input :label="__('Subject (Urdu)')" wire:model="tpl.subject_ur" dir="rtl"/>
            <x-textarea :label="__('Body (English)')" wire:model="tpl.body" rows="4" required/><x-textarea :label="__('Body (Urdu)')" wire:model="tpl.body_ur" rows="4" dir="rtl"/>
            <x-checkbox wire:model="tpl.is_active" :label="__('Active')"/>
            <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" class="btn-secondary" x-on:click="open=false">{{ __('Cancel') }}</button><button class="btn-primary">{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</div>
