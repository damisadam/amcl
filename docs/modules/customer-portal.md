# Customer Web Portal (DFS + Wallet)

Mobile-first web replacement for the customer mobile app (DFS + Wallet docs). Layout `layouts.customer`
(bottom tab bar on phones, side nav on desktop), English/Urdu (RTL) via the header toggle or Profile.
Routes in `routes/customer.php` (prefix `/app`, names `app.*`, middleware `auth` + `type:customer`).
Code: `app/Livewire/Customer/**`, views `resources/views/livewire/customer/**`, Urdu strings `lang/json/customer/ur.json`.

## Access rules
- Only `customer` users; tenant comes from the user (never the URL).
- Every record is resolved through the logged-in customer (`CustomerContext::own()`); another customer's
  application / loan / complaint id returns **404**, documents return **403** (`RecordAccess::canDownload`),
  receipts are owner-only (`Customer\ReceiptController`). Wallet screens only see `$customer->wallet`.
- AML results are never shown; an AML hit shows as a generic "under review".
- Money actions carry `$this->idem` as the idempotency key (double submits never double-post), require the wallet PIN,
  and are rate-limited (`throttle:money` on Pay).

## Screens
| Route | Screen | Notes |
|---|---|---|
| `app.onboarding` | Onboarding wizard | OTP → personal (CNIC `12345-1234567-1`, 18+) → address/employment/income/obligations → next of kin → CNIC front/back + selfie (camera capture) → itemised consents (NADRA + credit bureau mandatory, text in chosen language) → e-KYC with per-step results. |
| `app.home` | Dashboard | KYC banner, wallet card + quick actions, action-needed list (draft, returned, KFS, signing, cooling-off), active financing with progress, recent activity, banners. Redirects to onboarding until KYC starts. |
| `app.products`, `app.products.show` | Catalogue & detail | Tenant products, Shariah structure explainer, live calculator (instalment, profit, total, APR) and eligibility hints (`EligibilityService`). |
| `app.apply` | Application wizard | Financing → guarantors (if required, with consent) → documents (product required docs, versioned) → checklist & submit. Drafts resume via `?application=`. |
| `app.applications`, `app.applications.show` | Applications | Timeline (customer-visible history), objection responses + resubmit, withdraw, KFS (EN/UR toggle) acknowledge, OTP e-signature of all digital docs, wet-ink items flagged for branch, cooling-off countdown with free cancel / waive (if product allows), disbursement status, reapply. |
| `app.financing`, `app.financing.show` | Live ledger | Outstanding breakdown, overdue/DPD, schedule, payments, receipts, NOC download. |
| `app.receipt`, `app.wallet.receipt` | Printable receipts | Allocation detail for loan payments; provider ref for wallet txns. |
| `app.pay` | Pay instalment | Wallet (PIN) or Raast / QR-IBAN / 1Bill / Easypaisa / JazzCash. External channels show a clearly labelled **SIMULATED** instruction (QR, IBAN alias, 1Bill consumer no.) and poll until the callback. |
| `app.wallet` (+ `topup`, `transfer`, `withdraw`, `bills`, `merchant`, `settings`) | Wallet | Balance/held/limits, prerequisites if not active, top-up (source + bank), transfer with masked recipient confirmation, withdraw to verified linked IBAN (simulated Raast title fetch), 1Bill inquiry → pay, merchant code payment, PIN, biometric flag, daily limit, linked banks, devices. |
| `app.transactions` | History | Wallet transactions (type/status/date filters, detail sheet, receipt, "report a problem") and loan repayments. |
| `app.documents` | Documents | All own documents, new versions, previous versions, expiry highlighting. |
| `app.notifications` | Notifications | Database notifications, mark read; bell in header updates via Reverb or 30 s polling. |
| `app.support`, `app.support.show` | Complaints | Categories incl. transaction dispute / refund request linked to a wallet txn or loan, SLA date, conversation thread, contacts, FAQ help centre. |
| `app.profile` | Profile & settings | Locked identity after KYC with correction requests (`OnboardingService::requestChange`), contact edit, language, consent status/history/withdrawal (optional ones), password, devices. |

## Simulated behaviour (demo mode only)
- OTPs (registration, password reset, e-signature) are shown in an amber "Simulated SMS" box.
- KYC scenario select (`config('integrations.kyc_scenarios')`); default is deterministic by CNIC last digit (0–6 pass, 7 manual review, 8 fail, 9 AML hit).
- Payment scenario select (`config('integrations.payment_scenarios')`) on top-up, withdraw, bills, merchant, external instalments;
  pending items offer "Simulate success/failure" and "Check status" (inquiry).
- Bills: any 8–14 digit reference returns a deterministic bill; references ending `00` are already paid.
- Linked accounts: valid PK IBAN format → title = customer name; IBANs ending `0000` → not found.

## Added services
- `App\Services\Lending\CustomerApplicationActions` – customer reply to an objection (audited, notifies raiser).
- `App\Services\Wallet\LinkedAccountService` – link/unlink bank accounts with simulated title fetch.

## Tests
`tests/Feature/Customer`: onboarding (pass / manual review / fail / validation), full journey
(apply → ops approval → KFS in Urdu → OTP sign → wet ink → disburse → PIN → top-up → wallet repayment, TB balanced),
pending Raast collection + simulated callback, wallet screens (scenarios, idempotent resubmission, transfer PIN,
withdraw hold/settle, bills, merchant), authorization (cross-customer, cross-tenant, staff vs customer portal, receipts),
and a render test of all 25 screens in EN and UR.

## Known limitations
- Guarantor e-signature is completed by staff (no separate guarantor login/link).
- Biometric toggle and camera capture are browser features only; no device biometrics in web.
- Live chat is not implemented (complaints + helpline instead).
- KFS/agreements/receipts are printable HTML ("Print / Save as PDF"), not server-generated PDFs.
