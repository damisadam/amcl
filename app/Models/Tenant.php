<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /** Feature entitlements a tenant can be granted by the platform. */
    public const FEATURES = [
        'lms' => 'Loan Management System',
        'dfs' => 'Digital Financing (customer portal)',
        'wallet' => 'Digital Wallet',
        'fms' => 'Finance Management System',
        'vendor_portal' => 'Vendor Portal',
        'staff_lending' => 'Corporate / Staff Lending',
        'bill_payments' => 'Utility Bill Payments (1Bill)',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'features' => 'array',
            'is_demo' => 'boolean',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
