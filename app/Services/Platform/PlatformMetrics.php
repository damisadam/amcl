<?php

namespace App\Services\Platform;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant operational metrics for platform administrators. Produces COUNTS and health
 * indicators only – never customer-level rows, identities, balances or books.
 */
class PlatformMetrics
{
    /** @return array<int, array> keyed by tenant id */
    public function tenantHealth(): array
    {
        return TenantContext::bypass(function () {
            $count = fn (string $table, array $where = []) => DB::table($table)->when($where, fn ($q) => $q->where($where))
                ->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            // Staff and vendor logins only; customers are counted separately (M21).
            $users = DB::table('users')->whereNotNull('tenant_id')->whereNull('deleted_at')->whereIn('type', ['staff', 'vendor'])->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            $customers = $count('customers');
            $loans = DB::table('loan_accounts')->whereIn('status', ['active', 'overdue'])->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            $pending = DB::table('payment_orders')->whereIn('status', ['pending', 'timed_out'])->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            $rejectedCallbacks = DB::table('payment_callbacks')->where('result', 'rejected')->where('received_at', '>=', now()->subDay())
                ->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
            $lastActivity = DB::table('audit_logs')->whereNotNull('tenant_id')->selectRaw('tenant_id, MAX(created_at) m')->groupBy('tenant_id')->pluck('m', 'tenant_id');
            $integrationErrors = DB::table('integration_logs')->whereIn('status', ['failure', 'error', 'timeout'])->where('created_at', '>=', now()->subDay())
                ->selectRaw('tenant_id, COUNT(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');

            $out = [];
            foreach (Tenant::orderBy('id')->get() as $t) {
                $out[$t->id] = [
                    'tenant' => $t,
                    'users' => (int) ($users[$t->id] ?? 0),
                    'customers' => (int) ($customers[$t->id] ?? 0),
                    'active_loans' => (int) ($loans[$t->id] ?? 0),
                    'pending_payments' => (int) ($pending[$t->id] ?? 0),
                    'rejected_callbacks_24h' => (int) ($rejectedCallbacks[$t->id] ?? 0),
                    'integration_errors_24h' => (int) ($integrationErrors[$t->id] ?? 0),
                    'last_activity' => $lastActivity[$t->id] ?? null,
                ];
            }

            return $out;
        });
    }

    /** Integration health per provider (optionally per tenant) over the last N hours. */
    public function integrationHealth(int $hours = 24, bool $perTenant = false): array
    {
        $rows = DB::table('integration_logs')->where('created_at', '>=', now()->subHours($hours))
            ->selectRaw(($perTenant ? 'tenant_id, ' : '')."provider, COUNT(*) total,
                SUM(status = 'success') ok, SUM(status IN ('failure','error')) failed, SUM(status = 'timeout') timeouts,
                SUM(status = 'pending') pending, AVG(duration_ms) avg_ms, SUM(mode = 'demo') demo, SUM(mode = 'live') live")
            ->groupBy($perTenant ? ['tenant_id', 'provider'] : ['provider'])->orderBy('provider')->get();

        return $rows->map(function ($r) {
            $total = max(1, (int) $r->total);

            return (array) $r + [
                'success_pct' => round(100 * $r->ok / $total, 1),
                'failure_pct' => round(100 * $r->failed / $total, 1),
                'timeout_pct' => round(100 * $r->timeouts / $total, 1),
            ];
        })->all();
    }

    /** Recent integration errors WITHOUT request/response payloads. */
    public function recentErrors(int $limit = 25): array
    {
        return DB::table('integration_logs')->leftJoin('tenants', 'tenants.id', '=', 'integration_logs.tenant_id')
            ->whereIn('integration_logs.status', ['failure', 'error', 'timeout'])
            ->orderByDesc('integration_logs.id')->limit($limit)
            ->get(['integration_logs.id', 'tenants.name as tenant', 'integration_logs.provider', 'integration_logs.operation', 'integration_logs.status',
                'integration_logs.error_code', 'integration_logs.mode', 'integration_logs.duration_ms', 'integration_logs.correlation_id', 'integration_logs.created_at'])
            ->all();
    }

    /** Demo/live configuration matrix: tenant × provider → mode/enabled. Never credentials. */
    public function configMatrix(): array
    {
        $rows = DB::table('integration_settings')->get(['tenant_id', 'provider', 'enabled', 'mode', 'environment', 'live_approved_at']);
        $matrix = [];
        foreach ($rows as $r) {
            $matrix[$r->tenant_id][$r->provider] = ['enabled' => (bool) $r->enabled, 'mode' => $r->mode, 'environment' => $r->environment, 'live_approved' => $r->live_approved_at !== null];
        }

        return $matrix;
    }

    public function modeCounts(): array
    {
        return DB::table('integration_settings')->selectRaw('mode, COUNT(*) c')->groupBy('mode')->pluck('c', 'mode')->all();
    }

    /** Platform administrators with MFA state and last sign-in (no tenant data). */
    public function platformUsers(): array
    {
        return \App\Models\User::withoutGlobalScopes()->with('roles')->whereNull('tenant_id')->where('type', 'platform')->orderBy('name')->get()
            ->map(fn ($u) => ['user' => $u, 'mfa' => \App\Services\Identity\Mfa::enrolled($u), 'last_login' => $u->last_login_at])->all();
    }

    public function failedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    public function featureMatrix(): array
    {
        return Tenant::orderBy('id')->get()->mapWithKeys(fn ($t) => [$t->id => ['tenant' => $t, 'features' => $t->features ?? []]])->all();
    }

    /** Platform-level audit: platform actors, platform.* actions, or events without a tenant. */
    public static function platformAuditQuery()
    {
        return AuditLog::query()->where(fn ($q) => $q->where('actor_type', 'platform')->orWhere('action', 'like', 'platform.%')->orWhereNull('tenant_id'));
    }
}
