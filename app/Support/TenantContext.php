<?php

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Holds the tenant for the current request/job. Established ONLY from a trusted source:
 * the authenticated user's tenant, a validated tenant entry point (/t/{code}), or
 * explicitly by trusted system code (jobs, seeders, scheduled commands).
 *
 * Tenant-scoped queries fail closed: with no tenant and no explicit bypass they return nothing.
 */
final class TenantContext
{
    private static ?Tenant $tenant = null;

    private static int $bypassDepth = 0;

    public static function set(?Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function get(): ?Tenant
    {
        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenant?->id;
    }

    public static function require(): Tenant
    {
        return self::$tenant ?? throw new \RuntimeException('No tenant context established.');
    }

    public static function has(): bool
    {
        return self::$tenant !== null;
    }

    /** Run a callback inside a specific tenant's context, restoring the previous one afterwards. */
    public static function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = self::$tenant;
        self::$tenant = $tenant;
        try {
            return $callback($tenant);
        } finally {
            self::$tenant = $previous;
        }
    }

    /**
     * Run platform-level code that must see across tenants (provisioning, platform health metrics,
     * cross-tenant isolation tests). Never call this from tenant-facing request paths.
     */
    public static function bypass(Closure $callback): mixed
    {
        self::$bypassDepth++;
        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    public static function isBypassed(): bool
    {
        return self::$bypassDepth > 0;
    }

    public static function clear(): void
    {
        self::$tenant = null;
        self::$bypassDepth = 0;
    }
}
