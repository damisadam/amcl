<?php

namespace App\Enums;

enum TenantStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Suspended => 'amber',
            self::Inactive => 'gray',
        };
    }
}
