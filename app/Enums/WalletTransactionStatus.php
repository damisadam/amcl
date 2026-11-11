<?php

namespace App\Enums;

enum WalletTransactionStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';
    case Refunded = 'refunded';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Processing => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Reversed => 'gray',
            self::Refunded => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'pending' => [self::Processing, self::Succeeded, self::Failed],
            'processing' => [self::Succeeded, self::Failed],
            'succeeded' => [self::Reversed, self::Refunded],
            'failed' => [],
            'reversed' => [],
            'refunded' => [],
        ];
    }
}
