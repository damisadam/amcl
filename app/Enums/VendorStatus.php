<?php

namespace App\Enums;

enum VendorStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
    case Rejected = 'rejected';
    case Blacklisted = 'blacklisted';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Inactive => 'gray',
            self::Rejected => 'red',
            self::Blacklisted => 'red',
        };
    }
}
