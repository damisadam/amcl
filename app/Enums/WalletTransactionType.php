<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    use EnumHelpers;

    case Topup = 'topup';
    case Withdrawal = 'withdrawal';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case BillPayment = 'bill_payment';
    case MerchantPayment = 'merchant_payment';
    case MerchantReceipt = 'merchant_receipt';
    case InstalmentPayment = 'instalment_payment';
    case DisbursementCredit = 'disbursement_credit';
    case Refund = 'refund';
    case Reversal = 'reversal';
}
