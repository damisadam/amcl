<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Per-request / per-job correlation id that ties audit, integration and payment records together. */
final class Correlation
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= (string) Str::ulid();
    }

    public static function set(?string $id): void
    {
        self::$id = $id;
    }

    public static function fresh(): string
    {
        return self::$id = (string) Str::ulid();
    }
}
