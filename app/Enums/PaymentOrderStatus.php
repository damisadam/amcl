<?php

namespace App\Enums;

enum PaymentOrderStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Created = 'created';
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function tone(): string
    {
        return match ($this) {
            self::Created => 'gray',
            self::Pending => 'amber',
            self::Processing => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::TimedOut => 'red',
            self::Reversed => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'created' => [self::Pending, self::Processing, self::Succeeded, self::Failed, self::TimedOut, self::Cancelled],
            'pending' => [self::Processing, self::Succeeded, self::Failed, self::TimedOut, self::Cancelled],
            'processing' => [self::Succeeded, self::Failed, self::TimedOut],
            'timed_out' => [self::Succeeded, self::Failed, self::Processing],
            'succeeded' => [self::Reversed],
            'failed' => [],
            'reversed' => [],
            'cancelled' => [],
        ];
    }
}
