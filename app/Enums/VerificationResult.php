<?php

namespace App\Enums;

enum VerificationResult: string
{
    use EnumHelpers;

    case Passed = 'passed';
    case Failed = 'failed';
    case ManualReview = 'manual_review';
    case Pending = 'pending';

    public function tone(): string
    {
        return match ($this) {
            self::Passed => 'green',
            self::Failed => 'red',
            self::ManualReview => 'amber',
            self::Pending => 'blue',
        };
    }
}
