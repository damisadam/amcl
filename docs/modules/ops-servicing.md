# Ops portal – Servicing, Products, Vendors & Staff lending

Owner module: `app/Livewire/Ops/{Disbursements,Loans,Collections,Recovery,Products,Vendors,StaffLending}`,
routes `routes/ops/servicing.php`, services `App\Services\Lending\{CollectionsService,VendorAdminService,StaffLendingService}`.
Tests: `tests/Feature/Ops/Servicing` (`DB_DATABASE=amcl_test_3 php artisan test tests/Feature/Ops/Servicing`).

## Screens & routes

| Route | Screen | Permissions |
|---|---|---|
| `ops.disbursements.index` `/ops/disbursements` | Tabs *Ready* (ready_for_disbursement + cooling-off cases with `DisbursementService::blockers`), *In progress*, *History* (status, gross/deductions/net, provider ref, payment order, failure reason, resulting loan). Confirm modal shows recipient & net payout; demo scenario select. Retry allowed after a failed payout. | view `disbursements.view`; initiate `disbursements.initiate` |
| `ops.loans.index` `/ops/loans` | Portfolio with filters (status, classification, DPD bucket, product, branch, officer), KPI totals (principal, outstanding, overdue count, PAR30), CSV export. Branch-scoped via `RecordAccess::loansQuery`. | `loans.view`; export `reports.export` |
| `ops.loans.show` `/ops/loans/{loan}` | Tabs summary (frozen terms snapshot, outstanding breakdown, parties), schedule, payments (allocation detail, GL voucher no, reverse), recovery contacts, documents (incl. NOC), audit trail. Actions: record manual payment, reverse payment, issue NOC. Write-off status banner (write-off requests are in Finance). | `loans.view` / recovery perms; `repayments.record`; `repayments.reverse` (+ SoD: cannot reverse own); `loans.issue_noc`; audit tab `audit.view` or `loans.manage` |
| `ops.loans.transfer` `/ops/loans/transfer` | Select open loans (filters) → destination branch and/or officer + reason → `ServicingService::transferPortfolio`; recent transfers list. | `loans.manage` |
| `ops.collections.index` `/ops/collections` | Overdue / due today / due in 7 days queues, aging buckets 1-30/31-60/61-90/90+, collected today, single reminders (`CollectionsService::sendReminder`), quick manual payment, "Run delinquency now". Mobile numbers masked without `customers.view_sensitive`. | `repayments.view`; payment `repayments.record`; delinquency run `loans.manage` |
| `ops.recovery.index` `/ops/recovery` | Agents (`recovery.work`) see only cases assigned to them; managers (`recovery.view`/`recovery.assign`) see all delinquent cases, filter by agent/unassigned and bulk-assign. | as listed |
| `ops.recovery.show` `/ops/recovery/{loan}` | Case file: overdue, unpaid instalments, contact details (masked), guarantors, contact log, log contact (type, party borrower/guarantor only, outcome, PTP date & amount), payment delay notice, evaluate promises (kept/broken). Conduct banner. `ServicingService::logContact` enforces the tenant contact window (default 07:00–22:00). | assigned agent, or manager with branch access |
| `ops.products.index/create/show` | Catalogue incl. sub-products; create product + first version (terms, charges, required documents, branch availability); product page: current terms, pricing preview (`FinancingCalculator`), version history with usage counts, "New version" (publishes v+1 and retires current; existing applications/loans keep their version), details (Urdu name/description, Shariah approval ref, catalogue visibility, sort), branch availability, activate/deactivate. | view `products.view`; edit `products.manage` |
| `ops.vendors.index/create/show` | Vendor master: profile edit, approval (`vendors.approve`; asset vendors require IBAN), reject with reason, deactivate / reactivate / blacklist with reason (portal users suspended when not active), merchant wallet (open + activate via `WalletService`), items CRUD, documents upload/verify/reject (VENDOR_REG, VENDOR_BANK, expiry badges), vendor portal users (temporary password, forced change), financed cases, disbursements and payables payments (read-only). | view `vendors.view`; edit `vendors.manage`; approve `vendors.approve`; docs `documents.upload`/`documents.verify` |
| `ops.staff-lending.index/show` | Corporate clients; staff registry; link staff ↔ customer CIF; salary records (manual + CSV `employee_no,period,gross[,loan_deduction]`; deduction defaults to instalments due in the period); reconciliation per period (due vs payroll deduction vs posted) and "Post" = `RepaymentService::recordManual` channel `salary_deduction` with reference `SAL-YYYY-MM-EMPNO` (idempotent). | `staff_lending.manage`; posting also `repayments.record` |

## Business rules surfaced
- Loans exist only after the provider confirms a payout; failed payouts leave the case in the queue with no loan and no GL voucher.
- Manual payments require a reference and are allocated per the loan's frozen allocation order; reversal recomputes balances from allocations, reverses the GL voucher and refunds wallet payments.
- Recovery agents never see unassigned cases; third-party contact is not selectable; out-of-window contacts are refused.
- Product versions are immutable; publishing with zero charges really removes charges (`ProductService::newVersion(..., exact: true)`).
- Vendor status changes are audited with reasons; bank detail changes are audited.

## Realtime
Queues listen for `realtime` Livewire events (Reverb) and poll every 30–60 s as fallback.
