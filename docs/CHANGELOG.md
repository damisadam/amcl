# Changelog

## Unreleased — UI redesign (in progress, tracked in `docs/ui-redesign-inventory.md`)
* Customer portal rebuilt to the full-width references (11 screens), incl. transaction CSV export and notification filters.
* Second pass on the reference screens; KPI icons app-wide, segmented tabs, branded sign-in screens, product art in the customer catalogue; fixed double-escaped `&#039;`/`&amp;` in form hints (317 component attributes).
* Batch 0 (foundations) and Batch 1 (the 8 reference screens in `docs/ui-references/`) done: green Islamic-fintech shell for all five portals, KPI cards with icons, numbered pagination, product illustrations, landing/tenant landing/customer home/support/ops dashboard/customers/posting rules/platform overview redesigned. Fixed horizontal page overflow on 21 screens. New screenshot harness `scripts/ui_screens.mjs`.

## 0.2.0 — 2026-10-26 (QA & product review fixes)
All findings of the "AMCL Platform – QA & Product Review" were addressed:
* **Critical:** C1 document uploads (Livewire `upload` name collision, also found and fixed on three staff upload forms);
  C2 guarantor verification gate (bureau + AML + income) before approval, real guarantor status in assessments;
  C3 unique email/mobile per tenant with OTP to the new contact; C4 documents must be opened before e-signing, viewed
  hash stored and checked; C5 GPS-required field check-in (accuracy ≤ 200 m, scheduled date only, distance flag).
* **High:** H1 resubmission requires responses, keeps first submission date; H2 apply parameters clamped; H3 Asia/Karachi
  timezone; H4 KYC/AML review with evidence and queue separation; H5 stale-assessment rule; H6 guarantor signing link and
  branch wet-ink confirmation + readiness checklist; H7 two-person payout release and trimmed Tenant Admin; H8 committee
  before disbursement approval, stage-order validation.
* **Medium M1–M21:** POST locale switch and full Urdu, human validation messages, vendor↔product mapping, wallet
  restriction UX, attempts-left messages, lifecycle timeline events, nav/escaping/badge fixes, dashboard and customer-list
  scoping and masking, branded error pages and titles, layout shifts, visit checklist ✓/✗, vendor portal status scope,
  vendor price-change approval, demo data consistency and per-tenant branding.
* **Security S1–S8:** TOTP MFA for platform, admin and approver roles (+ step-up for payout/payment release and journal
  reversal approval); typed organisation code; nonce CSP, no X-Powered-By, HSTS; two-person demo reset; simulation
  controls only for demo tenants and audited (UI and services); journal reversal maker-checker; masked receipts and
  rate-limited lookup; compliance panel in approval modals.
* **Finance F3–F7:** APR and "from PKR X/month" on cards, unified late-payment wording, Murabaha accounting with deferred
  profit/charges (net receivable = principal outstanding), MTD income tile, Murabaha asset-ownership step (vendor invoice
  in the institution's name confirmed by a second user) and Murabaha document pack. F1/F2 recorded as D-019/D-020.
* Verification: 168 tests / 1,355 assertions passing; seeded crawl of 3,605 pages with 0 errors; all five tenants
  reconcile (TB, balance sheet, wallet three-way, net receivable = loan principal).

## 0.1.0 — 2026-10-25 (initial demo release)
* Greenfield Laravel 12 modular monolith: tenancy, identity & RBAC, audit, documents, notifications, integrations layer.
* LMS & DFS lending lifecycle; Digital Wallet with immutable ledger; payment orchestration with deterministic simulators;
  FMS double-entry GL with posting rules, periods, AP, reconciliation, provisioning, assets, payroll, budgets, Islamic & charity accounting, reports.
* Portals: customer (mobile-first, EN/UR), operations (role-based), vendor, platform super-admin.
* Five-tenant deterministic demo data built through real workflows; `amcl:demo-reset`.
* Scheduled jobs: daily servicing (cooling-off release, delinquency, reminders, PTP), month-end (provisioning, depreciation).
* Fixes found during build: guarantor creation ignored non-column consent flag; pending top-ups now link their payment
  order; tenant middleware now runs before route-model binding; callback de-duplication scoped per tenant; product
  versions can publish an explicitly empty charge/document list; Horizon processes the `callbacks` and `broadcasts`
  queues; broadcast jobs are single-attempt; trial balance is cumulative as of the "To" date; admin dashboard no longer
  shows empty field/recovery task panels; demo users' password age is current after the time-travel seeding.
* Verification (2026-10-25): full suite **120 tests / 960 assertions passing**; seeded crawl of **2,653 pages** across
  all portals and roles with **0 errors**; multi-process wallet race (6 processes) → exactly one debit; all five demo
  tenants reconcile (TB & balance sheet balanced; wallet balances = wallet liability = safeguarded account; loan
  principal = financing receivable); live Redis/Horizon delayed callback and Reverb broadcast verified.
