<?php

namespace App\Services\Platform;

use App\Exceptions\DomainException;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Exceptional platform support access to a tenant (D-005): requested with a justification,
 * granted by a DIFFERENT platform user, time-limited (≤ 24h), revocable, and every use audited.
 * The support view exposes operational summaries only – never customer identities or balances.
 */
class SupportAccessService
{
    public const MAX_HOURS = 24;

    public function request(Tenant $tenant, User $requester, string $reason, ?string $ticket, int $hours): SupportAccessGrant
    {
        if (! $requester->isPlatform() || ! $requester->hasPermission('platform.support_access.request')) {
            throw new DomainException('You are not authorized to request support access.');
        }
        if (strlen(trim($reason)) < 15) {
            throw new DomainException('Explain why access is needed (at least 15 characters).');
        }
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new DomainException('Access duration must be between 1 and '.self::MAX_HOURS.' hours.');
        }
        if (SupportAccessGrant::where('tenant_id', $tenant->id)->where('platform_user_id', $requester->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('granted_at')->orWhere('expires_at', '>', now()))->exists()) {
            throw new DomainException('You already have a pending or active request for this tenant.');
        }
        $g = SupportAccessGrant::create([
            'tenant_id' => $tenant->id, 'platform_user_id' => $requester->id, 'reason' => $reason, 'ticket_ref' => $ticket,
            'duration_hours' => $hours, 'expires_at' => now()->addDays(2), // request itself lapses if not granted within 48h
        ]);
        Auditor::record('platform.support_access_requested', $g, [], ['tenant' => $tenant->code, 'hours' => $hours, 'ticket' => $ticket], $reason, tenantId: $tenant->id);

        return $g;
    }

    public function grant(SupportAccessGrant $g, User $approver): SupportAccessGrant
    {
        if (! $approver->isPlatform() || ! $approver->hasPermission('platform.support_access.grant')) {
            throw new DomainException('You are not authorized to grant support access.');
        }
        if ((int) $g->platform_user_id === (int) $approver->id) {
            throw new DomainException('Segregation of duties: you cannot grant your own support access request.');
        }
        if ($g->granted_at || $g->revoked_at || $g->expires_at->isPast()) {
            throw new DomainException('This request is no longer pending.');
        }
        $g->update(['granted_by' => $approver->id, 'granted_at' => now(), 'expires_at' => now()->addHours($g->duration_hours)]);
        Auditor::record('platform.support_access_granted', $g, [], ['expires_at' => $g->expires_at->toDateTimeString()], tenantId: $g->tenant_id);

        return $g;
    }

    public function revoke(SupportAccessGrant $g, User $actor, string $reason = 'Revoked'): SupportAccessGrant
    {
        $own = (int) $g->platform_user_id === (int) $actor->id;
        if (! $actor->isPlatform() || (! $own && ! $actor->hasPermission('platform.support_access.grant'))) {
            throw new DomainException('You are not authorized to revoke this access.');
        }
        if ($g->revoked_at) {
            return $g;
        }
        $g->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        Auditor::record('platform.support_access_revoked', $g, [], [], $reason, tenantId: $g->tenant_id);

        return $g;
    }

    public function isActive(SupportAccessGrant $g): bool
    {
        return $g->granted_at !== null && $g->revoked_at === null && $g->expires_at->isFuture();
    }

    public function status(SupportAccessGrant $g): string
    {
        return match (true) {
            $g->revoked_at !== null => 'revoked',
            $g->granted_at === null && $g->expires_at->isPast() => 'lapsed',
            $g->granted_at === null => 'pending',
            $g->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    /** Read-only operational summary for an active grant held by $user. Each call is audited. */
    public function supportView(SupportAccessGrant $g, User $user): array
    {
        if ((int) $g->platform_user_id !== (int) $user->id || ! $this->isActive($g)) {
            throw new DomainException('No active support access grant.');
        }
        Auditor::record('platform.support_view_accessed', $g, [], ['grant_id' => $g->id], tenantId: $g->tenant_id);
        $tenant = Tenant::findOrFail($g->tenant_id);

        return TenantContext::run($tenant, fn () => [
            'applications_by_status' => DB::table('loan_applications')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
                ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all(),
            'loans_by_status' => DB::table('loan_accounts')->where('tenant_id', $tenant->id)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all(),
            'open_complaints' => DB::table('complaints')->where('tenant_id', $tenant->id)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'overdue_complaints' => DB::table('complaints')->where('tenant_id', $tenant->id)->whereNotIn('status', ['resolved', 'closed'])->where('sla_due_at', '<', now())->count(),
            'pending_payments' => DB::table('payment_orders')->where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'timed_out'])->count(),
            'integration_errors' => DB::table('integration_logs')->where('tenant_id', $tenant->id)->whereIn('status', ['failure', 'error', 'timeout'])
                ->where('created_at', '>=', now()->subDay())->selectRaw('provider, error_code, COUNT(*) c')->groupBy('provider', 'error_code')->get()->all(),
            'users_by_status' => DB::table('users')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')->all(),
        ]);
    }
}
