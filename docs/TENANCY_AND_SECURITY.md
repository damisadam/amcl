# Tenancy and security

## Tenant model
Shared application + shared MySQL database. Every tenant-owned table has a non-null `tenant_id` and the model
uses `App\Models\Concerns\BelongsToTenant`:

* **Reads** get a global scope `tenant_id = <current tenant>`; with **no tenant context the scope returns no rows** (fail closed).
* **Creates** are stamped with the current tenant; `tenant_id` is never mass-assignable.
* **Updates/deletes** of a record belonging to another tenant throw `TenantIsolationViolation` (rendered as 404).
* `users` has the same scope whenever a tenant context exists.

### Where the context comes from (`SetTenantContext` middleware)
1. The authenticated user's `tenant_id` (staff, customer, vendor).
2. For guests, the validated entry point `/t/{code}` which stores `entry_tenant_id` in the session.
The middleware runs **before route-model binding** (priority list), so `/ops/loans/{id}` for another tenant's
id resolves to 404. Browser-supplied tenant ids are never used. Suspended/inactive tenants are logged out.

Jobs, commands and webhooks set context explicitly: `TenantContext::run($tenant, fn)`; webhooks fix the tenant
via the URL `/webhooks/{tenant}/{provider}` and authenticate with that tenant's HMAC secret.
`TenantContext::bypass()` is reserved for platform aggregates (counts only), provisioning and tests.

Isolation is proven by `tests/Feature/Core/TenantIsolationTest.php` plus cross-tenant cases in every module test
(view, update, approve, post, export, document download, wallet transfer, reports).

## Authentication
* Staff/vendors: organisation code + email + password (`/login`); customers: mobile/email + password at their tenant entry point; platform: `/platform/login`.
* Bcrypt hashing; password policy (length 10, mixed case, number, symbol); expiry for staff (90 days, forced change);
  lockout after 5 failures for 30 minutes (tenant-configurable); rate limiting per IP and per login; session regeneration; CSRF on all forms.
* Customer OTP (simulated SMS) for registration, password reset and e-signature; OTPs stored hashed, 5 attempts, 5-minute TTL, never logged.
* Device tracking (`user_devices`) with user-initiated revocation.
* **Two-step verification (TOTP, RFC 6238)** — `App\Services\Identity\{Totp,Mfa}`, `RequireMfa` middleware, `MfaController`.
  Mandatory for every platform user and for staff with the Tenant Admin role or any privileged permission
  (`approvals.act`, `disbursements.initiate`, `fms.journals.post`, `fms.journals.reverse`, `fms.payables.execute`,
  `kyc.review`, `aml.review`; tenant setting `security.staff_mfa_required`, default on). Enrolment shows a setup key
  and otpauth URI, issues 8 hashed one-time recovery codes; codes cannot be replayed (last time-step stored); 5
  attempts per 5 minutes. Until the challenge is passed in a session only the MFA pages, logout and password change are
  reachable — Livewire requests get 403. Step-up: `Mfa::recentlyConfirmed($user)` / `Mfa::requireRecent($user)` (10 min)
  for payout release and journal reversal. Demo users share a documented key; in demo mode the challenge page shows the
  current code in the SIMULATED banner. Tests disable enforcement via `AMCL_MFA_ENFORCE=false` (security tests re-enable it).
* Staff login takes a typed organisation code (prefilled from `/t/{code}/staff` or `?org=`); institutions are never
  listed and an unknown code gets the same error as a wrong password.

## Authorization
* Permission keys (`config/permissions.php`) granted through tenant roles (`config/roles.php` templates copied at provisioning).
  Multiple roles per user. `Gate::before` resolves dotted abilities to permissions.
* Every Livewire screen calls `need()` in `mount()` and again in each action; services re-check critical permissions.
* Record level (`App\Policies\RecordAccess`): branch scoping for applications/loans (unless `applications.view_all`),
  recovery agents only see assigned cases, visit officers only assigned visits, customers only their own records,
  vendors only their own vendor/orders.
* Sensitive fields: full CNIC and identity documents need `customers.view_sensitive`; AML/CFT data needs `aml.view`;
  integration secrets need `integrations.manage` and are only ever shown masked.

## Segregation of duties
Requester/creator cannot approve; one user acts on at most one stage of a request; JV maker cannot post; vendor
payment requester cannot release; repayment recorder cannot reverse; wallet transaction initiator cannot reverse;
customer change requester cannot approve; support access requester cannot self-grant.

## Data protection
Private disk (`storage/app/private`, not web-served) with random file names, MIME/size validation and an
authorised download route that sets `no-store` and a restrictive CSP. Sensitive document views by staff are audited.
Integration credentials are encrypted (`encrypted:array` cast with APP_KEY). The audit trail redacts secrets and masks CNICs.
Security headers (`SecurityHeaders`): nonce-based Content-Security-Policy (`script-src 'self' 'nonce-…' 'unsafe-eval'`
— `unsafe-eval` is required by the standard Alpine build bundled in Livewire 3; inline scripts must carry
`Vite::cspNonce()`; the only allowed inline handler is the hashed `window.print()`), `object-src 'none'`,
`frame-ancestors 'self'`, connect-src limited to self + the Reverb host; X-Frame-Options, nosniff, Referrer-Policy,
Permissions-Policy (no contacts/gallery access, DFS §6.2); HSTS on HTTPS/production; no `X-Powered-By`. Horizon's UI and
responses that set their own CSP (private document downloads) are not overridden.

Demo reset (`/platform/demo`) is two-person: one platform admin requests (typed phrase + reason), a different platform
admin approves, then the job runs; refused in production or without demo mode; demo credentials are only shown in demo mode.

## Audit
`audit_logs` is append-only at the model layer; entries record actor, tenant, action, subject, old/new values,
reason, correlation id, IP and user agent. Exports of audit data are themselves audited.

## Real-time channels
`App.Models.User.{id}` (own user), `tenant.{id}.ops` (active staff of that tenant only), `platform.health`
(platform users with health permission). Payloads carry ids/labels only — never balances, CNICs or AML data.

## Items needing external review
Penetration test (agreement §20.1), key management/rotation for APP_KEY, data-residency hosting, MFA enforcement,
WAF/rate limits at the edge, backup/DR targets (agreement §8.5).
