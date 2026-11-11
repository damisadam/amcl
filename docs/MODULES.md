# Modules and screens

Detailed per-module notes: `docs/modules/*.md`.

| Portal / module | Entry | Doc |
|---|---|---|
| Public: institution selector, tenant landing, customer register/login/reset, vendor onboarding request | `/`, `/t/{code}` | customer-portal.md, platform-vendor.md |
| Customer portal (DFS + Wallet): onboarding & e-KYC, products & calculator, apply wizard, applications (KFS, e-sign, cooling-off), financing & receipts & NOC, pay instalment (all rails), wallet (top-up, transfer, withdraw, bills, merchant, settings), transactions, documents, notifications, complaints, profile | `/app` | [customer-portal.md](modules/customer-portal.md) |
| Ops – origination: dashboard, customers 360°, KYC/AML review, leads, applications case file, approvals inbox, field visits (Visit Officer portal) | `/ops` | [ops-origination.md](modules/ops-origination.md) |
| Ops – servicing: disbursements, loan accounts, collections, recovery (Recovery Agent portal), products & versions, vendors, staff lending | `/ops` | [ops-servicing.md](modules/ops-servicing.md) |
| Ops – wallet & payments, complaints, administration (users, roles, organisation, configuration, workflows, integrations, content, audit) | `/ops` | [ops-wallet-admin.md](modules/ops-wallet-admin.md) |
| Ops – finance (FMS) and reports | `/ops/finance`, `/ops/reports` | [finance-reports.md](modules/finance-reports.md) |
| Platform super-admin; vendor portal | `/platform`, `/vendor` | [platform-vendor.md](modules/platform-vendor.md) |
