<?php

namespace App\Enums;

enum KycStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case PendingReview = 'pending_review';
    case Passed = 'passed';
    case Failed = 'failed';

    public function tone(): string
    {
        return match ($this) {
            self::NotStarted => 'gray',
            self::InProgress => 'blue',
            self::PendingReview => 'amber',
            self::Passed => 'green',
            self::Failed => 'red',
        };
    }

    public static function transitions(): array
    {
        return [
            'not_started' => [self::InProgress],
            'in_progress' => [self::PendingReview, self::Passed, self::Failed],
            'pending_review' => [self::Passed, self::Failed, self::InProgress],
            'failed' => [self::InProgress],
            'passed' => [self::InProgress],
        ];
    }
}
