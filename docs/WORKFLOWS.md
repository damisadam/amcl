# Workflows and state machines

All transitions are validated server-side by the enum state machines in `app/Enums` (`assertCanTransitionTo`) and
performed only by the owning service, which writes history and audit records. A request that tries an invalid
transition is rejected with a user-safe message.

## Customer onboarding & KYC (DFS §1, Wallet §1)
Register (name, mobile, password, Terms + Privacy consent) → **OTP** (simulated SMS; code shown in demo banner) →
personal details & CNIC → address/employment/income → next of kin → CNIC front/back + live selfie upload →
itemised consents (NADRA, credit bureau, data sharing, e-signature, communications) → **e-KYC**:
CNIC OCR → NADRA Verisys → face match/liveness → sanctions/PEP screening.

`KycStatus`: `not_started → in_progress → passed | failed | pending_review`; `pending_review → passed | failed`.
* Any failed step → failed. Any manual-review step (partial NADRA match, face score < 0.80) → pending_review → decided by
  `kyc.review` with notes. AML potential match → `aml_status=potential_match`, decided only by `aml.review`
  (clear → `cleared_after_review`; confirm → `confirmed_match` + blacklist).
* On **passed**: customer active, wallet opened and activated when prerequisites hold (mobile verified, KYC passed, AML clear, not blacklisted).
* Identity corrections after KYC require a change request approved by a different user.

## Financing application (LMS §6, DFS §2)
```
draft → submitted → under_review → pending_approval → approved → kfs_acknowledged → cooling_off
      → ready_for_disbursement → disbursement_in_progress → disbursed
returned ↔ submitted (resubmission)          rejected / withdrawn / cancelled (terminal)
disbursement_in_progress → ready_for_disbursement (payout failed)
```
* **Submit** checklist: eligibility (amount/tenure range, age, min income, KYC passed, not blacklisted, no open
  application for the product, reapplication wait (30 days default), indicative DBR ≤ product max), vendor for vendor
  products, guarantors with consent, NADRA + bureau consents, mandatory documents per product version & amount tier.
* **Review**: bureau (Tasdeeq/Data Check, applicant + guarantors, consent required), field visits (scheduled to Visit
  Officers; check-in with GPS; proof photo mandatory; recommendation), credit assessment, objections.
* **Credit scoring**: internal score 0–100 = 20 + bureau (0–35) + DBR (≤20%:25, ≤30%:20, ≤40%:12, else 0) + income type
  (6–10) + visit (+10 / +4 / −20) + repeat history (+5 / −10) − AML (30) − guarantor fail (10) − adverse bureau (10).
  Grades A ≥80, B+ ≥70, B ≥60, C ≥50, D. Risk: high (D, AML, adverse bureau, negative visit), low (A/B+ and DBR pass), else medium.
  Recommendation: decline (DBR hard-gate fail or D), approve (grade ≥ product minimum, DBR pass, risk ≠ high), else refer.
  DBR = (existing monthly obligations [max of declared, bureau] + proposed monthly instalment) / monthly income.
* **Approval** via the configurable hierarchy (default: CRO → Manager Operations → Compliance → Risk → Disbursement
  Approval, + Approval Committee ≥ PKR 1m). Approve/reject/return/delegate; comments/reasons enforced; SLA overdue
  flags; stage plan frozen at submission. Return creates an objection for the originator (pop-up notification).
* **KFS** auto-generated on final approval (amount, profit rate, APR, tenor, all fees, late payment/charity, cooling-off,
  grievance contacts; EN/UR). Customer acknowledgement (time, locale, IP, device) is required before signing.
* **e-Signature**: digital documents (application form, agreement, undertaking) via simulated NIFT eSign with OTP;
  conditional documents (DPN, affidavit) via wet-ink path confirmed by staff. Guarantors sign agreement + undertaking.
* **Cooling-off** (24h default, per product) starts when the last document is signed; customer may cancel without charge;
  waivable only if the product allows. Daily job (and the disbursement screen) releases elapsed cases.
* **Disbursement** blocked unless: ready status, approval request approved, KFS acknowledged, all documents signed,
  cooling-off elapsed, recipient valid (active vendor with IBAN / linked bank / active wallet), fund capacity.
  One live payout per application; failure leaves no loan and no voucher.

## Loan servicing
`LoanAccountStatus`: `active ⇄ overdue → closed → noc_issued`; `active|overdue → written_off`.
* Schedule from the product version captured at disbursement (`terms_snapshot`); due dates skip holidays/non-working days.
* Repayment channels: wallet (instant), Raast / QR-IBAN alias / 1Bill / Easypaisa / JazzCash (payment order → callback),
  manual cash / bank transfer / cheque / salary deduction (staff, `repayments.record`).
* `LoanPaymentStatus`: `pending → succeeded | failed`, `succeeded → reversed` (compensating, reason, SoD).
* Daily run: late amounts (per day after grace, cap 90 days), instalment statuses, DPD, classification, reminders
  (5/1/0 days before and 1 after), promise-to-pay evaluation.
* Recovery: assignment, contact log (07:00–22:00 only, borrower/guarantor only, recorded calls), delay notices, PTP.
* Closure when nothing is outstanding → NOC issued by authorised staff (document generated, customer notified).
* Portfolio transfer between branches/officers with reason and audit.

## Wallet transactions
See FINANCIAL_LEDGER.md. Top-up: pending → succeeded (callback) | failed; withdrawal/bill/external merchant:
hold → capture | release; transfer & internal merchant: atomic debit/credit; reversal/refund: compensating entries.

## Vendor payment (FMS §3)
`draft → pending_approval → approved → processing → paid | failed(→ approved retry)`; `paid → reversed`;
`pending_approval → rejected | draft(returned)`. Approval via finance hierarchy (≥ PKR 2m adds Tenant Admin);
release by a user other than the requester; "paid" only on provider confirmation; GL posted on success only.

## Journal voucher
`draft → pending_approval → posted | rejected | draft(returned)`; `posted → reversed` (linked reversal voucher).

## Reconciliation
`draft → in_review → pending_approval → approved`. Auto-match by provider reference + direction + amount; exceptions:
amount mismatch, missing external (no bank line), missing internal (unidentified receipt → suspense), duplicate
reference. Exceptions must be resolved (manual match, suspense, annotated) before submission.

## Complaint (DFS §5.3)
`open → acknowledged → in_progress → (escalated) → resolved → closed`; resolved may reopen on customer reply.
SLA due date per tenant setting; disputes link to wallet transactions/loans; refunds for internal transactions use
wallet reversal (SoD).
