<?php

namespace App\Enums;

enum FieldVisitStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'amber',
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'scheduled' => [self::InProgress, self::Completed, self::Cancelled],
            'in_progress' => [self::Completed, self::Cancelled],
            'completed' => [],
            'cancelled' => [],
        ];
    }
}
