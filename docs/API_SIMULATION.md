# Simulated APIs (demo mode)

Demo mode is controlled by `AMCL_DEMO_MODE` (must be `false` in production). Simulations are **deterministic** — the
same input always produces the same outcome, so demonstrations are repeatable. Every simulated artefact is labelled
SIMULATED (references, QR codes, settlement lines, KFS/agreements, credit reports).

## Identity (driven by the CNIC's last digit, or a forced scenario in demo UIs)
| Last digit | CNIC OCR | NADRA Verisys | Face match / liveness | AML / PEP |
|---|---|---|---|---|
| 0–6 | extracted | full match | score 0.90–0.99 → pass | clear |
| 7 | extracted | partial match → manual review | 0.64 → manual review | clear |
| 8 | extracted | **mismatch → KYC failed** | pass | clear |
| 9 | extracted | full match | pass | **potential PEP match** → compliance review |
Names containing "PEP" also produce an AML match. KYC scenarios selectable in demo: auto, pass, fail, manual_review, aml_match.

## Credit bureau (Tasdeeq / Data Check)
Score = deterministic function of the CNIC (470–840). `crc32(cnic) % 10 == 0` → no-hit (thin file).
Score < 540 → adverse (max DPD 60–120); < 620 → max DPD 30. Facilities 0–3 with monthly obligations. Scenario
`fail` → bureau timeout error. Reports are marked `simulated: true` and **are not real bureau data**.

## Payments (Raast, sponsor bank, Easypaisa, JazzCash, 1Bill)
Scenario per transaction (demo selector) or tenant default (`default_scenario` option):

| Scenario | Behaviour |
|---|---|
| `success` | accepted → signed callback `succeeded` (immediately, or after `callback_delay_seconds` via queue) |
| `delayed_success` | accepted → callback after ≥20 s through the `callbacks` queue (needs a worker; or "Simulate callback") |
| `pending` | stays pending until "Simulate callback" or "Check status" |
| `failure` | declined at initiation → order failed, funds released / nothing credited |
| `timeout` | order `timed_out`; a status inquiry later resolves it as succeeded |
| `duplicate_callback` | success callback delivered twice → second recorded as `duplicate`, no double credit |
| `reversal` | success, then provider reversal → compensating wallet/GL entries |
| `invalid_reference` | callback with unknown reference → rejected, order stays pending |
| `amount_mismatch` | callback amount ≠ order → rejected, order flagged `AMOUNT_MISMATCH` for reconciliation |

Successful/reversed orders write a simulated bank statement line for reconciliation.

## Other simulations
* **1Bill inquiry:** consumer ref must be 8–14 digits; refs ending in `00` are already paid; amount/due date deterministic.
* **Raast title fetch:** returns a deterministic account title for an IBAN.
* **NIFT eSign:** envelope + signature with document SHA-256 hash and simulated certificate/TSA.
* **SMS / email / WhatsApp:** always "sent" except WhatsApp to numbers ending in 0 (fails → automatic SMS fallback). OTP
  codes are displayed in the demo banner and never logged.
