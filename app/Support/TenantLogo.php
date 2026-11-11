<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Generated SVG badge logos for tenants without an uploaded logo (M21): initials on a coloured shape.
 * Stored as a data URI in tenants.logo_path so each institution is visually distinct everywhere.
 */
final class TenantLogo
{
    public const SHAPES = ['circle', 'rounded', 'hexagon', 'diamond', 'shield'];

    public static function svg(string $initials, string $color, string $shape = 'rounded'): string
    {
        $initials = htmlspecialchars(mb_strtoupper(mb_substr($initials, 0, 3)), ENT_QUOTES);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#15803d';
        $body = match ($shape) {
            'circle' => "<circle cx='32' cy='32' r='30' fill='{$color}'/>",
            'hexagon' => "<polygon points='32,2 58,17 58,47 32,62 6,47 6,17' fill='{$color}'/>",
            'diamond' => "<rect x='9' y='9' width='46' height='46' rx='8' transform='rotate(45 32 32)' fill='{$color}'/>",
            'shield' => "<path d='M32 2 L58 10 V30 C58 46 46 57 32 62 C18 57 6 46 6 30 V10 Z' fill='{$color}'/>",
            default => "<rect x='2' y='2' width='60' height='60' rx='14' fill='{$color}'/>",
        };
        $size = strlen(html_entity_decode($initials)) > 2 ? 17 : 22;

        return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'>{$body}<text x='32' y='39' text-anchor='middle' font-family='Inter,Arial,sans-serif' font-size='{$size}' font-weight='700' fill='#ffffff'>{$initials}</text></svg>";
    }

    public static function dataUri(string $initials, string $color, string $shape = 'rounded'): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($initials, $color, $shape));
    }

    /** Uploaded/generated logo URL, or a generated badge from the tenant name and colour. */
    public static function for(?Tenant $tenant): string
    {
        if ($tenant && $tenant->logo_path && (str_starts_with($tenant->logo_path, 'data:image/') || str_starts_with($tenant->logo_path, 'https://'))) {
            return $tenant->logo_path;
        }
        $name = $tenant?->name ?? 'AMCL';
        $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/\b(Demo|Ltd|Pvt)\b/i', '', $name))));
        $initials = count($words) > 1 ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1) : mb_substr($name, 0, 2);

        return self::dataUri($initials, $tenant->primary_color ?? '#15803d');
    }
}
