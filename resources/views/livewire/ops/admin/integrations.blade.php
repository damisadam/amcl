<div>
    <x-page-header :title="__('Integration settings')" :subtitle="__('Per-institution provider credentials. Each tenant uses only its own credentials. All providers run as deterministic simulators in this release.')"/>
    <div class="grid gap-4 lg:grid-cols-4">
        <x-card :padding="false" class="lg:col-span-1">
            <ul class="divide-y divide-neutral-100 text-sm">
                @foreach($providers as $key => $p)
                    @php($o = $overview[$key] ?? null)
                    <li><button wire:click="$set('provider', '{{ $key }}')" @class(['w-full px-4 py-2.5 text-left', 'bg-brand-50 font-semibold' => $provider === $key, 'hover:bg-neutral-50' => $provider !== $key])>
                        <span class="block">{{ $p['name'] }}</span>
                        <span class="text-[11px] text-neutral-500">{{ ucfirst($p['category']) }} · {{ $o?->enabled ? __('enabled') : __('disabled') }} · {{ strtoupper($o?->mode ?? 'demo') }}</span></button></li>
                @endforeach
            </ul>
        </x-card>
        <div class="space-y-4 lg:col-span-3">
            <x-card :title="$def['name']" :subtitle="$def['description']">
                <x-slot:actions>
                    <x-badge :tone="$setting->mode === 'live' ? 'red' : 'amber'">{{ strtoupper($setting->mode) }}</x-badge>
                    <button wire:click="testConnection" class="btn-secondary btn-sm">{{ __('Test connection') }}</button>
                </x-slot:actions>
                @if($setting->mode === 'live')
                    <x-alert tone="red" class="mb-4" :title="__('Live mode recorded but blocked')">{{ __('Live mode was approved by :u on :d, but no certified live adapter is installed in this release; calls will return LIVE_ADAPTER_NOT_AVAILABLE. Switch back to demo to continue demonstrations.', ['u' => \App\Models\User::find($setting->live_approved_by)?->name, 'd' => $setting->live_approved_at?->format('d M Y H:i')]) }}</x-alert>
                @else
                    <x-alert tone="amber" class="mb-4">{{ __('DEMO MODE – responses are simulated deterministically. No real provider is contacted and no real money or identity data is exchanged.') }}</x-alert>
                @endif
                <form wire:submit="save" class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2 flex flex-wrap gap-6"><x-checkbox wire:model="settings.enabled" :label="__('Provider enabled for this institution')" :disabled="! $canManage"/></div>
                    <x-select :label="__('Mode')" wire:model="settings.mode" :options="['demo' => __('Demo (simulated)'), 'live' => __('Live (requires go-live approval)')]" :disabled="! $canManage"
                        :hint="$canGoLive ? null : __('Only users with go-live permission can switch to live.')"/>
                    <x-select :label="__('Environment')" wire:model="settings.environment" :options="['sandbox' => 'Sandbox', 'production' => 'Production']" :disabled="! $canManage"/>
                    <x-input class="sm:col-span-2" :label="__('Base URL')" wire:model="settings.base_url" placeholder="https://" :disabled="! $canManage"/>
                    <div class="sm:col-span-2 rounded-lg border border-neutral-200 p-3">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-600">{{ __('Credentials') }}</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach($credentials as $field => $value)
                                @continue($field === 'base_url')
                                @php($secret = in_array($field, $secretFields, true))
                                <x-input :label="\Illuminate\Support\Str::headline($field)" wire:model="credentials.{{ $field }}" :type="$secret ? 'text' : 'text'" autocomplete="off"
                                    :hint="$secret ? __('Secret – stored encrypted; shown masked. Type a new value to replace it.') : null" :disabled="! $canManage"/>
                            @endforeach
                        </div>
                    </div>
                    <x-input :label="__('Timeout (seconds)')" type="number" wire:model="settings.timeout_seconds" :disabled="! $canManage"/>
                    <x-input :label="__('Max retries')" type="number" wire:model="settings.max_retries" :disabled="! $canManage"/>
                    @if($def['category'] === 'payments')
                        <x-select :label="__('Default demo scenario')" wire:model="settings.default_scenario" :options="config('integrations.payment_scenarios')" :disabled="! $canManage"/>
                        <x-input :label="__('Simulated callback delay (seconds)')" type="number" wire:model="settings.callback_delay_seconds" :hint="__('0 = instant callback; delays need the queue worker (Horizon).')" :disabled="! $canManage"/>
                        <div class="sm:col-span-2"><p class="field-label">{{ __('Webhook URL for this institution') }}</p><p class="rounded bg-neutral-50 p-2 font-mono text-xs break-all">{{ $webhook }}</p><p class="field-hint">{{ __('Callbacks must be HMAC-SHA256 signed with this institution\'s webhook secret.') }}</p></div>
                    @endif
                    @error('action')<p class="field-error sm:col-span-2">{{ $message }}</p>@enderror
                    @if($canManage)<div class="flex justify-end sm:col-span-2"><button class="btn-primary">{{ __('Save settings') }}</button></div>@endif
                </form>
            </x-card>

            <x-card :title="__('Last 30 days')" :padding="false">
                <div class="grid gap-3 p-4 sm:grid-cols-4">
                    <x-stat :label="__('Calls')" :value="(int) $stats->total"/>
                    <x-stat :label="__('Success')" :value="(int) $stats->ok"/>
                    <x-stat :label="__('Failures')" :value="(int) $stats->bad" tone="red"/>
                    <x-stat :label="__('Avg latency')" :value="round((float) $stats->avg_ms).' ms'" tone="black"/>
                </div>
                <x-table>
                    <x-slot:head><x-th>{{ __('Time') }}</x-th><x-th>{{ __('Operation') }}</x-th><x-th>{{ __('Mode') }}</x-th><x-th>{{ __('Status') }}</x-th><x-th>{{ __('Latency') }}</x-th><x-th>{{ __('Correlation') }}</x-th></x-slot:head>
                    @forelse($logs as $l)
                        <tr><td class="text-xs">{{ $l->created_at->format('d M H:i:s') }}</td><td class="text-xs">{{ $l->operation }}</td><td class="text-xs">{{ $l->mode }}</td><td><x-status :value="$l->status"/>@if($l->error_code)<span class="text-[11px] text-red-600"> {{ $l->error_code }}</span>@endif</td><td class="text-xs">{{ $l->duration_ms }} ms</td><td class="font-mono text-[11px] text-neutral-500">{{ $l->correlation_id }}</td></tr>
                    @empty
                        <x-empty :colspan="6" :title="__('No calls logged yet')"/>
                    @endforelse
                </x-table>
            </x-card>
        </div>
    </div>
</div>
