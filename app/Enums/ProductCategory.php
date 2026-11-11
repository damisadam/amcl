<?php

namespace App\Enums;

enum ProductCategory: string
{
    use EnumHelpers;

    case Motorbike = 'motorbike';
    case Rickshaw = 'rickshaw';
    case Laptop = 'laptop';
    case Scooty = 'scooty';
    case Solar = 'solar';
    case HomeAppliances = 'home_appliances';
    case SewingMachine = 'sewing_machine';
    case SchoolFees = 'school_fees';
    case KarobarSme = 'karobar_sme';
    case Livestock = 'livestock';
    case StaffLoan = 'staff_loan';
    case Other = 'other';

    public function label(): string
    {
        return match ($this->value) {
            'karobar_sme' => 'Karobar / SME',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }
}
