<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    use EnumHelpers;
    use StateMachine;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case PendingApproval = 'pending_approval';
    case Returned = 'returned';
    case Approved = 'approved';
    case KfsAcknowledged = 'kfs_acknowledged';
    case CoolingOff = 'cooling_off';
    case ReadyForDisbursement = 'ready_for_disbursement';
    case DisbursementInProgress = 'disbursement_in_progress';
    case Disbursed = 'disbursed';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this->value) {
            'kfs_acknowledged' => 'KFS Acknowledged',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'blue',
            self::UnderReview => 'blue',
            self::PendingApproval => 'amber',
            self::Returned => 'amber',
            self::Approved => 'green',
            self::KfsAcknowledged => 'green',
            self::CoolingOff => 'amber',
            self::ReadyForDisbursement => 'green',
            self::DisbursementInProgress => 'blue',
            self::Disbursed => 'green',
            self::Rejected => 'red',
            self::Withdrawn => 'gray',
            self::Cancelled => 'gray',
        };
    }

    public static function transitions(): array
    {
        return [
            'draft' => [self::Submitted, self::Withdrawn],
            'submitted' => [self::UnderReview, self::Returned, self::Rejected, self::Withdrawn],
            'under_review' => [self::PendingApproval, self::Returned, self::Rejected, self::Withdrawn],
            'pending_approval' => [self::Approved, self::Returned, self::Rejected, self::Withdrawn],
            'returned' => [self::Submitted, self::Withdrawn, self::Rejected],
            'approved' => [self::KfsAcknowledged, self::Cancelled],
            'kfs_acknowledged' => [self::CoolingOff, self::Cancelled],
            'cooling_off' => [self::ReadyForDisbursement, self::Cancelled],
            'ready_for_disbursement' => [self::DisbursementInProgress, self::Cancelled],
            'disbursement_in_progress' => [self::Disbursed, self::ReadyForDisbursement],
            'disbursed' => [],
            'rejected' => [],
            'withdrawn' => [],
            'cancelled' => [],
        ];
    }
}
