<?php

namespace App\Services\Platform;

use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;

/** Platform-side tenant lifecycle: profile/branding, entitlements, status, initial admin credentials. */
class TenantAdministration
{
    public const EDITABLE = ['name', 'legal_name', 'registration_no', 'license_ref', 'ntn', 'primary_contact_name', 'primary_contact_email', 'primary_contact_phone',
        'address', 'city', 'support_phone', 'support_email', 'primary_color', 'tagline', 'tagline_ur', 'default_locale', 'is_demo', 'wallet_safeguarding_model'];

    public function update(Tenant $tenant, array $data, User $actor): Tenant
    {
        $this->assertPermission($actor, 'platform.tenants.manage');
        $tenant->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
        $dirty = $tenant->getDirty();
        if ($dirty) {
            Auditor::record('platform.tenant_updated', $tenant, array_intersect_key($tenant->getOriginal(), $dirty), $dirty, tenantId: $tenant->id);
            $tenant->save();
        }

        return $tenant;
    }

    public function setFeatures(Tenant $tenant, array $features, User $actor): Tenant
    {
        $this->assertPermission($actor, 'platform.tenants.manage');
        $features = array_values(array_intersect($features, array_keys(Tenant::FEATURES)));
        $old = $tenant->features ?? [];
        $tenant->features = $features;
        $tenant->save();
        Auditor::record('platform.tenant_features_changed', $tenant, ['features' => $old], ['features' => $features], tenantId: $tenant->id);

        return $tenant;
    }

    public function changeStatus(Tenant $tenant, TenantStatus $to, string $reason, User $actor): Tenant
    {
        $this->assertPermission($actor, 'platform.tenants.suspend');
        if ($tenant->status === $to) {
            return $tenant;
        }
        if ($to !== TenantStatus::Active && strlen(trim($reason)) < 5) {
            throw new DomainException('Give a reason (at least 5 characters) for suspending or deactivating a tenant.');
        }
        $from = $tenant->status;
        $tenant->forceFill([
            'status' => $to,
            'suspended_at' => $to === TenantStatus::Active ? null : now(),
            'suspension_reason' => $to === TenantStatus::Active ? null : $reason,
            'activated_at' => $to === TenantStatus::Active ? now() : $tenant->activated_at,
        ])->save();
        Auditor::record('platform.tenant_status_changed', $tenant, ['status' => $from->value], ['status' => $to->value], $reason ?: null, tenantId: $tenant->id);

        return $tenant;
    }

    /** The tenant's administrator accounts (users holding the tenant_admin role). */
    public function admins(Tenant $tenant)
    {
        $roleId = Role::where('tenant_id', $tenant->id)->where('key', 'tenant_admin')->value('id');

        return TenantContext::bypass(fn () => User::where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId))->orderBy('id')->get());
    }

    /** Reset the initial administrator's password; the temporary password is returned once and never stored in clear. */
    public function resetAdminPassword(Tenant $tenant, User $actor): array
    {
        $this->assertPermission($actor, 'platform.tenants.manage');
        $admin = $this->admins($tenant)->first() ?? throw new DomainException('This tenant has no administrator account.');
        $temp = 'Adm#'.random_int(100000, 999999).'xY';
        $admin->forceFill(['password' => $temp, 'must_change_password' => true, 'failed_login_attempts' => 0, 'locked_until' => null,
            'status' => $admin->status === UserStatus::Locked ? UserStatus::Active : $admin->status])->save();
        Auditor::record('platform.tenant_admin_password_reset', $admin, [], ['admin_email' => $admin->email], tenantId: $tenant->id);

        return ['email' => $admin->email, 'password' => $temp];
    }

    private function assertPermission(User $actor, string $perm): void
    {
        if (! $actor->isPlatform() || ! $actor->hasPermission($perm)) {
            throw new DomainException('You are not authorized for this platform action.');
        }
    }
}
