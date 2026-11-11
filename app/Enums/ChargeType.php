<?php

namespace App\Enums;

enum ChargeType: string
{
    use EnumHelpers;

    case ProcessingFee = 'processing_fee';
    case Takaful = 'takaful';
    case LatePayment = 'late_payment';
    case Prepayment = 'prepayment';
    case Documentation = 'documentation';
    case Other = 'other';
}
