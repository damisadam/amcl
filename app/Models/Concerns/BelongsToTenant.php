<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies mandatory tenant isolation to a model:
 *  - reads are scoped to the current tenant (fail closed when no tenant is set),
 *  - new records are stamped with the current tenant,
 *  - writes against another tenant's record are refused.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (TenantContext::isBypassed()) {
                return;
            }
            $column = $builder->getModel()->qualifyColumn('tenant_id');
            $id = TenantContext::id();
            $id === null ? $builder->whereRaw('1 = 0') : $builder->where($column, $id);
        });

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::id()
                    ?? throw new \RuntimeException('Cannot create '.class_basename($model).' without a tenant context.');
            }
            static::guardTenant($model);
        });

        static::updating(fn ($model) => static::guardTenant($model));
        static::deleting(fn ($model) => static::guardTenant($model));
    }

    protected static function guardTenant($model): void
    {
        if (TenantContext::isBypassed() || TenantContext::id() === null) {
            return;
        }
        if ((int) $model->tenant_id !== (int) TenantContext::id()) {
            throw new \App\Exceptions\TenantIsolationViolation(class_basename($model), $model->getKey());
        }
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
