# Testing

Tests run against MySQL (the code relies on row locks and MySQL semantics). `phpunit.xml` uses database `amcl_test`;
override with `DB_DATABASE=...`. Each test provisions full tenants through the real provisioner, so the first test
in a run takes ~30–40 s (schema refresh).

```bash
php artisan test                                  # everything
php artisan test tests/Feature/Core               # tenancy, lending, wallet ledger, general ledger
php artisan test tests/Feature/Customer           # customer portal journeys
php artisan test tests/Feature/Ops                # operations portal modules
php artisan test tests/Feature/Finance tests/Feature/Reports
php artisan test tests/Feature/Platform tests/Feature/Vendor
php scripts/concurrency_check.php                 # real parallel wallet debits (separate processes, scratch DB amcl_test_7)
DB_DATABASE=amcl php artisan test tests/Smoke     # crawl every screen as every demo role on the seeded DB (not in default suite)
QUEUE_CONNECTION=sync php artisan tinker --execute "require base_path('scripts/smoke.php');"   # service smoke run
```

Fixtures: `tests/Concerns/BuildsTenants` (tenants, staff by role, KYC-passed customers, product, vendor) and
`tests/Concerns/DrivesLending` (drives an application to any stage, or a disbursed loan).

## What is covered (assertions check DB state, ledger effects, audit and permissions — not just HTTP 200)
* **Tenancy:** fail-closed scoping, cross-tenant write blocked, cross-tenant URL/document/report/export/approval access.
* **Lending:** full journey to closure + NOC; disbursement blocked before approvals; failed payout creates no loan/voucher;
  retry idempotency; SoD in approvals; allocation, partial payment, reversal restoring balances; delinquency, charity late amounts.
* **Wallet:** single credit on duplicate callbacks, failed/pending/invalid/mismatch callbacks, forged signatures,
  insufficient balance, PIN, limits, holds & release, provider reversal compensation, competing debits, three-way
  GL check, cross-tenant transfer blocked.
* **GL:** unbalanced rejected, maker cannot post, immutability, reversal idempotency, closed-period posting blocked,
  restricted reopen, idempotent system postings, per-tenant reports.
* **Modules:** each portal module has workflow tests and authorization-denial tests plus a render sweep over every screen/tab.

Results of the last full run are recorded in `docs/CHANGELOG.md`.
