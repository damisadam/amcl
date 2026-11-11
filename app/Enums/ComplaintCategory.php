<?php

namespace App\Enums;

enum ComplaintCategory: string
{
    use EnumHelpers;

    case General = 'general';
    case Loan = 'loan';
    case Wallet = 'wallet';
    case TransactionDispute = 'transaction_dispute';
    case RefundRequest = 'refund_request';
    case CollectionConduct = 'collection_conduct';
    case Kyc = 'kyc';
    case Service = 'service';
}
