<?php

namespace App\Models;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * One identity table for all user types. tenant_id is null only for platform users.
 * Tenant scoping for users is enforced explicitly (they authenticate before a tenant context exists),
 * via App\Models\Scopes and the tenant() relationship checks in middleware and policies.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = ['id', 'tenant_id', 'type', 'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_enabled', 'mfa_confirmed_at'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    private ?Collection $permissionCache = null;

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // When a tenant context is active, users from other tenants (and platform users) are invisible.
        static::addGlobalScope('tenant', function ($query) {
            if (TenantContext::has() && ! TenantContext::isBypassed()) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), TenantContext::id());
            }
        });
    }

    /** Users visible in tenant-facing screens: always the current tenant's users only. */
    public function scopeInCurrentTenant($query)
    {
        return $query->where('tenant_id', TenantContext::id() ?? 0);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot(['assigned_by', 'assigned_at']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isPlatform(): bool { return $this->type === UserType::Platform; }
    public function isStaff(): bool { return $this->type === UserType::Staff; }
    public function isCustomer(): bool { return $this->type === UserType::Customer; }
    public function isVendor(): bool { return $this->type === UserType::Vendor; }

    /** @return Collection<int,string> permission keys granted through all of this user's roles */
    public function permissionKeys(): Collection
    {
        return $this->permissionCache ??= Permission::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $this->roles()->pluck('roles.id')))
            ->pluck('key');
    }

    public function hasPermission(string $key): bool
    {
        return $this->status === UserStatus::Active && $this->permissionKeys()->contains($key);
    }

    public function hasAnyPermission(array $keys): bool
    {
        return $this->status === UserStatus::Active && $this->permissionKeys()->intersect($keys)->isNotEmpty();
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains('key', $key);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    public function isLocked(): bool
    {
        return $this->status === UserStatus::Locked || ($this->locked_until && $this->locked_until->isFuture());
    }

    public function roleNames(): string
    {
        return $this->roles->pluck('name')->join(', ');
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('');
    }
}
