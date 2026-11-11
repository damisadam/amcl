# Known limitations (release 1 demo)

* **No live integrations.** All providers (NADRA, OCR, liveness, AML, Tasdeeq/Data Check, Raast, sponsor bank, Easypaisa,
  JazzCash, 1Bill, NIFT eSign, SMS/email/WhatsApp) are deterministic simulators. Live mode is blocked at runtime.
* **No real money movement**; settlement statements are simulated. The legal safeguarding model is unconfirmed (D-001).
* **Documents** (KFS, agreements, receipts, NOC) are printable HTML ("Print / Save as PDF"), not signed PDFs.
* **Biometrics / device binding** are browser-level simulations; staff OTP-MFA enforcement is configurable but not enforced (D-016).
* **Islamic accounting** recognises profit on collection, not accrual (D-004); Takaful is not separated (D-011).
* **Regulatory reports** use simulated formats until official SECP/SBP/Raast templates are supplied (D-015).
* **Guarantors** cannot self-sign remotely yet; staff complete the guarantor e-signature step.
* **Live chat** (Wallet §10) is replaced by structured complaints/support tickets.
* **Push notifications** are not used (web release); in-app + simulated SMS/email/WhatsApp only.
* **Salary disbursement** for corporate clients is out of scope pending stakeholder decision (D-014).
* **Merchant QR** is a visual placeholder, not an EMVCo/Raast QR.
* **Scalability targets** (agreement §8.5) are not benchmarked; no load testing, backup/DR or pen-test has been performed.
* **Test suite runtime**: each test provisions full tenants; the whole suite takes several minutes on MySQL.
* **Urdu** covers customer-facing flows, KFS and key screens; back-office screens are English-first.
