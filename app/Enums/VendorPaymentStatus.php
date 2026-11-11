<?php

namespace App\Enums;

enum VendorPaymentStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'amber',
            self::Approved => 'blue',
            self::Processing => 'blue',
            self::Paid => 'green',
            self::Failed => 'red',
            self::Rejected => 'red',
            self::Reversed => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'draft' => [self::PendingApproval, self::Cancelled],
            'pending_approval' => [self::Approved, self::Rejected, self::Draft],
            'approved' => [self::Processing, self::Cancelled],
            'processing' => [self::Paid, self::Failed],
            'failed' => [self::Approved, self::Cancelled],
            'paid' => [self::Reversed],
            'rejected' => [],
            'reversed' => [],
            'cancelled' => [],
        ];
    }
}
