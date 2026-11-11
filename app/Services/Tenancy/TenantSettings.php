<?php

namespace App\Services\Tenancy;

use App\Models\TenantSetting;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Arr;

/**
 * Typed access to per-tenant configuration with documented defaults.
 * Every setting change is audited.
 */
class TenantSettings
{
    /** group => key => default */
    public const DEFAULTS = [
        'lending' => [
            'reapplication_wait_days' => 30,          // LMS §6.10
            'default_cooling_off_hours' => 24,        // DFS §6.1 / LMS §6.6
            'default_max_dbr' => 40,                  // DFS §2.2
            'prevent_multi_stage_same_approver' => true,
            'kfs_required_before_signing' => true,
            'collection_contact_start' => '07:00',    // DFS §6.5
            'collection_contact_end' => '22:00',
            'reminder_days_before_due' => [5, 1, 0],  // LMS §8 (6, 6.1)
            'provisioning_rates' => ['regular' => 0, 'oaem' => 0, 'substandard' => 25, 'doubtful' => 50, 'loss' => 100],
            'classification_dpd' => ['oaem' => 30, 'substandard' => 90, 'doubtful' => 180, 'loss' => 365],
        ],
        'wallet' => [
            'default_daily_limit' => '50000.00',
            'default_per_transaction_limit' => '25000.00',
            'default_monthly_limit' => '200000.00',
            'default_max_balance' => '200000.00',
            'require_pin_for_debits' => true,
            'allow_cross_tenant_transfers' => false,  // never enabled by default (DECISIONS D-003)
        ],
        'security' => [
            'password_min_length' => 10,
            'max_failed_logins' => 5,
            'lockout_minutes' => 30,
            'password_expiry_days' => 90,
            'staff_mfa_required' => true,             // TOTP MFA for tenant admins and privileged staff (S1)
            'otp_ttl_minutes' => 5,
        ],
        'finance' => [
            'fiscal_year_start_month' => 7,           // Pakistan FY: July–June
            'wht_rate_vendor_goods' => 4.5,
            'wht_rate_services' => 8.0,
            'journal_requires_approval' => true,
            'vendor_payment_requires_approval' => true,
            'reconciliation_requires_approval' => true,
        ],
        'working_days' => [
            'days' => [1, 2, 3, 4, 5],                // Mon–Fri (ISO-8601 day numbers)
        ],
        'complaints' => [
            'acknowledge_hours' => 24,
            'resolution_days' => 10,
        ],
    ];

    public static function get(string $group, string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $tenantId ??= TenantContext::id();
        $row = $tenantId ? TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('group', $group)->where('key', $key)->first() : null;

        return $row?->value ?? $default ?? Arr::get(self::DEFAULTS, "$group.$key");
    }

    public static function set(string $group, string $key, mixed $value, ?int $tenantId = null, bool $audit = true): void
    {
        $tenantId ??= TenantContext::id() ?? throw new \RuntimeException('No tenant context.');
        $row = TenantSetting::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenantId, 'group' => $group, 'key' => $key]);
        $old = $row->value;
        $row->tenant_id = $tenantId;
        $row->value = $value;
        $row->save();
        if ($audit && $old !== $value) {
            Auditor::record('settings.updated', $row, ['value' => $old], ['value' => $value], tenantId: $tenantId);
        }
    }

    public static function group(string $group, ?int $tenantId = null): array
    {
        $out = self::DEFAULTS[$group] ?? [];
        foreach (array_keys($out) as $key) {
            $out[$key] = self::get($group, $key, null, $tenantId);
        }

        return $out;
    }
}
