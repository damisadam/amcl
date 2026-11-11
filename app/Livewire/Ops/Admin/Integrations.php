<?php

namespace App\Livewire\Ops\Admin;

use App\Livewire\Concerns\HandlesActions;
use App\Models\IntegrationLog;
use App\Models\IntegrationSetting;
use App\Services\Audit\Auditor;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderResponse;
use App\Support\TenantContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tenant integration settings (Agreement §9.2). Secrets are encrypted at rest, rendered only as
 * masked values, and never leave the server unmasked. Live mode can be recorded as approved but
 * release 1 has no certified live adapters, so calls stay blocked.
 */
#[Layout('layouts.ops')]
class Integrations extends Component
{
    use HandlesActions;

    #[Url(except: 'nadra_verisys')]
    public string $provider = 'nadra_verisys';

    public array $credentials = [];

    public array $settings = [];

    public function mount(): void
    {
        $this->need('integrations.view');
        abort_unless(array_key_exists($this->provider, config('integrations.providers')), 404);
        $this->load();
    }

    public function updatedProvider(): void
    {
        abort_unless(array_key_exists($this->provider, config('integrations.providers')), 404);
        $this->resetValidation();
        $this->load();
    }

    private function setting(): IntegrationSetting
    {
        return IntegrationSetting::firstOrCreate(['provider' => $this->provider], ['enabled' => false, 'mode' => 'demo', 'environment' => 'sandbox', 'options' => []]);
    }

    private function load(): void
    {
        $s = $this->setting();
        $masked = app(IntegrationManager::class)->masked($s);
        $this->credentials = [];
        foreach (config("integrations.providers.{$this->provider}.fields") as $f) {
            $this->credentials[$f] = $masked[$f] ?? '';
        }
        $this->settings = [
            'enabled' => (bool) $s->enabled, 'mode' => $s->mode, 'environment' => $s->environment, 'base_url' => (string) $s->base_url,
            'timeout_seconds' => (string) $s->option('timeout_seconds', 15), 'max_retries' => (string) $s->option('max_retries', 3),
            'default_scenario' => (string) $s->option('default_scenario', 'success'), 'callback_delay_seconds' => (string) $s->option('callback_delay_seconds', 0),
        ];
    }

    public function save(IntegrationManager $manager): void
    {
        $this->need('integrations.manage');
        $this->validate([
            'settings.mode' => 'required|in:demo,live', 'settings.environment' => 'required|in:sandbox,production',
            'settings.base_url' => 'nullable|url:https|max:255', 'settings.timeout_seconds' => 'required|integer|min:1|max:120',
            'settings.max_retries' => 'required|integer|min:0|max:10', 'settings.callback_delay_seconds' => 'required|integer|min:0|max:600',
            'settings.default_scenario' => 'required|in:'.implode(',', array_keys(config('integrations.payment_scenarios'))),
            'credentials.*' => 'nullable|string|max:500',
        ]);
        $s = $this->setting();
        $wantsLive = $this->settings['mode'] === 'live';
        if ($wantsLive && $s->mode !== 'live' && ! auth()->user()->hasPermission('integrations.go_live')) {
            $this->addError('settings.mode', __('Switching to live mode requires the "Approve switching a provider to live mode" permission.'));

            return;
        }
        $fields = config("integrations.providers.{$this->provider}.fields");
        $input = array_intersect_key($this->credentials, array_flip($fields));

        $this->attempt(function () use ($s, $manager, $input, $wantsLive) {
            $old = $s->only(['enabled', 'mode', 'environment', 'base_url']);
            $s->fill(['enabled' => (bool) $this->settings['enabled'], 'environment' => $this->settings['environment'], 'base_url' => $this->settings['base_url'] ?: null]);
            if ($wantsLive && $s->mode !== 'live') {
                $s->forceFill(['mode' => 'live', 'live_approved_by' => auth()->id(), 'live_approved_at' => now()]);
                Auditor::record('integration.live_mode_approved', $s, ['mode' => 'demo'], ['mode' => 'live', 'provider' => $s->provider]);
            } elseif (! $wantsLive && $s->mode === 'live') {
                $s->forceFill(['mode' => 'demo', 'live_approved_by' => null, 'live_approved_at' => null]);
            }
            $manager->updateCredentials($s, $input, [
                'timeout_seconds' => (int) $this->settings['timeout_seconds'], 'max_retries' => (int) $this->settings['max_retries'],
                'default_scenario' => $this->settings['default_scenario'], 'callback_delay_seconds' => (int) $this->settings['callback_delay_seconds'],
            ]);
            $new = $s->only(['enabled', 'mode', 'environment', 'base_url']);
            if ($old != $new) {
                Auditor::record('integration.mode_updated', $s, $old, $new);
            }
        }, __('Settings saved. Secrets are encrypted and shown masked.'));
        $this->load();
    }

    public function testConnection(IntegrationManager $manager): void
    {
        $this->need('integrations.view');
        $res = $this->attempt(fn () => $manager->call($this->provider, 'health_check', ['probe' => 'ping'], function ($req, $setting) {
            if (! $setting || empty($setting->base_url)) {
                return new ProviderResponse('failure', [], 'NOT_CONFIGURED', 'Base URL is not configured.');
            }

            return new ProviderResponse('success', ['status' => 'UP', 'note' => 'SIMULATED health check – no network call made'], providerRef: 'HC-'.strtoupper(substr(md5($setting->provider.now()->format('YmdHi')), 0, 8)));
        }));
        if ($res instanceof ProviderResponse) {
            $this->toast($res->successful() ? __('Simulated health check: UP (:ms ms logged).', ['ms' => IntegrationLog::find($res->logId)?->duration_ms]) : ($res->message ?? __('Health check failed.')), $res->successful() ? 'green' : 'red');
        }
    }

    public function render()
    {
        $this->need('integrations.view');
        $s = $this->setting();
        $base = IntegrationLog::where('tenant_id', TenantContext::id())->where('provider', $this->provider);
        $stats = (clone $base)->where('created_at', '>=', now()->subDays(30))->selectRaw("COUNT(*) total, SUM(status='success') ok, SUM(status IN ('failure','error','timeout')) bad, AVG(duration_ms) avg_ms")->first();
        $overview = IntegrationSetting::orderBy('provider')->get()->keyBy('provider');

        return view('livewire.ops.admin.integrations', [
            'providers' => config('integrations.providers'),
            'def' => config("integrations.providers.{$this->provider}"),
            'setting' => $s,
            'overview' => $overview,
            'stats' => $stats,
            'logs' => (clone $base)->latest('id')->take(15)->get(),
            'secretFields' => config('integrations.secret_fields'),
            'webhook' => route('webhooks', [TenantContext::require()->code, $this->provider]),
            'canManage' => auth()->user()->hasPermission('integrations.manage'),
            'canGoLive' => auth()->user()->hasPermission('integrations.go_live'),
        ])->title(__('Integration settings'));
    }
}
