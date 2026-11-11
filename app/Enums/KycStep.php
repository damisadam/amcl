<?php

namespace App\Enums;

enum KycStep: string
{
    use EnumHelpers;

    case MobileOtp = 'mobile_otp';
    case CnicOcr = 'cnic_ocr';
    case NadraVerisys = 'nadra_verisys';
    case Liveness = 'liveness';
    case AmlScreening = 'aml_screening';

    public function label(): string
    {
        return match ($this->value) {
            'nadra_verisys' => 'NADRA Verisys',
            'cnic_ocr' => 'CNIC OCR',
            'aml_screening' => 'AML Screening',
            'mobile_otp' => 'Mobile OTP',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }
}
