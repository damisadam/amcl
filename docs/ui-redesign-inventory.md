# UI redesign — inventory & progress tracker

The source of truth for the "green Islamic-fintech" UI redesign. **Every screen the app can render is
listed here.** We work in batches; nothing counts as done until it has been verified (see *Verification*).
Update this file in the same change that touches a screen.

Reference mock-ups: `docs/ui-references/*.png` (8 images). They are design targets, not data sources —
all numbers on a screen must still come from the database (CLAUDE.md rule 12).

## Status legend

| Mark | Meaning |
|---|---|
| ☐ | Not started — still on the old look |
| ◐ | Inherits the new shell/components automatically but no page-level pass yet |
| ✎ | Page-level pass done, awaiting verification |
| ✅ | Done and verified (renders in crawl, screenshot checked at desktop + 390px, Urdu checked where the portal supports it) |
| — | Not a visual screen (redirect / download / PDF) — listed for completeness |

## Design language (extracted from the references)

| Token | Value | Where it shows |
|---|---|---|
| Shell green (dark) | `#0b3d24` → `#0f5132` gradient | Ops/platform sidebar, top bars, customer header, wallet card |
| Primary green | brand-700 `#15803d` / brand-800 `#166534` | Primary buttons, active nav pill, links |
| Page background | Mint wash `#f3f8f4` + faint geometric/mosque ornament (SVG, low opacity) | All authenticated portals and guest pages |
| Cards | White, `rounded-xl`, hairline border, soft shadow | Everywhere |
| KPI cards | White, **coloured left border** + round tinted icon + uppercase label + bold value + hint | Ops dashboard, platform overview |
| Status pills | Rounded-full, tinted fill: green (Passed/Clear/Active), amber/orange (Open/Restricted/Pending), red (overdue/failed) | Tables |
| Tables | Uppercase grey headers on light row, roomy rows, link-green IDs, kebab row actions | Lists |
| Demo banner | Amber bar with warning icon, bold "DEMO ENVIRONMENT" | Every page (demo mode only) |
| Top bar | Breadcrumb (Branch › Role) left; `English | اردو`, bell with red count, avatar initials + name + chevron right | Ops, platform, customer |
| Customer shell | Header full-width green; left nav as a floating white card; mosque illustration bottom-left | Customer portal |
| Typography | Inter; page titles 28–32px bold; section titles 16–18px semibold | Everywhere |

## Batches

| Batch | Scope | State |
|---|---|---|
| B0 | Foundations: tokens, background ornament, 5 layouts, shared components (stat, card, table, badge, page-header, pagination, empty, alert, input, icon set, flash, demo banner, locale-pair, product-art) | ✅ |
| B1 | The 8 reference screens, page-level | ✅ |
| B2 | Ops — Customers & Lending (remaining) | ◐ |
| B3 | Ops — Wallet & Payments, Finance (remaining) | ◐ |
| B4 | Ops — Reports & Administration | ◐ |
| B5 | Customer portal (remaining) | ◐ |
| B6 | Public, auth, MFA, errors, printable documents | ◐ (sign-in screens done; MFA, errors, documents left) |
| B7 | Vendor portal | ◐ |
| B8 | Platform admin (remaining) | ◐ |
| B9 | Final QA: full crawl, Urdu/RTL sweep, 390px sweep, docs/CHANGELOG | ☐ |

## Verification (per batch)

1. `php artisan test` — the existing suite must stay green (UI changes must not break Livewire tests).
2. `DB_DATABASE=amcl php artisan test tests/Smoke` — every screen still renders for every demo role.
3. Headless-Chrome screenshots of each screen in the batch at 1440px and 390px
   (`scripts/ui_screens.mjs`, output in `storage/app/ui-screens/`, not committed), compared to the
   reference image where one exists. The script prints `OVERFLOW +Npx` for any page wider than the
   viewport – a batch is not done while any of its screens shows that flag.
4. Customer-portal screens also screenshotted in Urdu (`dir="rtl"`).

## Screen inventory

`Ref` = reference image in `docs/ui-references/`.

### Public, auth & shared (layout: guest unless noted)

| # | Route | URL | View | Ref | Batch | Status |
|---|---|---|---|---|---|---|
| 1 | `home` | `/` | public/landing | institution-selection | B1 | ✅ |
| 2 | `tenant.entry` | `/t/{tenant}` | public/tenant-landing | financing-products | B1 | ✅ |
| 3 | `customer.login` | `/t/{tenant}/login` | customer/auth/login | — | B6 | ✅ |
| 4 | `customer.register` | `/t/{tenant}/register` | customer/auth/register | — | B6 | ✅ |
| 5 | `customer.password` | `/t/{tenant}/forgot-password` | customer/auth/forgot-password | — | B6 | ✅ |
| 6 | `vendor.request` | `/t/{tenant}/vendor-request` | public/vendor-request | — | B6 | ◐ |
| 7 | `guarantor.sign` | `/t/{tenant}/guarantor-sign/{sr}` | public/guarantor-sign | — | B6 | ◐ |
| 8 | `staff.login` | `/login` | auth/staff-login | — | B6 | ✅ |
| 9 | `platform.login` | `/platform/login` | auth/platform-login | — | B6 | ✅ |
| 10 | `mfa.challenge` / `mfa.setup` | `/account/mfa[/setup]` | auth/mfa (controller) | — | B6 | ◐ |
| 11 | `password.change` | `/account/password` | auth/change-password | — | B6 | ◐ |
| 12 | errors 403/404/419/500/503 | — | errors/* | — | B6 | ◐ |
| 13 | printable docs (KFS, agreement, NOC, receipt) | `documents.show`, receipts | documents/* | — | B6 | ◐ |
| 14 | notification bell (shared) | — | shared/notification-bell | all | B0 | ✅ |
| 15 | `t/{tenant}/staff`, `/ops` | redirects | — | — | — | — |
| 16 | `ops.admin.audit.export`, `app.applications.document`, `app.receipt`, `app.wallet.receipt`, `vendor.payments.receipt` | downloads / printable | documents/*, vendor/receipt | — | B6 | ◐ |

### Customer portal (layout: customer, mobile-first, Urdu)

| # | Route | URL | View | Ref | Batch | Status |
|---|---|---|---|---|---|---|
| 20 | `app.home` | `/app` | customer/home | customer-dashboard | B1 | ✅ |
| 21 | `app.support` | `/app/support` | customer/support/index | complaints-support | B1 | ✅ |
| 22 | `app.support.show` | `/app/support/{complaint}` | customer/support/show | — | B5 | ◐ |
| 23 | `app.onboarding` | `/app/onboarding` | customer/onboarding | — | B5 | ◐ |
| 24 | `app.products` | `/app/products` | customer/products/index | financing-products | B5 | ✅ |
| 25 | `app.products.show` | `/app/products/{product}` | customer/products/show | — | B5 | ◐ |
| 26 | `app.apply` | `/app/apply/{product}` | customer/applications/apply | — | B5 | ◐ |
| 27 | `app.applications` | `/app/applications` | customer/applications/index | — | B5 | ✅ |
| 28 | `app.applications.show` | `/app/applications/{application}` | customer/applications/show | — | B5 | ◐ |
| 29 | `app.financing` | `/app/financing` | customer/financing/index | — | B5 | ✅ |
| 30 | `app.financing.show` | `/app/financing/{loan}` | customer/financing/show | — | B5 | ◐ |
| 31 | `app.pay` | `/app/pay` | customer/payments/pay | — | B5 | ✅ |
| 32 | `app.wallet` | `/app/wallet` | customer/wallet/overview | customer-dashboard (wallet card) | B5 | ✅ |
| 33 | `app.wallet.topup` | `/app/wallet/top-up` | customer/wallet/topup | — | B5 | ◐ |
| 34 | `app.wallet.transfer` | `/app/wallet/transfer` | customer/wallet/transfer | — | B5 | ◐ |
| 35 | `app.wallet.withdraw` | `/app/wallet/withdraw` | customer/wallet/withdraw | — | B5 | ◐ |
| 36 | `app.wallet.bills` | `/app/wallet/bills` | customer/wallet/bills | — | B5 | ◐ |
| 37 | `app.wallet.merchant` | `/app/wallet/merchant` | customer/wallet/merchant | — | B5 | ◐ |
| 38 | `app.wallet.settings` | `/app/wallet/settings` | customer/wallet/settings | — | B5 | ◐ |
| 39 | `app.transactions` | `/app/transactions` | customer/transactions | — | B5 | ✅ |
| 40 | `app.documents` | `/app/documents` | customer/documents | — | B5 | ✅ |
| 41 | `app.notifications` | `/app/notifications` | customer/notifications | — | B5 | ✅ |
| 42 | `app.profile` | `/app/profile` | customer/profile | — | B5 | ✅ |

### Operations portal (layout: ops)

| # | Route | URL | View | Ref | Batch | Status |
|---|---|---|---|---|---|---|
| 50 | `ops.dashboard` | `/ops/dashboard` | ops/dashboard/index | operations-dashboard | B1 | ✅ |
| 51 | `ops.customers.index` | `/ops/customers` | ops/customers/index | customers-management | B1 | ✅ |
| 52 | `ops.finance.posting-rules` | `/ops/finance/posting-rules` | ops/finance/posting-rules | posting-rules | B1 | ✅ |
| 53 | `ops.customers.create` | `/ops/customers/create` | ops/customers/create | — | B2 | ◐ |
| 54 | `ops.customers.show` | `/ops/customers/{customer}` | ops/customers/show | — | B2 | ◐ |
| 55 | `ops.kyc.index` | `/ops/kyc` | ops/kyc/index | — | B2 | ◐ |
| 56 | `ops.leads.index` | `/ops/leads` | ops/leads/index | — | B2 | ◐ |
| 57 | `ops.complaints.index` | `/ops/complaints` | ops/complaints/index | — | B2 | ◐ |
| 58 | `ops.complaints.show` | `/ops/complaints/{complaint}` | ops/complaints/show | — | B2 | ◐ |
| 59 | `ops.applications.index` | `/ops/applications` | ops/applications/index | — | B2 | ◐ |
| 60 | `ops.applications.create` | `/ops/applications/create` | ops/applications/create | — | B2 | ◐ |
| 61 | `ops.applications.show` | `/ops/applications/{application}` | ops/applications/show (+_readiness) | — | B2 | ◐ |
| 62 | `ops.approvals.index` | `/ops/approvals` | ops/approvals/index | — | B2 | ◐ |
| 63 | `ops.visits.index` | `/ops/field/visits` | ops/field-visits/index | — | B2 | ◐ |
| 64 | `ops.visits.show` | `/ops/field/visits/{visit}` | ops/field-visits/show | — | B2 | ◐ |
| 65 | `ops.disbursements.index` | `/ops/disbursements` | ops/disbursements/index | — | B2 | ◐ |
| 66 | `ops.loans.index` | `/ops/loans` | ops/loans/index | — | B2 | ◐ |
| 67 | `ops.loans.show` | `/ops/loans/{loan}` | ops/loans/show | — | B2 | ◐ |
| 68 | `ops.loans.transfer` | `/ops/loans/transfer` | ops/loans/transfer | — | B2 | ◐ |
| 69 | `ops.collections.index` | `/ops/collections` | ops/collections/index | — | B2 | ◐ |
| 70 | `ops.recovery.index` | `/ops/recovery` | ops/recovery/index | — | B2 | ◐ |
| 71 | `ops.recovery.show` | `/ops/recovery/{loan}` | ops/recovery/show | — | B2 | ◐ |
| 72 | `ops.products.index` | `/ops/products` | ops/products/index | — | B2 | ◐ |
| 73 | `ops.products.create` | `/ops/products/create` | ops/products/create (+_terms) | — | B2 | ◐ |
| 74 | `ops.products.show` | `/ops/products/{product}` | ops/products/show | — | B2 | ◐ |
| 75 | `ops.vendors.index` | `/ops/vendors` | ops/vendors/index | — | B2 | ◐ |
| 76 | `ops.vendors.create` | `/ops/vendors/create` | ops/vendors/create (+_form) | — | B2 | ◐ |
| 77 | `ops.vendors.show` | `/ops/vendors/{vendor}` | ops/vendors/show, vendor-price-approvals | — | B2 | ◐ |
| 78 | `ops.staff-lending.index` | `/ops/staff-lending` | ops/staff-lending/index | — | B2 | ◐ |
| 79 | `ops.staff-lending.show` | `/ops/staff-lending/{corporate}` | ops/staff-lending/show | — | B2 | ◐ |
| 80 | `ops.wallets.index` | `/ops/wallets` | ops/wallets/index | — | B3 | ◐ |
| 81 | `ops.wallets.show` | `/ops/wallets/{wallet}` | ops/wallets/show | — | B3 | ◐ |
| 82 | `ops.wallet-transactions.index` | `/ops/wallet-transactions` | ops/wallet-transactions/index | — | B3 | ◐ |
| 83 | `ops.payments.index` | `/ops/payments` | ops/payments/index | — | B3 | ◐ |
| 84 | `ops.payments.show` | `/ops/payments/{order}` | ops/payments/show | — | B3 | ◐ |
| 85 | `ops.finance.dashboard` | `/ops/finance` | ops/finance/dashboard | operations-dashboard (KPI style) | B3 | ◐ |
| 86 | `ops.finance.journals.index` | `/ops/finance/journals` | ops/finance/journals/index | — | B3 | ◐ |
| 87 | `ops.finance.journals.create` / `.edit` | `/ops/finance/journals/create`, `/{journal}/edit` | ops/finance/journals/editor | — | B3 | ◐ |
| 88 | `ops.finance.journals.show` | `/ops/finance/journals/{journal}` | ops/finance/journals/show | — | B3 | ◐ |
| 89 | `ops.finance.accounts` | `/ops/finance/accounts` | ops/finance/accounts | — | B3 | ◐ |
| 90 | `ops.finance.accounts.ledger` | `/ops/finance/accounts/{account}/ledger` | ops/finance/account-ledger | — | B3 | ◐ |
| 91 | `ops.finance.periods` | `/ops/finance/periods` | ops/finance/periods | — | B3 | ◐ |
| 92 | `ops.finance.treasury` | `/ops/finance/treasury` | ops/finance/treasury | — | B3 | ◐ |
| 93 | `ops.finance.reconciliation.index` | `/ops/finance/reconciliation` | ops/finance/reconciliation/index | — | B3 | ◐ |
| 94 | `ops.finance.reconciliation.show` | `/ops/finance/reconciliation/{batch}` | ops/finance/reconciliation/show | — | B3 | ◐ |
| 95 | `ops.finance.payables` | `/ops/finance/payables` | ops/finance/payables | — | B3 | ◐ |
| 96 | `ops.finance.receivables` | `/ops/finance/receivables` | ops/finance/receivables | — | B3 | ◐ |
| 97 | `ops.finance.funds` | `/ops/finance/funds` | ops/finance/funds | — | B3 | ◐ |
| 98 | `ops.finance.provisioning` | `/ops/finance/provisioning` | ops/finance/provisioning | — | B3 | ◐ |
| 99 | `ops.finance.assets` | `/ops/finance/assets` | ops/finance/assets | — | B3 | ◐ |
| 100 | `ops.finance.payroll` | `/ops/finance/payroll` | ops/finance/payroll | — | B3 | ◐ |
| 101 | `ops.finance.expenses` | `/ops/finance/expenses` | ops/finance/expenses | — | B3 | ◐ |
| 102 | `ops.finance.budgets` | `/ops/finance/budgets` | ops/finance/budgets | — | B3 | ◐ |
| 103 | `ops.finance.islamic` | `/ops/finance/islamic` | ops/finance/islamic | — | B3 | ◐ |
| 104 | `ops.finance.tax` | `/ops/finance/tax` | ops/finance/tax | — | B3 | ◐ |
| 105 | `ops.reports.lending` | `/ops/reports/lending` | ops/reports/lending (+_bar, _filters) | — | B4 | ◐ |
| 106 | `ops.reports.wallet` | `/ops/reports/wallet` | ops/reports/wallet | — | B4 | ◐ |
| 107 | `ops.reports.financial` | `/ops/reports/financial` | ops/reports/financial | — | B4 | ◐ |
| 108 | `ops.reports.regulatory` | `/ops/reports/regulatory` | ops/reports/regulatory | — | B4 | ◐ |
| 109 | `ops.admin.users` | `/ops/admin/users` | ops/admin/users | — | B4 | ◐ |
| 110 | `ops.admin.roles` | `/ops/admin/roles` | ops/admin/roles | — | B4 | ◐ |
| 111 | `ops.admin.org` | `/ops/admin/organization` | ops/admin/organization | — | B4 | ◐ |
| 112 | `ops.admin.config` | `/ops/admin/configuration` | ops/admin/configuration | — | B4 | ◐ |
| 113 | `ops.admin.workflows` | `/ops/admin/workflows` | ops/admin/workflows | — | B4 | ◐ |
| 114 | `ops.admin.integrations` | `/ops/admin/integrations` | ops/admin/integrations | — | B4 | ◐ |
| 115 | `ops.admin.content` | `/ops/admin/content` | ops/admin/content | — | B4 | ◐ |
| 116 | `ops.admin.audit` | `/ops/admin/audit` | ops/admin/audit | — | B4 | ◐ |

### Vendor portal (layout: vendor)

| # | Route | URL | View | Ref | Batch | Status |
|---|---|---|---|---|---|---|
| 120 | `vendor.dashboard` | `/vendor` | vendor/dashboard | operations-dashboard (KPI style) | B7 | ◐ |
| 121 | `vendor.orders` | `/vendor/orders` | vendor/orders/index | customers-management (table style) | B7 | ◐ |
| 122 | `vendor.orders.show` | `/vendor/orders/{application}` | vendor/orders/show | — | B7 | ◐ |
| 123 | `vendor.originate` | `/vendor/originate` | vendor/originate | — | B7 | ◐ |
| 124 | `vendor.payments` | `/vendor/payments` | vendor/payments | — | B7 | ◐ |
| 125 | `vendor.items` | `/vendor/items` | vendor/items | — | B7 | ◐ |
| 126 | `vendor.merchant` | `/vendor/merchant` | vendor/merchant | — | B7 | ◐ |
| 127 | `vendor.documents` | `/vendor/documents` | vendor/documents | — | B7 | ◐ |
| 128 | `vendor.profile` | `/vendor/profile` | vendor/profile | — | B7 | ◐ |

### Platform administration (layout: platform)

| # | Route | URL | View | Ref | Batch | Status |
|---|---|---|---|---|---|---|
| 130 | `platform.dashboard` | `/platform` | platform/dashboard | platform-overview | B1 | ✅ |
| 131 | `platform.tenants.index` | `/platform/tenants` | platform/tenants/index | — | B8 | ◐ |
| 132 | `platform.tenants.create` | `/platform/tenants/create` | platform/tenants/create | — | B8 | ◐ |
| 133 | `platform.tenants.show` | `/platform/tenants/{tenant}` | platform/tenants/show | — | B8 | ◐ |
| 134 | `platform.health` | `/platform/health` | platform/health | — | B8 | ◐ |
| 135 | `platform.queues` | `/platform/queues` | platform/queues | — | B8 | ◐ |
| 136 | `platform.audit` | `/platform/audit` | platform/audit | — | B8 | ◐ |
| 137 | `platform.support-access` | `/platform/support-access` | platform/support-access | — | B8 | ◐ |
| 138 | `platform.settings` | `/platform/settings` | platform/settings | — | B8 | ◐ |
| 139 | `platform.demo` | `/platform/demo` | platform/demo | — | B8 | ◐ |

## Known follow-ups (carry into the batch named)

| Batch | Screen | Issue found in the B0/B1 sweep |
|---|---|---|
| ~~B5~~ | `app.products` | Done – product illustrations, green category pills. |
| ~~B5~~ | wallet sub-pages | Done – green gradient balance strip. |
| B5 | `app.financing` | Progress bar shows 0% for loans with no payments – check it reads `total_paid` correctly before restyling. |
| B6 | MFA challenge, error pages | Sign-in screens now use `x-auth-split`; MFA (controller view) and errors still use the plain centred card. |
| B2–B4 | detail screens (`*.show`) | KPI icons now automatic; segmented tabs; remaining: card header icons per section, inset tables where dense. |
| B3 | `ops.wallet-transactions.index`, `ops.collections.index` | Overflow fixed (filter row / 6-up KPI grid); re-verify at 1280px. |
| — | Ops portal language switch | Now shown in the ops/vendor top bars (reference). Ops screens are only partly translated and stay LTR – see DECISIONS. |

## Progress log

| Date | Batch | Notes |
|---|---|---|
| 2026-10-27 | — | Inventory created from `php artisan route:list` (every named GET route; downloads/redirects grouped). Reference images copied to `docs/ui-references/`. Baseline screenshots taken. |
| 2026-10-27 | B0 | New tokens (`shell-*`, `mint`), `bg-shell`/`bg-shell-bar`/`mosque`/`nav-item` utilities, ornament pattern (`public/images/ui/`). All 5 layouts restyled (green sidebar + top bar with breadcrumb, `English \| اردو`, bell, avatar). Components: KPI `x-stat icon=`, tinted pill badges, numbered pagination (also used by Livewire via `vendor/livewire/tailwind`), `x-input icon=`, `x-alert icon=`, `x-card icon=`, `x-empty icon=`, new `x-locale-pair`, `x-product-art`. ~25 icons added. Tables: one-line cells, `relative` scroll wrapper. Global `:where(.grid) > * { min-width: 0 }` fixed 21 screens that overflowed the viewport (mostly at 390px). |
| 2026-10-27 | B1 | All 8 reference screens given a page-level pass and compared side by side with `docs/ui-references/*.png`. Verified: full test suite (1 test updated: active-nav assertion now counts `aria-current="page"`), seeded crawl, screenshots at 1440 + 390px with zero page overflow, Urdu/RTL on customer + public pages. |
| 2026-10-27 | B1 rev. | Second pass against the references at the user's request: complaints & support (inset table, larger pills, ▶ FAQ markers, mosque-scene art, edge ornaments instead of tiled pattern, patterned header, ringed logo tile, outlined avatar), ops dashboard (darker sidebar, larger KPI chips, compact status bars, taller chart with gridlines, dense side-by-side performance tables), posting rules (sans event list in inset box, inset rules table), platform overview (tinted KPI cards, dense inset tables, boxed audit list). New: `x-table dense inset`, `x-card :divider`, `x-stat tinted`. |
| 2026-10-27 | all | Cross-app improvements: KPI icons inferred from the label (every `x-stat` in the app), container-query KPI cards (icon hides when narrow → no overflow), segmented `x-tabs`, stronger `x-dl` values, `btn-dark` in shell green, branded split sign-in (`x-auth-split`) for customer/staff/platform, product art on customer product list, green wallet header. Fixed a pre-existing double-escaping bug (`&#039;`, `&amp;` in hints) by converting 317 `attr="{{ }}"` component attributes to `:attr`. Full sweep: 276 captures; the 2 remaining overflows (collections, wallet@390) fixed and re-checked; full test suite green. |
| 2026-10-27 | B5 | Customer portal rebuilt to the new full-width references (user-supplied mock-ups for /app, products, applications, financing, wallet, pay, transactions, documents, notifications, support, profile): full-height sidebar with live badges (applications, unread notifications) and promo card, header page/product finder (⌘K), breadcrumbs, verse, mosque/wallet illustrations, 7-step tracker restyle, wallet limits tiles, payment-method cards (generic icons – no third-party logos), transaction summary + CSV export (audited `customer.transactions_exported`), notification tabs/search, profile two-column layout. `x-card` shows a round icon chip when icon + subtitle are given. Urdu strings added; verified EN/UR at 1440 + 390px, 0 overflow; tests green. |

