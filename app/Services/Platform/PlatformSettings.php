<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use App\Services\Audit\Auditor;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;

/** Platform-wide settings (not tenant data). Every change is audited. */
class PlatformSettings
{
    public static function defaults(): array
    {
        return [
            'platform_name' => 'AMCL Digital Financing Platform',
            'default_features' => TenantProvisioner::DEFAULT_FEATURES,
            'demo_banner_text' => 'DEMO ENVIRONMENT – all banks, NADRA, Tasdeeq, Raast, wallets and e-signatures are SIMULATED. No real money moves.',
            'maintenance_notice' => '',
        ];
    }

    public static function get(string $key): mixed
    {
        $row = DB::table('platform_settings')->where('key', $key)->value('value');

        return $row !== null ? json_decode($row, true) : (self::defaults()[$key] ?? null);
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::defaults()) as $k) {
            $out[$k] = self::get($k);
        }

        return $out;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        if (! array_key_exists($key, self::defaults())) {
            throw new \InvalidArgumentException("Unknown platform setting [$key].");
        }
        if ($key === 'default_features') {
            $value = array_values(array_intersect((array) $value, array_keys(Tenant::FEATURES)));
        }
        $old = self::get($key);
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
        if ($old !== $value) {
            Auditor::record('platform.settings_updated', null, [$key => $old], [$key => $value]);
        }
    }
}
