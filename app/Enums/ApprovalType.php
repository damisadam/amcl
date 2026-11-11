<?php

namespace App\Enums;

enum ApprovalType: string
{
    use EnumHelpers;

    case Loan = 'loan';
    case JournalVoucher = 'journal_voucher';
    case VendorPayment = 'vendor_payment';
    case Expense = 'expense';
    case FundTransfer = 'fund_transfer';
    case WriteOff = 'write_off';
    case CharityDisbursement = 'charity_disbursement';
    case Payroll = 'payroll';
    case Reconciliation = 'reconciliation';
    case JournalReversal = 'journal_reversal';
}
