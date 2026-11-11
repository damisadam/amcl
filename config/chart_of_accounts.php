<?php

/*
| Chart of accounts TEMPLATE copied into each tenant at provisioning. After that each tenant owns and
| edits its own chart. `role` binds an account to an AccountRole referenced by posting rules.
| [code, name, type, level, parent code|null, role|null, cash flow category|null]
*/

return [
    ['1000', 'Assets', 'asset', 'head', null, null, null],
    ['1100', 'Cash & Bank', 'asset', 'sub_head', '1000', null, null],
    ['1110', 'Operating Account – Sponsor Bank', 'asset', 'account', '1100', 'operating_bank', null],
    ['1120', 'Disbursement Account', 'asset', 'account', '1100', 'disbursement_bank', null],
    ['1130', 'Collection Account – Raast', 'asset', 'account', '1100', 'collection_bank', null],
    ['1140', 'Safeguarded Wallet Account', 'asset', 'account', '1100', 'safeguarded_bank', null],
    ['1150', 'Petty Cash', 'asset', 'account', '1100', 'petty_cash', null],
    ['1200', 'Financing Receivables', 'asset', 'sub_head', '1000', null, null],
    ['1210', 'Islamic Financing Receivable (Murabaha / Ijarah)', 'asset', 'account', '1200', 'financing_receivable', 'operating'],
    ['1220', 'Provision for Financing Losses', 'asset', 'account', '1200', 'provision_allowance', 'operating'],
    ['1230', 'Deferred Murabaha / Ijarah Profit (contra)', 'asset', 'account', '1200', 'deferred_profit', 'operating'],
    ['1240', 'Deferred Financing Charges (contra)', 'asset', 'account', '1200', 'deferred_charges', 'operating'],
    ['1250', 'Assets Purchased for Financing (Murabaha stock)', 'asset', 'account', '1200', 'murabaha_inventory', 'operating'],
    ['1300', 'Other Receivables', 'asset', 'sub_head', '1000', null, null],
    ['1310', 'Staff & Other Receivables', 'asset', 'account', '1300', 'other_receivables', 'operating'],
    ['1320', 'Advances to Vendors', 'asset', 'account', '1300', 'vendor_advances', 'operating'],
    ['1400', 'Fixed Assets', 'asset', 'sub_head', '1000', null, null],
    ['1410', 'Property & Equipment', 'asset', 'account', '1400', 'fixed_assets', 'investing'],
    ['1420', 'Accumulated Depreciation', 'asset', 'account', '1400', 'accumulated_depreciation', 'investing'],

    ['2000', 'Liabilities', 'liability', 'head', null, null, null],
    ['2100', 'Customer Liabilities', 'liability', 'sub_head', '2000', null, null],
    ['2110', 'Customer Wallet Balances (Safeguarded)', 'liability', 'account', '2100', 'wallet_liability', 'operating'],
    ['2120', 'Customer Advances / Excess Receipts', 'liability', 'account', '2100', 'customer_advances', 'operating'],
    ['2200', 'Payables', 'liability', 'sub_head', '2000', null, null],
    ['2210', 'Vendor Payables', 'liability', 'account', '2200', 'vendor_payable', 'operating'],
    ['2220', 'Withholding Tax Payable', 'liability', 'account', '2200', 'wht_payable', 'operating'],
    ['2230', 'Provident Fund & EOBI Payable', 'liability', 'account', '2200', 'pf_payable', 'operating'],
    ['2240', 'Investor Profit Payable', 'liability', 'account', '2200', 'investor_profit_payable', 'financing'],
    ['2250', 'Takaful Contributions Payable', 'liability', 'account', '2200', 'takaful_payable', 'operating'],
    ['2300', 'Charity Fund', 'liability', 'sub_head', '2000', null, null],
    ['2310', 'Charity Fund Payable (Late Payment Amounts)', 'liability', 'account', '2300', 'charity_payable', 'operating'],
    ['2400', 'Funding Liabilities', 'liability', 'sub_head', '2000', null, null],
    ['2410', 'Investor / Mudarabah Funds', 'liability', 'account', '2400', 'investor_funds', 'financing'],
    ['2420', 'Bank Lines of Credit', 'liability', 'account', '2400', 'bank_lines', 'financing'],
    ['2900', 'Suspense', 'liability', 'sub_head', '2000', null, null],
    ['2910', 'Suspense – Unidentified Receipts', 'liability', 'account', '2900', 'suspense', 'operating'],

    ['3000', 'Equity', 'equity', 'head', null, null, null],
    ['3100', 'Share Capital', 'equity', 'account', '3000', 'equity_capital', 'financing'],
    ['3200', 'Retained Earnings', 'equity', 'account', '3000', 'retained_earnings', null],

    ['4000', 'Income', 'income', 'head', null, null, null],
    ['4100', 'Murabaha / Ijarah Profit Income', 'income', 'account', '4000', 'profit_income', 'operating'],
    ['4200', 'Processing & Documentation Fees', 'income', 'account', '4000', 'fee_income', 'operating'],
    ['4300', 'Gain on Disposal of Assets', 'income', 'account', '4000', 'gain_on_disposal', 'investing'],
    ['4900', 'Other Income', 'income', 'account', '4000', 'other_income', 'operating'],

    ['5000', 'Expenses', 'expense', 'head', null, null, null],
    ['5100', 'Salaries & Benefits', 'expense', 'account', '5000', 'salaries_expense', 'operating'],
    ['5200', 'Rent & Utilities', 'expense', 'account', '5000', 'rent_expense', 'operating'],
    ['5300', 'IT & Connectivity', 'expense', 'account', '5000', 'it_expense', 'operating'],
    ['5400', 'Depreciation', 'expense', 'account', '5000', 'depreciation_expense', null],
    ['5500', 'Provision for Financing Losses', 'expense', 'account', '5000', 'provision_expense', null],
    ['5600', 'Financing Written Off', 'expense', 'account', '5000', 'bad_debt_expense', null],
    ['5700', 'General & Administrative', 'expense', 'account', '5000', 'general_expense', 'operating'],
    ['5800', 'Profit Distribution to Investors', 'expense', 'account', '5000', 'profit_distribution_expense', 'operating'],
    ['5900', 'Loss on Disposal of Assets', 'expense', 'account', '5000', 'loss_on_disposal', 'investing'],
    ['5950', 'Bank & Provider Charges', 'expense', 'account', '5000', 'bank_charges', 'operating'],
];
