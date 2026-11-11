<?php

namespace App\Enums;

enum CustomerStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Suspended => 'red',
            self::Archived => 'gray',
        };
    }
}
