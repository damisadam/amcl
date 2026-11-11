<?php

namespace App\Enums;

enum LoanPaymentStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Reversed => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'pending' => [self::Succeeded, self::Failed],
            'succeeded' => [self::Reversed],
            'failed' => [],
            'reversed' => [],
        ];
    }
}
