# Architecture

## Shape
A **modular monolith**: one Laravel 12 application, one MySQL database, five portals sharing one identity,
authorization, audit, ledger, payment and notification foundation (agreement §9.1 "API-first, clear domain boundaries").

```
Browser (Livewire 3 + Alpine + Tailwind)          Provider simulators / webhooks
   │  customer /app   ops /ops   vendor /vendor        │
   │  platform /platform   public /, /t/{code}          ▼
   ▼                                          /webhooks/{tenant}/{provider}
Middleware: session → SetTenantContext → bindings → auth → type:* → feature:* → SecurityHeaders/TrackDevice
   ▼
Livewire screens (thin)  ──calls──►  Domain services (app/Services/*)  ──►  Eloquent models (tenant-scoped)
                                         │            │          │
                                         │            │          └► MySQL (system of record)
                                         │            └► GeneralLedger (posting rules → journal vouchers)
                                         ├► PaymentOrchestrator ─► IntegrationManager ─► simulators (integration_logs)
                                         ├► ApprovalEngine (workflows/stages per tenant)
                                         ├► Notifier ─► DB notifications + simulated SMS/email/WhatsApp
                                         └► Auditor (append-only audit_logs)
Redis: cache, queues (Horizon: default, callbacks, broadcasts), Reverb pub/sub — never a system of record.
Reverb: private channels per user / tenant ops / platform health — UX only, persisted state first.
Scheduler: amcl:daily-servicing (06:00), amcl:month-end, horizon:snapshot.
```

## Domain boundaries (`app/Services`)
| Domain | Responsibility |
|---|---|
| Tenancy | provisioning templates, tenant settings |
| Identity | authentication, lockout, OTP, user admin |
| Kyc | onboarding, e-KYC orchestration, manual KYC/AML review, change requests |
| Products | versioned product terms, eligibility, financing calculator (schedule, APR) |
| Lending | applications, underwriting, agreements/KFS/e-sign/cooling-off, disbursement, repayments, servicing, recovery, leads, vendor origination, staff lending |
| Approvals | generic multi-stage approval engine + per-type handlers |
| Wallet | wallet lifecycle, ledger, operations, linked accounts |
| Payments | payment orders, provider simulation, callbacks, purpose handlers |
| Accounting | GL, periods, financial reports, AP/vendor payments, reconciliation, finance operations |
| Integrations | API management layer, deterministic gateways |
| Documents | private versioned storage |
| Notifications / Support / Audit | notifier, complaints, audit trail |

## Key cross-cutting patterns
* **Tenant scope trait** + context middleware (TENANCY_AND_SECURITY.md).
* **State machines** on enums; services own transitions.
* **Idempotency** keys on payment orders, wallet transactions, loan payments, disbursements, journal vouchers; inbox
  de-duplication for callbacks; unique ledger postings.
* **Money** as bcmath strings (`App\Support\Money`), decimal(18,2) columns.
* **Correlation id** per request/job shared by audit and integration logs.
* **Configuration over code**: roles/permissions, approval workflows, products & versions, charges, required documents,
  posting rules, chart of accounts, settings, integrations and templates are tenant data, editable in the UI.

## Front-end
Livewire full-page components per portal with a shared Blade component kit (`resources/views/components`), Tailwind v4
theme (green/black/white), Alpine for small interactions (modals, geolocation, countdowns). Customer portal is
mobile-first with bottom navigation and Urdu RTL support. Real-time updates refresh components via a `realtime`
Livewire event; every live screen also polls (30 s) as a fallback.
