<?php

namespace App\Enums;

enum InstalmentStatus: string
{
    use EnumHelpers;

    case Pending = 'pending';
    case Due = 'due';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Due => 'amber',
            self::PartiallyPaid => 'amber',
            self::Paid => 'green',
            self::Overdue => 'red',
        };
    }
}
