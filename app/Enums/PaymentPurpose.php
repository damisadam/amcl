<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    use EnumHelpers;

    case WalletTopup = 'wallet_topup';
    case WalletWithdrawal = 'wallet_withdrawal';
    case LoanDisbursement = 'loan_disbursement';
    case InstalmentCollection = 'instalment_collection';
    case VendorPayment = 'vendor_payment';
    case BillPayment = 'bill_payment';
    case MerchantPayment = 'merchant_payment';
    case FundTransfer = 'fund_transfer';
}
