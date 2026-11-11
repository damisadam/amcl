<?php

namespace App\Services\Identity;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant user administration (LMS §1.1–1.2). Guards: users are always created inside the current
 * tenant, only tenant roles can be assigned, an admin cannot change their own roles or status,
 * and the last active Tenant Admin can never be removed.
 */
class UserAdminService
{
    /** @return array{0: User, 1: string} the user and the one-time temporary password */
    public function create(array $data, array $roleIds, User $by): array
    {
        $tenantId = TenantContext::id() ?? throw new \RuntimeException('No tenant context.');
        $type = ($data['type'] ?? 'staff') === 'vendor' ? UserType::Vendor : UserType::Staff;
        if ($type === UserType::Vendor && ! Vendor::whereKey($data['vendor_id'] ?? 0)->exists()) {
            throw new DomainException('Select the vendor this portal user belongs to.');
        }
        $this->assertUnique($data['email'] ?? null, $data['mobile'] ?? null);

        return DB::transaction(function () use ($data, $roleIds, $by, $tenantId, $type) {
            $temp = 'Tmp#'.random_int(100000, 999999).'aA';
            $user = new User(array_intersect_key($data, array_flip(['name', 'email', 'mobile', 'designation', 'branch_id', 'department_id', 'employee_id', 'vendor_id'])));
            $user->forceFill(['tenant_id' => $tenantId, 'type' => $type, 'status' => UserStatus::Active, 'password' => $temp,
                'must_change_password' => true, 'created_by' => $by->id, 'password_changed_at' => now()])->save();
            if ($type === UserType::Staff) {
                $this->syncRoles($user, $roleIds, $by, audit: false);
            }
            Auditor::record('users.created', $user, [], ['email' => $user->email, 'type' => $type->value, 'roles' => $user->roles()->pluck('key')->all()]);

            return [$user, $temp];
        });
    }

    public function update(User $user, array $data, array $roleIds, User $by): User
    {
        $this->assertSameTenant($user);
        $this->assertUnique($data['email'] ?? null, $data['mobile'] ?? null, $user->id);

        return DB::transaction(function () use ($user, $data, $roleIds, $by) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email', 'mobile', 'designation', 'branch_id', 'department_id', 'employee_id'])));
            Auditor::changes('users.updated', $user);
            $user->save();
            if ($user->isStaff()) {
                $this->syncRoles($user, $roleIds, $by);
            }

            return $user;
        });
    }

    public function syncRoles(User $user, array $roleIds, User $by, bool $audit = true): void
    {
        $this->assertSameTenant($user);
        if ((int) $user->id === (int) $by->id && $audit) {
            $current = $user->roles()->pluck('roles.id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $wanted = collect($roleIds)->map(fn ($i) => (int) $i)->sort()->values()->all();
            if ($current !== $wanted) {
                throw new DomainException('You cannot change your own roles. Ask another administrator.');
            }

            return;
        }
        $roles = Role::where('tenant_id', TenantContext::id())->whereIn('id', $roleIds)->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new DomainException('Only roles of this institution can be assigned.');
        }
        if (! $roles->contains('key', 'tenant_admin') && $user->hasRole('tenant_admin')) {
            $this->assertNotLastAdmin($user);
        }
        $old = $user->roles()->pluck('key')->all();
        $user->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['assigned_by' => $by->id, 'assigned_at' => now()]])->all());
        $user->flushPermissionCache();
        $new = $roles->pluck('key')->all();
        if ($audit && array_diff($old, $new) + array_diff($new, $old)) {
            Auditor::record('users.roles_changed', $user, ['roles' => $old], ['roles' => $new]);
        }
    }

    public function changeStatus(User $user, UserStatus $to, string $reason, User $by): User
    {
        $this->assertSameTenant($user);
        if ((int) $user->id === (int) $by->id) {
            throw new DomainException('You cannot change the status of your own account.');
        }
        if ($to !== UserStatus::Active && $user->hasRole('tenant_admin')) {
            $this->assertNotLastAdmin($user);
        }
        $old = $user->status;
        $user->forceFill(['status' => $to, 'locked_until' => null, 'failed_login_attempts' => 0])->save();
        if ($to === UserStatus::Archived) {
            $user->delete();
        }
        Auditor::record('users.status_changed', $user, ['status' => $old->value], ['status' => $to->value], $reason);

        return $user;
    }

    public function unlock(User $user, User $by): void
    {
        $this->assertSameTenant($user);
        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0, 'status' => $user->status === UserStatus::Locked ? UserStatus::Active : $user->status])->save();
        Auditor::record('users.unlocked', $user);
    }

    private function assertNotLastAdmin(User $user): void
    {
        $admins = User::where('status', UserStatus::Active->value)->whereHas('roles', fn ($q) => $q->where('key', 'tenant_admin')->where('roles.tenant_id', TenantContext::id()))->count();
        if ($admins <= 1) {
            throw new DomainException('This is the last active Tenant Admin; assign another administrator first.');
        }
    }

    private function assertSameTenant(User $user): void
    {
        if ((int) $user->tenant_id !== (int) TenantContext::id()) {
            throw new \App\Exceptions\TenantIsolationViolation('User', $user->id);
        }
    }

    private function assertUnique(?string $email, ?string $mobile, ?int $ignore = null): void
    {
        if ($email && User::where('email', Str::lower($email))->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw new DomainException('Another user of this institution already uses that email.');
        }
        if ($mobile && User::where('mobile', $mobile)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw new DomainException('Another user of this institution already uses that mobile number.');
        }
    }
}
