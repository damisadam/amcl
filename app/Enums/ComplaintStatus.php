<?php

namespace App\Enums;

enum ComplaintStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Acknowledged => 'blue',
            self::InProgress => 'blue',
            self::Escalated => 'red',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'open' => [self::Acknowledged, self::InProgress, self::Escalated, self::Resolved],
            'acknowledged' => [self::InProgress, self::Escalated, self::Resolved],
            'in_progress' => [self::Escalated, self::Resolved],
            'escalated' => [self::InProgress, self::Resolved],
            'resolved' => [self::Closed, self::InProgress],
            'closed' => [],
        ];
    }
}
