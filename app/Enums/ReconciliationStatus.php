<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Draft = 'draft';
    case InReview = 'in_review';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InReview => 'blue',
            self::PendingApproval => 'amber',
            self::Approved => 'green',
        };
    }

    public static function transitions(): array
    {
        return [
            'draft' => [self::InReview],
            'in_review' => [self::PendingApproval, self::Approved],
            'pending_approval' => [self::Approved, self::InReview],
            'approved' => [],
        ];
    }
}
