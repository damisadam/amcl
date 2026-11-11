<?php

namespace App\Services\Integrations;

use App\Exceptions\DomainException;
use App\Models\IntegrationLog;
use App\Models\IntegrationSetting;
use App\Services\Audit\Auditor;
use App\Support\Correlation;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Centralised API Management Layer (Agreement §9.2): resolves the tenant's provider settings,
 * enforces enabled/demo/live mode, executes the adapter, and writes a masked request/response
 * log with a correlation id. Release 1 ships only simulated adapters; live mode is blocked.
 */
class IntegrationManager
{
    public function setting(string $provider): ?IntegrationSetting
    {
        return IntegrationSetting::where('provider', $provider)->first();
    }

    /**
     * Execute a provider operation.
     *
     * @param  callable(array $request, ?IntegrationSetting $setting): ProviderResponse  $simulator
     */
    public function call(string $provider, string $operation, array $request, callable $simulator, ?Model $subject = null, ?string $idempotencyKey = null): ProviderResponse
    {
        $config = config("integrations.providers.$provider") ?? throw new \InvalidArgumentException("Unknown provider [$provider]");
        $setting = $this->setting($provider);

        if ($setting && ! $setting->enabled) {
            throw new DomainException("{$config['name']} is disabled for this institution. Enable it under Integration Settings.");
        }
        if ($setting && $setting->mode === 'live') {
            // Live adapters are intentionally not part of release 1 (see docs/INTEGRATIONS.md).
            return $this->log($provider, $operation, 'live', $request, new ProviderResponse('error', [], 'LIVE_ADAPTER_NOT_AVAILABLE',
                "Live mode for {$config['name']} is configured but no certified live adapter is installed.", simulated: false), $subject, $idempotencyKey, 0);
        }

        $started = hrtime(true);
        try {
            $response = $simulator($request, $setting);
        } catch (\Throwable $e) {
            report($e);
            $response = new ProviderResponse('error', [], 'ADAPTER_EXCEPTION', 'Provider adapter error.');
        }
        $ms = (int) ((hrtime(true) - $started) / 1_000_000) + $this->simulatedLatency($provider, $operation);

        return $this->log($provider, $operation, 'demo', $request, $response, $subject, $idempotencyKey, $ms);
    }

    /** Messaging helper used by the Notifier. */
    public function message(string $channel, string $to, ?string $subject, string $body): ProviderResponse
    {
        return $this->call($channel, 'send', ['to' => $to, 'subject' => $subject, 'body' => Str::limit($body, 160)], function ($req) use ($channel) {
            // Deterministic: WhatsApp to numbers ending in 0 "fails" so SMS fallback can be demonstrated.
            if ($channel === 'whatsapp' && str_ends_with($req['to'], '0')) {
                return new ProviderResponse('failure', [], 'NOT_ON_WHATSAPP', 'Recipient not reachable on WhatsApp (simulated).');
            }

            return new ProviderResponse('success', ['queued' => true], providerRef: strtoupper($channel).'-'.Str::upper(Str::random(10)));
        });
    }

    /** Masked view of credentials for UI display. */
    public function masked(?IntegrationSetting $setting): array
    {
        $secret = config('integrations.secret_fields');
        $out = [];
        foreach (($setting?->credentials ?? []) as $k => $v) {
            if ($v === null || $v === '') {
                $out[$k] = '';
            } elseif (in_array($k, $secret, true)) {
                $out[$k] = '••••••••'.substr((string) $v, -4);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public function updateCredentials(IntegrationSetting $setting, array $input, array $options): IntegrationSetting
    {
        $current = $setting->credentials ?? [];
        $changed = [];
        foreach ($input as $k => $v) {
            if ($v === null || str_starts_with((string) $v, '••••')) {
                continue; // untouched masked value
            }
            if (($current[$k] ?? null) !== $v) {
                $changed[] = $k;
                $current[$k] = $v;
            }
        }
        $oldOptions = $setting->options ?? [];
        $setting->credentials = $current;
        $setting->options = array_merge($oldOptions, $options);
        $setting->updated_by = auth()->id();
        $setting->save();
        Auditor::record('integration.settings_updated', $setting, ['options' => $oldOptions], [
            'provider' => $setting->provider, 'credential_fields_changed' => $changed, 'options' => $setting->options,
        ]);

        return $setting;
    }

    private function log(string $provider, string $operation, string $mode, array $request, ProviderResponse $response, ?Model $subject, ?string $key, int $ms): ProviderResponse
    {
        $log = IntegrationLog::create([
            'tenant_id' => TenantContext::id(),
            'provider' => $provider,
            'operation' => $operation,
            'mode' => $mode,
            'correlation_id' => Correlation::id(),
            'idempotency_key' => $key,
            'scenario' => $request['scenario'] ?? null,
            'request' => $this->mask($request),
            'response' => $this->mask(['status' => $response->status, 'data' => $response->data, 'error_code' => $response->errorCode, 'message' => $response->message, 'provider_ref' => $response->providerRef]),
            'status' => $response->status,
            'error_code' => $response->errorCode,
            'duration_ms' => $ms,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
        $response->logId = $log->id;

        return $response;
    }

    private function mask(array $data): array
    {
        $secret = array_merge(config('integrations.secret_fields'), ['otp', 'pin', 'password', 'code']);
        array_walk_recursive($data, function (&$v, $k) use ($secret) {
            if (in_array($k, $secret, true)) {
                $v = '[REDACTED]';
            } elseif (in_array($k, ['cnic', 'subject_cnic'], true) && is_string($v) && strlen($v) > 6) {
                $v = substr($v, 0, 5).'*******'.substr($v, -1);
            }
        });

        return $data;
    }

    private function simulatedLatency(string $provider, string $operation): int
    {
        return 80 + (crc32($provider.$operation) % 400);
    }
}
