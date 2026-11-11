<?php

namespace App\Enums;

enum ConsentType: string
{
    use EnumHelpers;

    case TermsOfUse = 'terms_of_use';
    case PrivacyPolicy = 'privacy_policy';
    case NadraVerification = 'nadra_verification';
    case CreditBureau = 'credit_bureau';
    case DataSharing = 'data_sharing';
    case Esignature = 'esignature';
    case Communications = 'communications';

    public function label(): string
    {
        return match ($this->value) {
            'esignature' => 'e-Signature',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }
}
