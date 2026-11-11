<?php

/*
| Default sub-ledger posting rules TEMPLATE (FMS §2). event => list of [side, account role, amount key].
| Tenants can edit their copies (Finance › Posting Rules). Lines with a zero amount are skipped; every
| generated voucher must balance or the posting is refused. Assumptions are recorded in docs/ACCOUNTING_RULES.md.
*/

return [
    // LMS / DFS — Murabaha sequence (DECISIONS D-004/D-019): the institution buys the asset (Dr Murabaha stock / Cr bank),
    // sells it to the customer at cost + disclosed profit (Dr receivable at contract value / Cr stock, Cr deferred profit,
    // Cr deferred charges). Profit and financed charges are recognised from the deferred accounts as instalments are collected.
    'loan_disbursement' => [['debit', 'murabaha_inventory', 'asset_cost'], ['credit', 'disbursement_bank', 'net'], ['credit', 'fee_income', 'deductions'],
        ['debit', 'financing_receivable', 'contract'], ['credit', 'murabaha_inventory', 'asset_cost'], ['credit', 'deferred_profit', 'profit'], ['credit', 'deferred_charges', 'charges']],
    'loan_disbursement_wallet' => [['debit', 'murabaha_inventory', 'asset_cost'], ['credit', 'wallet_liability', 'net'], ['credit', 'fee_income', 'deductions'],
        ['debit', 'safeguarded_bank', 'net'], ['credit', 'disbursement_bank', 'net'],
        ['debit', 'financing_receivable', 'contract'], ['credit', 'murabaha_inventory', 'asset_cost'], ['credit', 'deferred_profit', 'profit'], ['credit', 'deferred_charges', 'charges']],
    'loan_repayment_bank' => [['debit', 'collection_bank', 'amount'], ['credit', 'financing_receivable', 'receivable'],
        ['credit', 'charity_payable', 'late_charge'], ['credit', 'customer_advances', 'unallocated'],
        ['debit', 'deferred_profit', 'profit'], ['credit', 'profit_income', 'profit'], ['debit', 'deferred_charges', 'charges'], ['credit', 'fee_income', 'charges']],
    'loan_repayment_wallet' => [['debit', 'wallet_liability', 'amount'], ['credit', 'financing_receivable', 'receivable'],
        ['credit', 'charity_payable', 'late_charge'], ['credit', 'customer_advances', 'unallocated'],
        ['debit', 'deferred_profit', 'profit'], ['credit', 'profit_income', 'profit'], ['debit', 'deferred_charges', 'charges'], ['credit', 'fee_income', 'charges'],
        ['debit', 'collection_bank', 'amount'], ['credit', 'safeguarded_bank', 'amount']],
    'loan_provision' => [['debit', 'provision_expense', 'amount'], ['credit', 'provision_allowance', 'amount']],
    'loan_provision_release' => [['debit', 'provision_allowance', 'amount'], ['credit', 'provision_expense', 'amount']],
    'loan_write_off' => [['debit', 'provision_allowance', 'provisioned'], ['debit', 'bad_debt_expense', 'excess'], ['credit', 'financing_receivable', 'principal'],
        ['debit', 'deferred_profit', 'profit'], ['debit', 'deferred_charges', 'charges'], ['credit', 'financing_receivable', 'unearned']],

    // Wallet (internal transfers have no GL impact: wallet ledger only)
    'wallet_topup' => [['debit', 'safeguarded_bank', 'amount'], ['credit', 'wallet_liability', 'amount']],
    'wallet_withdrawal' => [['debit', 'wallet_liability', 'amount'], ['credit', 'safeguarded_bank', 'amount']],
    'wallet_bill_payment' => [['debit', 'wallet_liability', 'amount'], ['credit', 'safeguarded_bank', 'amount']],
    'wallet_merchant_external' => [['debit', 'wallet_liability', 'amount'], ['credit', 'safeguarded_bank', 'amount']],
    'wallet_reversal_credit' => [['debit', 'safeguarded_bank', 'amount'], ['credit', 'wallet_liability', 'amount']],

    // Accounts payable & treasury
    'vendor_bill' => [['debit', 'general_expense', 'gross'], ['credit', 'vendor_payable', 'payable'], ['credit', 'wht_payable', 'wht']],
    'vendor_payment' => [['debit', 'vendor_payable', 'amount'], ['credit', 'operating_bank', 'amount']],
    'vendor_advance' => [['debit', 'vendor_advances', 'amount'], ['credit', 'operating_bank', 'amount']],
    'expense_payment' => [['debit', 'general_expense', 'amount'], ['credit', 'operating_bank', 'amount']],
    'fund_receipt' => [['debit', 'operating_bank', 'amount'], ['credit', 'investor_funds', 'amount']],
    'fund_transfer' => [['debit', 'disbursement_bank', 'amount'], ['credit', 'operating_bank', 'amount']],
    'suspense_receipt' => [['debit', 'collection_bank', 'amount'], ['credit', 'suspense', 'amount']],
    'suspense_reclass' => [['debit', 'suspense', 'amount'], ['credit', 'other_income', 'amount']],

    // Finance operations
    'payroll' => [['debit', 'salaries_expense', 'gross'], ['credit', 'operating_bank', 'net'], ['credit', 'wht_payable', 'tax'], ['credit', 'pf_payable', 'pf']],
    'asset_purchase' => [['debit', 'fixed_assets', 'amount'], ['credit', 'operating_bank', 'amount']],
    'depreciation' => [['debit', 'depreciation_expense', 'amount'], ['credit', 'accumulated_depreciation', 'amount']],
    'asset_disposal' => [['debit', 'operating_bank', 'proceeds'], ['debit', 'accumulated_depreciation', 'accumulated'], ['debit', 'loss_on_disposal', 'loss'],
        ['credit', 'fixed_assets', 'cost'], ['credit', 'gain_on_disposal', 'gain']],
    'charity_disbursement' => [['debit', 'charity_payable', 'amount'], ['credit', 'operating_bank', 'amount']],
    'profit_distribution' => [['debit', 'profit_distribution_expense', 'gross'], ['credit', 'investor_profit_payable', 'net'], ['credit', 'wht_payable', 'wht']],
];
