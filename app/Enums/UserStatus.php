<?php

namespace App\Enums;

enum UserStatus: string
{
    use EnumHelpers;

    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Locked = 'locked';
    case Archived = 'archived';

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Suspended => 'amber',
            self::Locked => 'red',
            self::Archived => 'gray',
        };
    }
}
