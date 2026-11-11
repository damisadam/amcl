# Platform super-admin portal, Vendor portal, public vendor request

## Platform portal (`/platform`, user type `platform`, layout `layouts.platform`)

Platform admins operate the platform. They see **counts and health only**. Cross-tenant code runs in
`TenantContext::bypass()` inside `App\Services\Platform\*` and never returns customer-level rows,
identities, balances, books or integration credentials.

| Route | Screen | Permission | Notes |
|---|---|---|---|
| `platform.dashboard` `/platform` | Overview | `platform.tenants.view` | Tenants by status, feature matrix, per-tenant counts (users, customers, active loans, pending payments, integration errors 24h, last activity), integration health 24h, failed jobs, Redis, recent platform audit |
| `platform.tenants.index` | Tenants list | `platform.tenants.view` | Search, status filter, sort |
| `platform.tenants.create` | Provision tenant | `platform.tenants.manage` | `TenantProvisioner::provision`; admin gets `must_change_password`; temp password shown once |
| `platform.tenants.show` `/platform/tenants/{code}` | Tenant | view / `manage` / `suspend` | Profile & branding, feature entitlements, status activate/suspend/deactivate (reason ≥ 5 chars), entry points, admin list, reset initial admin password (shown once), provider modes (no credentials), platform audit for the tenant |
| `platform.health` | Integration health | `platform.health.view` | Per tenant × provider: calls, success/failure/timeout %, latency; recent errors (codes only, no payloads); demo/live matrix |
| `platform.queues` | Queues & jobs | `platform.health.view` | Failed jobs (retry / forget, audited), Redis ping, Reverb config, Horizon link |
| `platform.audit` | Platform audit | `platform.audit.view` | Events where actor is platform, action `platform.*`, or tenant is null. Tenant business audit is excluded |
| `platform.support-access` | Support access | `platform.support_access.request` / `.grant` | Request (reason ≥ 15 chars, ticket, 1–24h) → granted by a **different** platform user → read-only support view (status counts, complaints, pending payments, integration error codes; no PII). Every view audited (`platform.support_view_accessed`). Revocable; expires automatically |
| `platform.settings` | Platform settings | `platform.settings.manage` | `platform_settings` table: platform name, default features for new tenants, demo banner text, maintenance notice |
| `platform.demo` | Demo tools | `platform.demo.reset` | Type `RESET DEMO`; refused in production or with demo mode off; dispatches `App\Jobs\ResetDemoEnvironment` → `amcl:demo-reset --force`; shows `docs/DEMO_CREDENTIALS.md` table |

Services: `PlatformMetrics`, `TenantAdministration`, `SupportAccessService`, `QueueHealth`, `PlatformSettings`.
Migration `2026_02_06_000001`: `platform_settings` table; `support_access_grants.duration_hours/granted_at/revoked_by`.
Suspension: `SetTenantContext` logs out users of non-active tenants on their next request.

## Vendor portal (`/vendor`, user type `vendor`, tenant feature `vendor_portal`, layout `layouts.vendor`)

Every query is restricted to `auth()->user()->vendor_id` (`Vendor\Concerns\ResolvesVendor`) inside the
vendor's tenant. Vendors never see credit, bureau, AML or other customers' data. CNICs are masked.

| Route | Screen |
|---|---|
| `vendor.dashboard` | Orders in progress / approved / disbursed, paid this month, payouts in flight, latest payments, profile completeness |
| `vendor.orders`, `vendor.orders.show` | Own financing orders; detail with status timeline (customer-visible history only), payout status, upload extra income documents and submit drafts |
| `vendor.originate` | Point-of-sale origination (LMS §2.8/§4.4) via `App\Services\Lending\VendorOriginationService` |
| `vendor.payments`, `vendor.payments.receipt` | Vendor payments (incl. disbursement payouts) with SIMULATED provider refs and reconciliation status; printable payment advice (own paid payments only) |
| `vendor.items` | Own item catalogue CRUD (other vendors' items → 404) |
| `vendor.merchant` | Merchant code, SIMULATED placeholder QR pattern (not a live payment instruction), merchant wallet balance & activity |
| `vendor.documents` | Upload VENDOR_REG / VENDOR_BANK (versioned, verified by staff) |
| `vendor.profile` | Contact details editable; bank change is a **request** only (audited `vendor.bank_change_requested`, notifies staff with `vendors.approve`) – payouts are never redirected without institution verification |

### Vendor-assisted origination rules
- Item must be the vendor's own active item; product must have `requires_vendor`.
- Existing CNIC is linked only if the mobile also matches; otherwise the vendor is told to refer the customer to the institution.
- New customers: `client_type=vendor_referred`, `onboarding_channel=vendor`, `origin_vendor_id`, KYC `not_started`.
- Consents are stored item by item with the snapshot prefixed `[Captured at vendor premises: <vendor> by <user>]`.
- The application is created via `ApplicationService::createDraft` (channel `vendor`, `created_by` = vendor user). It is submitted only when the customer's KYC has passed and the submission checklist is clear; otherwise it stays draft with "Customer KYC must be completed by the institution…". Staff with `applications.verify` are notified.

## Public vendor onboarding request
`GET /t/{tenant}/vendor-request` (`vendor.request`, `Livewire\Public\VendorRequest`): guest form bound to the validated
tenant entry; creates a Vendor with status `pending`, source `web_request`, optional documents; rate-limited (3/hour/IP);
notifies staff with `vendors.approve`. Approval happens in the ops vendor screens.

## Tests
`tests/Feature/Platform/PlatformPortalTest.php`, `tests/Feature/Vendor/VendorPortalTest.php`
(`DB_DATABASE=amcl_test_6 php artisan test tests/Feature/Platform tests/Feature/Vendor`).
