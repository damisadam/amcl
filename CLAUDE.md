# AMCL Multi-Tenant Fintech Platform — project instructions

Web-first (no native apps) modular monolith implementing the AMCL V1.5 scope: **LMS, DFS (customer
portal), Digital Wallet, FMS, Multi-tenant platform administration**, plus a Vendor portal.
All external integrations are **deterministic simulators** (demo mode). Source requirements:
`AMCL V1.5 MultiTenant Combine Doc.docx` and the Pamex Soft / Al-Mawakhat agreement (scope §4–§9).
Longer explanations live in `docs/` — read the relevant doc before changing a module.

## Stack
Laravel 12 · PHP 8.2 · Livewire 3 (+ Alpine bundled) · Tailwind v4 · MySQL 8 · Redis · Horizon · Reverb.
Local DB user `admin/admin`; dev DB `amcl`, test DB `amcl_test` (override with `DB_DATABASE=...`).

## Layout
- `app/Models` — Eloquent models (flat). Tenant-owned models use `Concerns\BelongsToTenant`.
- `app/Enums` — statuses; state machines via `StateMachine` trait (`assertCanTransitionTo`).
- `app/Services/<Domain>` — ALL business logic: Tenancy, Identity, Kyc, Products, Lending, Approvals,
  Wallet, Payments, Accounting, Integrations, Documents, Notifications, Audit, Support.
- `app/Livewire/<Portal>/<Module>` — screens (thin: validate → call service → render). Views in
  `resources/views/livewire/<portal>/<module>/`. Shared Blade components in `resources/views/components`.
- Routes: `routes/web.php` (public/auth), `routes/customer.php` (/app), `routes/ops/*.php` (/ops),
  `routes/vendor.php`, `routes/platform.php`. Navigation: `config/navigation.php`.
- Config catalogues: `config/permissions.php`, `roles.php`, `chart_of_accounts.php`, `posting_rules.php`, `integrations.php`.

## Non-negotiable rules
1. **Tenant isolation.** Never accept a tenant id from the browser. Context comes from the logged-in
   user or the validated `/t/{code}` entry (`SetTenantContext`). Queries fail closed with no context.
   Use `TenantContext::run($tenant, fn)` in jobs/commands; `TenantContext::bypass()` only for
   platform-level aggregate code. Jobs must carry `tenantId` and restore context.
2. **Authorization.** Permission keys (`module.resource.action`) are checked with
   `$user->hasPermission()` / `$this->need()` in every Livewire mount AND action. Record-level rules:
   `App\Policies\RecordAccess`. Hiding menu items is not authorization.
3. **Money.** Never floats. Use `App\Support\Money` (bcmath strings, 2dp). DB columns `decimal(18,2)`.
4. **Wallet balances** change only via `Services\Wallet\WalletLedger` inside a DB transaction with row locks.
5. **External money movement** only via `PaymentOrchestrator` (payment order → provider → callback →
   purpose handler). Never set a payment/disbursement/transaction to success directly.
6. **Accounting.** Every financial event posts through `GeneralLedger::postEvent()` using tenant
   posting rules (idempotency key per event+source). Posted vouchers are immutable; correct with
   `reverse()`. No posting into closed periods. Manual JVs are maker-checker.
7. **Status changes** go through the owning service (e.g. `ApplicationService::transition`), which
   validates the state machine, writes history and `Auditor::record()`.
8. **Approvals** use `ApprovalEngine` (configurable workflows/stages per tenant; never hardcode the
   5 loan stages). Requester / creator cannot approve; one user acts on one stage.
9. **Audit** every sensitive action with `Auditor::record()`; never log secrets, passwords, OTPs or full CNICs.
10. **Integrations** via `IntegrationManager::call()` with a deterministic simulator; credentials are
    encrypted (`IntegrationSetting::credentials`) and only shown masked. Live mode is blocked in release 1.
11. Business-rule errors throw `App\Exceptions\DomainException` (message safe for users). Livewire
    screens wrap actions in `$this->attempt(fn () => ..., 'Success message')` (`HandlesActions`).
12. Demo data must be fictional; synthetic CNICs; dashboards/reports must query the database.

## UI conventions
Livewire full-page components with `#[Layout('layouts.ops'|'layouts.customer'|'layouts.platform'|'layouts.vendor'|'layouts.guest')]`.
Use components: `x-page-header`, `x-card`, `x-stat`, `x-table`/`x-th`, `x-status`, `x-badge`, `x-money`,
`x-input`, `x-select`, `x-textarea`, `x-checkbox`, `x-modal`, `x-dl`, `x-empty`, `x-alert`, `x-tabs`, `x-icon`.
Lists use `WithDataTable` (search/sort/paginate). Green = primary/success, red = error/overdue,
amber = pending. Customer pages are mobile-first and support Urdu (`__()` strings, `lang/ur.json`).
Money-moving forms pass `$this->idem` as the idempotency key. Demo scenario selectors appear only when
`config('integrations.demo_mode')` is true.

## Commands
- Setup: `composer install && npm install && npm run build && php artisan migrate --seed`
- Demo data reset (local/demo only): `php artisan amcl:demo-reset` (refuses when `APP_ENV=production`)
- Tests: `php artisan test` (MySQL `amcl_test`); a module: `php artisan test tests/Feature/<Module>`
- Workers: `php artisan horizon`, `php artisan reverb:start`, `php artisan schedule:work`
- Service smoke run: `QUEUE_CONNECTION=sync php artisan tinker --execute "require base_path('scripts/smoke.php');"`
- Seeded-data crawl of every screen (after demo reset): `DB_DATABASE=amcl php artisan test tests/Smoke`
- Real parallel wallet race check: `php scripts/concurrency_check.php` (resets scratch DB `amcl_test_7`)
- Regenerate generated docs: `php scripts/generate_docs.php` (roles, posting rules, COA) and
  `php artisan tinker --execute "require base_path('scripts/generate_schema_doc.php');"` (DATABASE_SCHEMA.md)

## Dangerous operations
Never run `migrate:fresh`/`db:wipe`/demo reset against production. Never commit real credentials.
Never enable live provider mode without an approved adapter. Never edit posted journals or ledger
entries in SQL. Never widen `TenantContext::bypass()` usage into tenant-facing code.

## Documentation rule
When behaviour, workflows, permissions or decisions change, update the relevant file in `docs/`
(smallest section) and add a line to `docs/CHANGELOG.md`. Record unresolved business/legal/
accounting questions in `docs/DECISIONS.md`.
