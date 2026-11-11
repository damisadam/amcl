<?php

namespace App\Enums;

enum JournalStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'amber',
            self::Posted => 'green',
            self::Rejected => 'red',
            self::Reversed => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'draft' => [self::PendingApproval, self::Posted],
            'pending_approval' => [self::Posted, self::Rejected, self::Draft],
            'rejected' => [self::Draft],
            'posted' => [self::Reversed],
            'reversed' => [],
        ];
    }
}
