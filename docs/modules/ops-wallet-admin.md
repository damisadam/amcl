# Ops portal – Wallet & Payments, Complaints, Tenant Administration

Owner: F4. Layout `layouts.ops`. Every screen checks permissions in `mount()` **and** in every action
(`HandlesActions::need()`); all records are tenant-scoped by global scopes, and route-model binding resolves
other tenants' ids to 404. Money/state changes go through the core services; nothing here edits balances,
payment states or posted entries directly.

## Routes

| Route name | URL | Permission | Screen |
|---|---|---|---|
| `ops.wallets.index` | `/ops/wallets` | `wallet.view` (+ feature `wallet`) | Wallet register, totals (liability, held), status/owner filters |
| `ops.wallets.show` | `/ops/wallets/{wallet}` | `wallet.view`; edits `wallet.manage` | Balance/held/available, **ledger integrity check** (ledger sum vs cached balance & held), limits edit (reason), status transitions (state machine, reason), transactions & immutable ledger entries |
| `ops.wallet-transactions.index` | `/ops/wallet-transactions` | `wallet.view`; reverse `wallet.reverse` | All wallet transactions, type/status/internal-external/date filters, totals, detail (ledger entries, payment order, callbacks), reversal/refund of internal transfers & internal merchant payments |
| `ops.payments.index` | `/ops/payments` | `payments.view` | Payment orders (purpose/provider/status/reconciliation filters) + **Callback inbox** tab |
| `ops.payments.show` | `/ops/payments/{order}` | `payments.view`; demo tools `payments.simulate` + demo mode | Order detail, timeline (audit), callbacks with result, masked integration logs; demo: simulate succeeded/failed/reversed, re-send last callback (duplicate), status inquiry |
| `ops.complaints.index` | `/ops/complaints` | `complaints.view` | Queue with status/category/priority/SLA-overdue/mine filters and KPIs |
| `ops.complaints.show` | `/ops/complaints/{complaint}` | `complaints.view`; actions `complaints.manage`; refund also `wallet.reverse` | Thread (customer-visible replies + internal notes), assign owner, state transitions (resolution mandatory), linked transaction/loan, dispute refund via wallet reversal |
| `ops.admin.users` | `/ops/admin/users` | `users.view`; `users.manage`; `users.reset_password` | Staff & vendor-portal users, multiple roles, deactivate/suspend/archive/reactivate, unlock, forced reset (temp password shown once), activity & devices |
| `ops.admin.roles` | `/ops/admin/roles` | `roles.view`; edit `roles.manage` | Role list, custom roles (optionally copied), permission matrix by module with sensitive flags |
| `ops.admin.org` | `/ops/admin/organization` | `org.manage` | Region → Area → Branch, departments, employees (payroll data), office loan products, office access to funds |
| `ops.admin.config` | `/ops/admin/configuration` | `config.manage` (blacklist tab: `config.blacklist`) | Typed tenant settings per group, holidays, payment types, loan categories, blacklist, document types (signing method), branding |
| `ops.admin.workflows` | `/ops/admin/workflows` | `approvals.configure` | Workflows for every `ApprovalType`; stages add/edit/remove/reorder, role, amount threshold, comment rules, return, SLA |
| `ops.admin.integrations` | `/ops/admin/integrations` | `integrations.view`; edit `integrations.manage`; live `integrations.go_live` | Per-provider settings (only that provider's fields), masked secrets, options, webhook URL, 30-day stats, recent logs, simulated health check |
| `ops.admin.content` | `/ops/admin/content` | `content.manage` | FAQs/banners/notices (EN+UR), notification templates per event/channel, notification delivery log |
| `ops.admin.audit` | `/ops/admin/audit` | `audit.view` | Audit trail filters (actor, action prefix, subject, dates, correlation id), before/after diff |
| `ops.admin.audit.export` | `/ops/admin/audit/export` | `audit.view` + `audit.export` | CSV export (streamed); the export itself is audited as `audit.exported` |

## Business rules enforced here

- **Wallet reversal / refund**: only succeeded *internal* transfers and internal merchant payments;
  compensating ledger entries via `WalletService::reverseInternal` (initiator cannot reverse, reason required).
  External transactions are reversed only by provider callbacks. Instalment payments are reversed from Repayments.
- **Wallet status**: transitions follow `WalletStatus` state machine; activation re-checks onboarding prerequisites;
  closing requires zero balance and no holds (service rule).
- **Demo simulation** (`payments.simulate` and `AMCL_DEMO_MODE=true`): callbacks go through the real
  `CallbackProcessor` (signature, inbox de-duplication, amount check, state machine). "Re-send last callback"
  replays the same provider event id and must come back as `duplicate` with no second posting.
- **Users** (`App\Services\Identity\UserAdminService`): users are created in the current tenant only, with
  `must_change_password`; only this tenant's roles can be assigned; admins cannot change their own roles or
  status; the last active Tenant Admin cannot be demoted, suspended, deactivated or archived.
- **Roles**: Tenant Admin always holds all permissions and is read-only; roles in use by users or approval
  stages cannot be deleted; permission changes are audited as added/removed keys.
- **Settings**: values are cast to the type of `TenantSettings::DEFAULTS`; cross-institution wallet transfers
  cannot be enabled (DECISIONS D-003). Blacklisting a CNIC flags matching customers; removing the last active
  entry clears the flag.
- **Workflows**: in-flight requests keep their `stage_plan` snapshot; a stage cannot be removed while requests
  wait at it; a workflow keeps at least one stage.
- **Integrations**: credentials are `encrypted:array`; the UI and Livewire snapshot only ever contain masked
  secrets (`••••••••last4`); untouched masked values are not re-saved; switching to live records
  `live_approved_by/at` but live calls remain blocked (`LIVE_ADAPTER_NOT_AVAILABLE`) in release 1.
- **Notification log**: recipients are masked; any 6-digit sequence is masked defensively (OTPs are never stored).

## Tests

`tests/Feature/Ops/Wallet/*`, `tests/Feature/Ops/Admin/*` — run with
`DB_DATABASE=amcl_test_4 php artisan test tests/Feature/Ops/Admin tests/Feature/Ops/Wallet` (23 tests).
Covered: permission denials, cross-tenant 404, status/limit audit, ledger integrity, reversal SoD, duplicate
callback idempotency, simulation blocked outside demo mode / without permission, secrets not rendered and
encrypted at rest, go-live permission, own-role/last-admin protection, role change effect, workflow editor,
settings + blacklist, org hierarchy, audit export permission + self-audit, render of every screen/tab.
