<?php

namespace App\Services\Payments;

use App\Services\Audit\Auditor;
use App\Support\TenantContext;

/**
 * Gate for simulation controls (QA S5). Simulated outcomes may be chosen only when the platform runs in demo
 * mode AND the current tenant is a demo tenant; every non-default choice is audited ('simulation.used').
 */
final class Simulation
{
    public static function enabled(): bool
    {
        $tenant = TenantContext::get();
        if (! config('integrations.demo_mode') || ! $tenant) {
            return false;
        }
        // A freshly created model may not carry the column default; read it from the database then.
        $isDemo = $tenant->getAttribute('is_demo') ?? \App\Models\Tenant::whereKey($tenant->id)->value('is_demo');

        return (bool) $isDemo;
    }

    /** Returns the scenario to use (null = provider default) and audits forced outcomes. */
    public static function scenario(?string $scenario, string $context, mixed $subject = null): ?string
    {
        if (! self::enabled() || $scenario === null || $scenario === '') {
            return null;
        }
        if (! in_array($scenario, ['success', 'auto'], true)) {
            self::audit($context, $scenario, $subject);
        }

        return $scenario;
    }

    public static function audit(string $context, string $choice, mixed $subject = null): void
    {
        Auditor::record('simulation.used', $subject instanceof \Illuminate\Database\Eloquent\Model ? $subject : null, [], ['context' => $context, 'choice' => $choice]);
    }
}
