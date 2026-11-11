<?php

namespace App\Enums;

enum AmlStatus: string
{
    use EnumHelpers;

    case NotScreened = 'not_screened';
    case Clear = 'clear';
    case PotentialMatch = 'potential_match';
    case ConfirmedMatch = 'confirmed_match';
    case ClearedAfterReview = 'cleared_after_review';

    public function tone(): string
    {
        return match ($this) {
            self::NotScreened => 'gray',
            self::Clear => 'green',
            self::PotentialMatch => 'amber',
            self::ConfirmedMatch => 'red',
            self::ClearedAfterReview => 'green',
        };
    }
}
