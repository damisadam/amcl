<?php

namespace App\Enums;

enum PaymentChannel: string
{
    use EnumHelpers;

    case Raast = 'raast';
    case QrIban = 'qr_iban';
    case OneBill = 'one_bill';
    case Easypaisa = 'easypaisa';
    case Jazzcash = 'jazzcash';
    case Wallet = 'wallet';
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case SalaryDeduction = 'salary_deduction';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this->value) {
            'qr_iban' => 'QR / IBAN Alias',
            'one_bill' => '1Bill / 1LINK',
            'raast' => 'Raast',
            default => \Illuminate\Support\Str::headline($this->value),
        };
    }
}
