# Integrations (API management layer)

All external calls go through `App\Services\Integrations\IntegrationManager::call()` (agreement §9.2):

1. Resolve the **tenant's** `integration_settings` row for the provider (never another tenant's).
2. Refuse if the provider is disabled; refuse **live** mode — release 1 contains no certified live adapter.
3. Execute the adapter (a deterministic simulator in demo mode).
4. Log to `integration_logs`: provider, operation, mode, correlation id, idempotency key, scenario, **masked**
   request/response (secrets, OTPs, PINs redacted; CNIC masked), status, error code, latency, subject.

| Provider key | Used for | Adapter |
|---|---|---|
| `cnic_ocr`, `nadra_verisys`, `liveness`, `aml_screening` | e-KYC | `KycGateway` |
| `tasdeeq`, `datacheck` | credit reports | `CreditBureauGateway` |
| `raast`, `sponsor_bank`, `easypaisa`, `jazzcash`, `one_bill` | money movement, bill inquiry, title fetch | `PaymentOrchestrator` + `CallbackProcessor` |
| `nift_esign` | e-signature envelopes | `ESignGateway` |
| `sms`, `email`, `whatsapp`, `push` | notifications (with SMS fallback) | `IntegrationManager::message()` |

## Tenant settings (Admin › Integration settings)
Per provider: enabled, demo/live mode, environment, base URL, only the relevant credential fields (client id/secret,
API key, merchant id, store id, integrity salt, biller id, webhook secret…), timeout, retries, default demo scenario,
callback delay. Credentials are encrypted at rest (`encrypted:array`), never sent to the browser (masked as
`••••••••abcd`), never logged; changes are audited (field names only). Switching to live requires
`integrations.go_live` and is still blocked at runtime until an adapter is certified.

## Webhooks
`POST /webhooks/{tenant}/{provider}` — CSRF-exempt, rate-limited, HMAC-SHA256 signature over the sorted payload using
the tenant's `webhook_secret`. Processed through the same `CallbackProcessor` as simulated callbacks (inbox,
de-duplication, amount check, state machine).

## Adding a real provider later
Implement the provider call inside the relevant gateway behind `IntegrationManager::call()` for `mode=live`, keep the
`ProviderResponse` contract, map provider error codes, register its webhook signature scheme, certify in sandbox,
then enable live per tenant. Business workflows do not change.
