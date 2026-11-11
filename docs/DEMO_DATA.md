# Demo data

`php artisan amcl:demo-reset --force` (refused in production / without demo mode) wipes the database and the demo
document folder, then seeds:

* **Platform:** permission catalogue, platform roles, platform admin/support users.
* **Five fictional tenants** – AMCL (primary), Amanah Microfinance Demo, Roshan Finance Demo, Naya Karobar Finance Demo,
  Barakah Financial Services Demo. All flagged `is_demo`; licence and registration numbers are fictional placeholders.

Data is created by **driving the real services with time travel** (`Carbon::setTestNow`) across the last 13 months, so
every application history, approval action, payment order, callback, wallet ledger entry, journal voucher, audit record
and notification was produced by the same workflow a user would trigger. Generation is deterministic (seeded by tenant
code). Identities are synthetic: names are random combinations, CNICs use the `99xxx` demo prefix, IBANs contain `DEMO`,
documents are generated placeholder images/PDFs.

## Per tenant
| Area | Contents |
|---|---|
| Organisation | 2–3 regions/areas, 3–5 branches + head office, departments, cost centres, ~25 staff covering all 16 roles (incl. a user with two roles, one suspended and one locked user), employees for payroll |
| Configuration | holidays, FAQs (EN/UR), banner & notice, notification templates, integration settings (demo mode, masked dummy credentials), approval workflows (5-stage loan + committee ≥ PKR 1m, finance hierarchies) |
| Products | 8 Shariah products: Motorbike Sahulat, Apna Rikshaw, Mera Laptop (v2 published), Sohni Scooty, Solar (Ijarah, reducing), Home Appliances (no partial payments), Karobar Sahulat (Musharakah, customer bank), School Fee (wallet disbursement) |
| Vendors | 12 vendors with items, IBANs and documents; 4 merchants (2 in-wallet, 2 external); 1 pending web request; vendor-portal login |
| Funds | own equity, Mudarabah investor pool (60% share), bank Musharakah line; receipts posted; opening transfer to disbursement account |
| Customers (30) | KYC not started / mobile unverified, profile started, manual review pending, failed (NADRA), AML potential match, passed after review, expired CNIC, expiring CNIC, and passed customers with wallets, PINs and linked banks |
| Applications (≈29) | draft, submitted, under review, pending at stage 1/3/5, returned with customer-visible objection, approved (KFS issued), KFS acknowledged, cooling-off (live timer), ready, failed payout (back in queue), disbursed, rejected at review, rejected in approval, withdrawn, cancelled in cooling-off |
| Loans (≈12) | active current, active with late-paid instalment (charity amount), overdue 2 instalments, partially paid, severely delinquent (doubtful), early-settled closed, closed with NOC; failed & pending collections; duplicate callbacks |
| Wallet | top-ups via Raast/Easypaisa/JazzCash, transfers, bill payments, internal & external merchant payments, withdrawals, pending/failed/timed-out/reversed/duplicate-callback top-ups, failed bill & withdrawal (holds released), refunded merchant payment, reversed transfer, restricted wallet |
| Servicing | delinquency run, recovery assignments, contact logs with promises to pay (kept/broken), delay notices, reminders |
| Support | 6 complaints (open → closed, escalated collections-conduct case), 7 leads across the pipeline |
| Finance | monthly rent bills paid, internet bill with failed payment, approved payment awaiting release, pending payment & vendor advance, posted / pending / draft / reversed manual JVs, paid & pending expenses, payroll posted last month + pending this month, fixed assets with monthly depreciation, budget for the FY, charity disbursement request, investor profit distribution, other receivables, reconciliation batch with a bank short-credit and an unidentified receipt moved to suspense, an approved reconciliation, closed historical periods, today's provisioning run |

Consistency rules (QA M21): every KYC check, application, visit and payment happens after the customer registered;
dealer items carry the product category they finance (scooty items for Sohni Scooty, laptops for Mera Laptop, …);
branch roles (loan officer, branch manager, visit officer, recovery agent) exist once per branch and only work their
own branch's customers; field-visit check-ins are on the scheduled date within ~60 m of the customer's coordinates
(accuracy ≤ 40 m); guarantors use synthetic CNICs that the simulators score as clean; operational decisions are made by
the owning role, never the Tenant Admin (payouts are requested and released by two Disbursement Approvers; journal
reversals are requested by the Accountant and approved by the Finance Officer); vendor-paid Murabaha cases record the
vendor invoice in the institution's name and a second user confirms ownership before the KFS is acknowledged. Each
tenant has a distinct colour and generated logo badge. All demo staff and platform users are enrolled in two-step
verification with a documented demo key.

Credentials are written to `docs/DEMO_CREDENTIALS.md` (password `Demo@12345`, wallet PIN `4826`).
Build a subset: `php artisan amcl:demo-reset --force --tenants=amcl,roshan`.
