# Finance (FMS) & Reports — module notes

Scope: AMCL V1.5 FMS §1–§16 screens in the tenant operations portal, plus all MIS / financial /
regulatory reports. Every figure is computed from the tenant's own database records (posted journal
lines, LMS/Wallet sub-ledgers) — nothing is hardcoded. Bank / Raast settlement data is **simulated**
and labelled as such.

## Routes (`routes/ops/finance.php`, `routes/ops/reports.php`; prefix `/ops`, names `ops.*`)

| Route | Screen | Permission(s) |
|---|---|---|
| `finance.dashboard` `/ops/finance` | Cash & bank, safeguarding check, AR/AP, JV status, recon exceptions, period status, fund utilization, charity, MTD P&L | `fms.view` |
| `finance.journals.index` | Voucher list (status/origin/module/date filters, CSV) | `fms.view` (+`reports.export`) |
| `finance.journals.create` / `.edit` | Manual JV editor (dynamic lines, live balance, attachments, save draft / submit) | `fms.journals.create` (maker only edits) |
| `finance.journals.show` | Lines, source drill-down, approval history, approve/return/reject (ApprovalEngine), direct post (if approval disabled), reverse | `fms.view`, `approvals.act`, `fms.journals.post`, `fms.journals.reverse` |
| `finance.accounts` | Chart of accounts tree with balances (as-of), add/edit accounts, cost centers | `fms.view`, `fms.coa.manage` |
| `finance.accounts.ledger` | Account ledger with running balance + CSV | `fms.view` |
| `finance.posting-rules` | Event → journal-line mapping editor | `fms.view` / `fms.posting_rules.manage` |
| `finance.periods` | Fiscal years, periods, close checklist, close, reopen (reason) | `fms.periods.manage`, `fms.periods.reopen` |
| `finance.treasury` | Bank accounts & balances, min-balance alerts, fund-transfer requests, settlement statements, 30-day liquidity | `fms.view`, `fms.treasury.manage` |
| `finance.reconciliation.index` / `.show` | Run matching, exceptions, manual match, suspense, annotate, submit/approve; suspense reclassification | `fms.reconciliation.perform` / `.approve` |
| `finance.payables` | Bills (WHT preview), payment requests, approval, release (demo scenario), history/retry, AP aging, vendor ledger | `fms.payables.manage`, `fms.payables.execute`, `approvals.act` |
| `finance.receivables` | Portfolio receivable vs GL (difference), DPD aging, other receivables & receipts | `fms.view` |
| `finance.funds` | Funding sources, fund receipts, investor report, profit distribution | `fms.view`, `fms.funds.manage` |
| `finance.provisioning` | Classification summary, provisioning run, write-off proposals | `fms.provisioning.manage` |
| `finance.assets` | Asset register, depreciation run, disposal, branch tracking | `fms.assets.manage` |
| `finance.payroll` | Payroll runs → approval → posting; staff loan deduction reconciliation | `fms.payroll.manage` |
| `finance.expenses` | Expense / reimbursement / purchase request / petty cash claims → approval → posted | `fms.expenses.create` |
| `finance.budgets` | Budgets (annual lines spread over 12 periods), approval by a different user, BvA, 3-month forecast | `fms.budgets.manage` |
| `finance.islamic` | Charity fund ledger & disbursement requests, profit by Shariah structure, Shariah audit page | `fms.islamic.manage` |
| `finance.tax` | WHT movements by month/source, vendor WHT schedule (CSV) | `fms.tax.view` |
| `reports.lending` | Funnel, decisions, approval TAT per stage, disbursements, portfolio (product/branch/officer), PAR & aging, collections efficiency, timeliness, closures/NOC, field & recovery | `reports.lms` |
| `reports.wallet` | Balances by status, volumes by type/status/day, failed/reversed/pending, external recon status, three-way safeguarding check | `reports.wallet` |
| `reports.financial` | Trial balance, GL, P&L, balance sheet, cash flow, budget vs actual, financing targeted vs actual, branch P&L | `reports.fms` |
| `reports.regulatory` | Portfolio quality (classification × provision), product-wise, outreach (gender/city), Raast Annexure-A summary, complaints | `reports.regulatory` |

All CSV exports go through `App\Services\Reports\CsvExport` (requires `reports.export`, writes a
`report.exported` audit record with filters).

## New services
- `App\Services\Accounting\JournalWorkflow` — submit manual JV to the finance approval hierarchy
  (`journal_voucher`), or direct post by a different user when `finance.journal_requires_approval` is off; discard drafts.
- `App\Services\Accounting\FinanceAdminService` — accounts, cost centers, posting rules (structural
  validation), bank account limits, funds, other receivables (posted via system vouchers), budgets (SoD approval).
- `App\Services\Reports\{LendingReports, WalletReports, RegulatoryReports, CsvExport}`.

## Rules enforced
- Maker-checker: JV maker cannot approve/post (engine + `GeneralLedger::post`); vendor payment requester
  cannot approve or release; budget preparer cannot approve; reconciliation preparer cannot approve.
- Posting rules: system events keep their (side, amount-key) multiset so generated vouchers always
  balance — only accounts/descriptions/activation are re-mappable; custom events must use identical
  amount keys on both sides.
- Accounts: unique code, child type = parent type, accounts with postings can't change type or become
  non-postable, accounts used by active posting rules can't be deactivated.
- Closed periods reject postings; reopening needs `fms.periods.reopen` + a ≥10-char reason (audited).
- Vendor marked paid only on provider success; failure leaves bill open and posts nothing.

## Simulated / assumption notes
- Regulatory report layouts are illustrative (header disclaimer); official SECP/SBP/Raast templates to be confirmed.
- Profit distribution = fund's investor share % × profit income lines carrying that fund dimension; WHT 15% (assumption).
- Liquidity projection uses schedules + approval pipeline; no external bank feed.
- Tax: WHT only; sales tax / FED on fees and FBR return formats are not implemented.

## Tests (`DB_DATABASE=amcl_test_5 php artisan test tests/Feature/Finance tests/Feature/Reports`)
20 tests / 200 assertions: screen rendering + 403s, JV maker-checker, unbalanced rejection,
self-approval block, closed-period block, reopen permission/reason/audit, reversal, payables
success/failure/SoD, reconciliation (matched / mismatch / missing both sides / suspense / approval),
mixed-activity TB & BS balance with sub-ledger agreement and every report tab, cross-tenant report
isolation (404 on other tenant's voucher/ledger), audited CSV export, posting-rule validation,
COA validation, budget SoD, profit distribution, charity limit.
