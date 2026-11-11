# Project overview

**AMCL multi-tenant fintech platform** — a web-first implementation of the Al-Mawakhat Solutions scope (AMCL V1.5
combined documentation and the development agreement V1.5): a Shariah-compliant digital financing platform on Raast,
serving multiple lending institutions (tenants) from one deployment.

| System | What it does |
|---|---|
| **DFS – customer portal** | Mobile-responsive web app replacing the planned mobile app: institution selection, Urdu/English, registration + OTP, e-KYC, product catalogue & calculator, applications, KFS, e-signature, cooling-off, repayments over every rail, NOC, grievances |
| **Digital Wallet** | Integrated in the customer portal (single onboarding/KYC): top-up, wallet-to-wallet, withdrawal, bills (1Bill), merchant QR, instalments, limits, PIN, devices, disputes |
| **LMS** | Back office: customers (CIF), KYC/AML review, leads, applications, bureau, field visits, credit scoring/DBR, configurable approvals, agreements, disbursement, servicing, collections, recovery, NOC, products, vendors, staff lending, company configuration |
| **FMS** | Per-tenant double-entry books fed by LMS/DFS/Wallet sub-ledgers; journals with maker-checker, periods, treasury, reconciliation, suspense, AP/AR, funds & investor reporting, provisioning/write-off, fixed assets, payroll, expenses, budgets, Islamic & charity accounting, tax, statements, regulatory reports |
| **Platform administration** | Tenant provisioning, branding, entitlements, health, queues, platform audit, support-access grants, demo tools — without default access to tenant customer or financial data |
| **Vendor portal** | Vendor-assisted point-of-sale origination, orders, payments, items, merchant wallet |

Release 1 is fully demonstrable with simulated integrations and seeded data; the adapter architecture is ready for
real providers. Start with `LOCAL_SETUP.md`, then `DEMO_CREDENTIALS.md` and the role-by-role demo guide in
`DEMO_GUIDE.md`. Architecture: `ARCHITECTURE.md`; requirement coverage: `REQUIREMENTS_TRACEABILITY.md`;
open questions: `DECISIONS.md`; limitations: `KNOWN_LIMITATIONS.md`.
