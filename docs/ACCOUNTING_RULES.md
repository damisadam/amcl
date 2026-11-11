# Accounting rules (FMS)

## Principles
* **Per-tenant books.** Each tenant has its own chart of accounts, posting rules, fiscal years, periods, bank
  accounts and GL. Sub-ledger postings go only to the same tenant's GL; no cross-tenant posting or netting.
* **Double entry, enforced.** `GeneralLedger` refuses any voucher whose debits ≠ credits, with < 2 lines, zero total,
  negative amounts, a line carrying both sides, or a non-postable / inactive / other-tenant account.
* **Immutability.** Posted vouchers cannot be edited or deleted (model guard); corrections are linked reversal
  vouchers (`reversal_of_id`), and reversal is idempotent.
* **Periods.** Monthly periods inside fiscal years (Pakistan FY July–June, configurable). Nothing posts into a closed
  period. Close runs in order with a checklist (no drafts/pending vouchers, no unbalanced vouchers). Reopening needs
  `fms.periods.reopen` and a reason, and must be done latest-first. All audited.
* **Maker-checker.** Manual vouchers: draft → submitted (finance approval workflow) → posted by the final approver,
  who can never be the maker. Vendor payments, expenses, fund transfers, write-offs, charity disbursements, payroll and
  reconciliation batches use their own configurable finance approval hierarchies (separate from loan approvals).
* **Traceability.** Every system voucher stores `source_type/source_id`, `event_type`, `source_module` and an
  idempotency key (`event:source`) so retries and duplicate callbacks never post twice, and any GL line drills back to
  the originating disbursement, repayment, wallet transaction, vendor payment, etc. Lines carry branch, cost centre,
  customer, vendor and fund dimensions.

## Sub-ledger posting rules (defaults, editable per tenant)
Events map to journal lines via `posting_rules` (Finance › Posting rules). Lines whose amount is zero are skipped.
Account overrides are applied for event-specific accounts (e.g. a bill's expense account, a fund-type liability,
the specific bank account in a fund transfer).

<!-- RULES -->
| Event | Side | Account (role → default code) | Amount key |
|---|---|---|---|
| `loan_disbursement` | debit | `murabaha_inventory` → 1250 Assets Purchased for Financing (Murabaha stock) | `asset_cost` |
|  | credit | `disbursement_bank` → 1120 Disbursement Account | `net` |
|  | credit | `fee_income` → 4200 Processing & Documentation Fees | `deductions` |
|  | debit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `contract` |
|  | credit | `murabaha_inventory` → 1250 Assets Purchased for Financing (Murabaha stock) | `asset_cost` |
|  | credit | `deferred_profit` → 1230 Deferred Murabaha / Ijarah Profit (contra) | `profit` |
|  | credit | `deferred_charges` → 1240 Deferred Financing Charges (contra) | `charges` |
| `loan_disbursement_wallet` | debit | `murabaha_inventory` → 1250 Assets Purchased for Financing (Murabaha stock) | `asset_cost` |
|  | credit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `net` |
|  | credit | `fee_income` → 4200 Processing & Documentation Fees | `deductions` |
|  | debit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `net` |
|  | credit | `disbursement_bank` → 1120 Disbursement Account | `net` |
|  | debit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `contract` |
|  | credit | `murabaha_inventory` → 1250 Assets Purchased for Financing (Murabaha stock) | `asset_cost` |
|  | credit | `deferred_profit` → 1230 Deferred Murabaha / Ijarah Profit (contra) | `profit` |
|  | credit | `deferred_charges` → 1240 Deferred Financing Charges (contra) | `charges` |
| `loan_repayment_bank` | debit | `collection_bank` → 1130 Collection Account – Raast | `amount` |
|  | credit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `receivable` |
|  | credit | `charity_payable` → 2310 Charity Fund Payable (Late Payment Amounts) | `late_charge` |
|  | credit | `customer_advances` → 2120 Customer Advances / Excess Receipts | `unallocated` |
|  | debit | `deferred_profit` → 1230 Deferred Murabaha / Ijarah Profit (contra) | `profit` |
|  | credit | `profit_income` → 4100 Murabaha / Ijarah Profit Income | `profit` |
|  | debit | `deferred_charges` → 1240 Deferred Financing Charges (contra) | `charges` |
|  | credit | `fee_income` → 4200 Processing & Documentation Fees | `charges` |
| `loan_repayment_wallet` | debit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
|  | credit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `receivable` |
|  | credit | `charity_payable` → 2310 Charity Fund Payable (Late Payment Amounts) | `late_charge` |
|  | credit | `customer_advances` → 2120 Customer Advances / Excess Receipts | `unallocated` |
|  | debit | `deferred_profit` → 1230 Deferred Murabaha / Ijarah Profit (contra) | `profit` |
|  | credit | `profit_income` → 4100 Murabaha / Ijarah Profit Income | `profit` |
|  | debit | `deferred_charges` → 1240 Deferred Financing Charges (contra) | `charges` |
|  | credit | `fee_income` → 4200 Processing & Documentation Fees | `charges` |
|  | debit | `collection_bank` → 1130 Collection Account – Raast | `amount` |
|  | credit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
| `loan_provision` | debit | `provision_expense` → 5500 Provision for Financing Losses | `amount` |
|  | credit | `provision_allowance` → 1220 Provision for Financing Losses | `amount` |
| `loan_provision_release` | debit | `provision_allowance` → 1220 Provision for Financing Losses | `amount` |
|  | credit | `provision_expense` → 5500 Provision for Financing Losses | `amount` |
| `loan_write_off` | debit | `provision_allowance` → 1220 Provision for Financing Losses | `provisioned` |
|  | debit | `bad_debt_expense` → 5600 Financing Written Off | `excess` |
|  | credit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `principal` |
|  | debit | `deferred_profit` → 1230 Deferred Murabaha / Ijarah Profit (contra) | `profit` |
|  | debit | `deferred_charges` → 1240 Deferred Financing Charges (contra) | `charges` |
|  | credit | `financing_receivable` → 1210 Islamic Financing Receivable (Murabaha / Ijarah) | `unearned` |
| `wallet_topup` | debit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
|  | credit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
| `wallet_withdrawal` | debit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
|  | credit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
| `wallet_bill_payment` | debit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
|  | credit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
| `wallet_merchant_external` | debit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
|  | credit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
| `wallet_reversal_credit` | debit | `safeguarded_bank` → 1140 Safeguarded Wallet Account | `amount` |
|  | credit | `wallet_liability` → 2110 Customer Wallet Balances (Safeguarded) | `amount` |
| `vendor_bill` | debit | `general_expense` → 5700 General & Administrative | `gross` |
|  | credit | `vendor_payable` → 2210 Vendor Payables | `payable` |
|  | credit | `wht_payable` → 2220 Withholding Tax Payable | `wht` |
| `vendor_payment` | debit | `vendor_payable` → 2210 Vendor Payables | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `vendor_advance` | debit | `vendor_advances` → 1320 Advances to Vendors | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `expense_payment` | debit | `general_expense` → 5700 General & Administrative | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `fund_receipt` | debit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
|  | credit | `investor_funds` → 2410 Investor / Mudarabah Funds | `amount` |
| `fund_transfer` | debit | `disbursement_bank` → 1120 Disbursement Account | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `suspense_receipt` | debit | `collection_bank` → 1130 Collection Account – Raast | `amount` |
|  | credit | `suspense` → 2910 Suspense – Unidentified Receipts | `amount` |
| `suspense_reclass` | debit | `suspense` → 2910 Suspense – Unidentified Receipts | `amount` |
|  | credit | `other_income` → 4900 Other Income | `amount` |
| `payroll` | debit | `salaries_expense` → 5100 Salaries & Benefits | `gross` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `net` |
|  | credit | `wht_payable` → 2220 Withholding Tax Payable | `tax` |
|  | credit | `pf_payable` → 2230 Provident Fund & EOBI Payable | `pf` |
| `asset_purchase` | debit | `fixed_assets` → 1410 Property & Equipment | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `depreciation` | debit | `depreciation_expense` → 5400 Depreciation | `amount` |
|  | credit | `accumulated_depreciation` → 1420 Accumulated Depreciation | `amount` |
| `asset_disposal` | debit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `proceeds` |
|  | debit | `accumulated_depreciation` → 1420 Accumulated Depreciation | `accumulated` |
|  | debit | `loss_on_disposal` → 5900 Loss on Disposal of Assets | `loss` |
|  | credit | `fixed_assets` → 1410 Property & Equipment | `cost` |
|  | credit | `gain_on_disposal` → 4300 Gain on Disposal of Assets | `gain` |
| `charity_disbursement` | debit | `charity_payable` → 2310 Charity Fund Payable (Late Payment Amounts) | `amount` |
|  | credit | `operating_bank` → 1110 Operating Account – Sponsor Bank | `amount` |
| `profit_distribution` | debit | `profit_distribution_expense` → 5800 Profit Distribution to Investors | `gross` |
|  | credit | `investor_profit_payable` → 2240 Investor Profit Payable | `net` |
|  | credit | `wht_payable` → 2220 Withholding Tax Payable | `wht` |
<!-- /RULES -->

### Notable treatments (see DECISIONS.md)
* **Disbursement – Murabaha sequence (QA F5):** one voucher shows purchase then sale: Dr *Assets purchased for
  financing* / Cr bank (asset cost paid to the vendor or customer), then Dr *Financing receivable* at the **contract value**
  (cost + disclosed profit + financed charges) / Cr *Assets purchased for financing* / Cr *Deferred Murabaha/Ijarah profit* /
  Cr *Deferred financing charges* (both contra-assets). Upfront-deducted charges are fee income at disbursement (D-009).
  The GL receivable net of the two deferred accounts equals principal outstanding in the loan sub-ledger
  (`FinancialReports::netFinancingReceivable()`, used by the receivables and finance overview screens).
* **Repayment:** allocated per product order; the receivable is reduced by principal + profit + charges collected;
  profit collected moves Deferred profit → profit income, charges collected move Deferred charges → fee income (recognition on
  collection, D-004); late amounts → **charity payable** (D-010); excess → customer advances.
* **Write-off:** allowance absorbs principal up to the provision, the excess is bad-debt expense; unearned profit and financed
  charges still in the receivable are released against their deferred accounts. 3-stage approval (Risk, Finance, Approval Committee).
* **Journal reversal (QA S6):** any posted voucher (manual or system) is reversed only through a reversal request approved by a
  second user (`journal_reversal` workflow); refused while the original voucher's period is closed.
* **Wallet:** top-ups/withdrawals/bills/external merchant payments move the safeguarded bank account and the wallet
  liability; wallet-to-wallet and internal merchant payments have **no GL impact** (ledger-only); wallet-funded loan
  repayments also sweep safeguarded → collection account (D-002).
* **Provisioning:** classification by DPD, provision movement posted (expense vs. allowance) — D-012. Write-off
  consumes the allowance first, the excess goes to bad-debt expense; requires a 3-stage approval.
* **Vendor bills:** expense (or asset-related) gross, vendor payable net of WHT, WHT payable. Payments clear payables;
  advances go to *Advances to vendors*.
* **Investor profit distribution:** Mudarabah-style share of profit income tagged to the fund, net of 15% WHT.

## Chart of accounts template
<!-- COA -->
| Code | Name | Type | Level | Parent | System role |
|---|---|---|---|---|---|
| 1000 | Assets | asset | head |  |  |
| 1100 | Cash & Bank | asset | sub_head | 1000 |  |
| 1110 | Operating Account – Sponsor Bank | asset | account | 1100 | `operating_bank` |
| 1120 | Disbursement Account | asset | account | 1100 | `disbursement_bank` |
| 1130 | Collection Account – Raast | asset | account | 1100 | `collection_bank` |
| 1140 | Safeguarded Wallet Account | asset | account | 1100 | `safeguarded_bank` |
| 1150 | Petty Cash | asset | account | 1100 | `petty_cash` |
| 1200 | Financing Receivables | asset | sub_head | 1000 |  |
| 1210 | Islamic Financing Receivable (Murabaha / Ijarah) | asset | account | 1200 | `financing_receivable` |
| 1220 | Provision for Financing Losses | asset | account | 1200 | `provision_allowance` |
| 1230 | Deferred Murabaha / Ijarah Profit (contra) | asset | account | 1200 | `deferred_profit` |
| 1240 | Deferred Financing Charges (contra) | asset | account | 1200 | `deferred_charges` |
| 1250 | Assets Purchased for Financing (Murabaha stock) | asset | account | 1200 | `murabaha_inventory` |
| 1300 | Other Receivables | asset | sub_head | 1000 |  |
| 1310 | Staff & Other Receivables | asset | account | 1300 | `other_receivables` |
| 1320 | Advances to Vendors | asset | account | 1300 | `vendor_advances` |
| 1400 | Fixed Assets | asset | sub_head | 1000 |  |
| 1410 | Property & Equipment | asset | account | 1400 | `fixed_assets` |
| 1420 | Accumulated Depreciation | asset | account | 1400 | `accumulated_depreciation` |
| 2000 | Liabilities | liability | head |  |  |
| 2100 | Customer Liabilities | liability | sub_head | 2000 |  |
| 2110 | Customer Wallet Balances (Safeguarded) | liability | account | 2100 | `wallet_liability` |
| 2120 | Customer Advances / Excess Receipts | liability | account | 2100 | `customer_advances` |
| 2200 | Payables | liability | sub_head | 2000 |  |
| 2210 | Vendor Payables | liability | account | 2200 | `vendor_payable` |
| 2220 | Withholding Tax Payable | liability | account | 2200 | `wht_payable` |
| 2230 | Provident Fund & EOBI Payable | liability | account | 2200 | `pf_payable` |
| 2240 | Investor Profit Payable | liability | account | 2200 | `investor_profit_payable` |
| 2250 | Takaful Contributions Payable | liability | account | 2200 | `takaful_payable` |
| 2300 | Charity Fund | liability | sub_head | 2000 |  |
| 2310 | Charity Fund Payable (Late Payment Amounts) | liability | account | 2300 | `charity_payable` |
| 2400 | Funding Liabilities | liability | sub_head | 2000 |  |
| 2410 | Investor / Mudarabah Funds | liability | account | 2400 | `investor_funds` |
| 2420 | Bank Lines of Credit | liability | account | 2400 | `bank_lines` |
| 2900 | Suspense | liability | sub_head | 2000 |  |
| 2910 | Suspense – Unidentified Receipts | liability | account | 2900 | `suspense` |
| 3000 | Equity | equity | head |  |  |
| 3100 | Share Capital | equity | account | 3000 | `equity_capital` |
| 3200 | Retained Earnings | equity | account | 3000 | `retained_earnings` |
| 4000 | Income | income | head |  |  |
| 4100 | Murabaha / Ijarah Profit Income | income | account | 4000 | `profit_income` |
| 4200 | Processing & Documentation Fees | income | account | 4000 | `fee_income` |
| 4300 | Gain on Disposal of Assets | income | account | 4000 | `gain_on_disposal` |
| 4900 | Other Income | income | account | 4000 | `other_income` |
| 5000 | Expenses | expense | head |  |  |
| 5100 | Salaries & Benefits | expense | account | 5000 | `salaries_expense` |
| 5200 | Rent & Utilities | expense | account | 5000 | `rent_expense` |
| 5300 | IT & Connectivity | expense | account | 5000 | `it_expense` |
| 5400 | Depreciation | expense | account | 5000 | `depreciation_expense` |
| 5500 | Provision for Financing Losses | expense | account | 5000 | `provision_expense` |
| 5600 | Financing Written Off | expense | account | 5000 | `bad_debt_expense` |
| 5700 | General & Administrative | expense | account | 5000 | `general_expense` |
| 5800 | Profit Distribution to Investors | expense | account | 5000 | `profit_distribution_expense` |
| 5900 | Loss on Disposal of Assets | expense | account | 5000 | `loss_on_disposal` |
| 5950 | Bank & Provider Charges | expense | account | 5000 | `bank_charges` |
<!-- /COA -->

## Reports (all from posted journal lines)
Trial balance (balanced check), general ledger with running balance and voucher drill-down, profit & loss, balance
sheet (assets = liabilities + equity + current earnings, checked), cash-flow statement (cash-account movements
classified by counterpart account category), budget vs. actual, branch / cost-centre P&L, AP aging, receivables aging,
fund utilisation, charity fund, tax (WHT) schedules.
