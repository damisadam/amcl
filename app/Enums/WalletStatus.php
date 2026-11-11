<?php

namespace App\Enums;

enum WalletStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Pending = 'pending';
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Restricted => 'amber',
            self::Suspended => 'red',
            self::Closed => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'pending' => [self::Active, self::Closed],
            'active' => [self::Restricted, self::Suspended, self::Closed],
            'restricted' => [self::Active, self::Suspended, self::Closed],
            'suspended' => [self::Active, self::Closed],
            'closed' => [],
        ];
    }
}
