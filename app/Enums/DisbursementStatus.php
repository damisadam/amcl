<?php

namespace App\Enums;

enum DisbursementStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Initiated = 'initiated';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Reversed = 'reversed';

    public function tone(): string
    {
        return match ($this) {
            self::Initiated => 'blue',
            self::Processing => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Reversed => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'initiated' => [self::Processing, self::Succeeded, self::Failed],
            'processing' => [self::Succeeded, self::Failed],
            'succeeded' => [self::Reversed],
            'failed' => [],
            'reversed' => [],
        ];
    }
}
