<?php

namespace App\Enums;

enum AccountType: string
{
    use EnumHelpers;

    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
