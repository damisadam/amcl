<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/** Builds permission-, feature- and route-filtered navigation for each portal. */
final class Navigation
{
    public static function ops(User $user): array
    {
        $tenant = TenantContext::get();
        $out = [];
        foreach (config('navigation.ops') as $section => $items) {
            $visible = array_values(array_filter($items, fn ($i) => Route::has($i[1])
                && ($i[4] === null || $tenant?->hasFeature($i[4]))
                && ($i[2] === null || $user->hasAnyPermission($i[2]))));
            if ($visible) {
                $out[$section] = $visible;
            }
        }

        return $out;
    }

    public static function customer(): array
    {
        $tenant = TenantContext::get();

        return array_values(array_filter(config('navigation.customer'), fn ($i) => Route::has($i[1]) && ($i[3] === null || $tenant?->hasFeature($i[3]))));
    }

    public static function platform(User $user): array
    {
        return array_values(array_filter(config('navigation.platform'), fn ($i) => Route::has($i[1]) && $user->hasAnyPermission($i[2])));
    }

    public static function vendor(): array
    {
        return array_values(array_filter(config('navigation.vendor'), fn ($i) => Route::has($i[1])));
    }
}
