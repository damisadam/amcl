# Demo guide (role by role)

Password for every demo account: `Demo@12345` · wallet PIN `4826` · OTPs appear in the yellow "Simulated SMS" box.
**Two-factor login:** platform admins, Tenant Admins and approver/finance roles must enter an authenticator code after the
password. In demo mode the current code is shown on the challenge page; the shared demo key is in `DEMO_CREDENTIALS.md`.
Releasing a payout or vendor payment and approving a journal reversal ask for a fresh code (step-up).
**Segregation of duties:** Tenant Admin configures the institution but cannot approve, release or reverse anything.
Payouts are requested by one Disbursement Approver (`disbursement_approver1@…`) and released by another
(`disbursement_approver2@…`). Journal reversals are requested by the Accountant and approved by the Finance Officer.
For vendor-paid Murabaha cases, staff record the vendor invoice (Loan Officer) and a second user confirms asset
ownership on the application's **KFS & agreement** tab before the customer can accept the offer.
Full account list: `DEMO_CREDENTIALS.md`. Tenant codes: `amcl`, `amanah`, `roshan`, `karobar`, `barakah`.

## 1. Platform administrator — `/platform/login` (admin@platform.demo)
1. **Overview**: tenants, health, failed jobs — counts only (no customer data).
2. Log in with the typed organisation code (the institution list is no longer shown). **Provision tenant**: create "Demo Bank 6", features, initial admin (temporary password shown once).
3. Log in as that admin at `/login?org=<code>`: forced password change, empty but fully provisioned institution
   (roles, chart of accounts, workflows, integrations in demo mode). Switch back and **suspend** it → its users are locked out.
4. **Support access**: support@platform.demo requests access to `amcl` with a reason; security@platform.demo grants it; the
   support view shows operational counts only, and every view is audited.
5. **Integration health / queues / audit**, **Demo tools** → reset (type `RESET DEMO`).

## 2. Customer journey — `/t/amcl`
1. **Register** a new mobile → OTP → onboarding wizard: personal details (use a CNIC ending in `3` to pass, `7` manual
   review, `8` NADRA fail, `9` AML match), address/income, next of kin, CNIC + selfie upload, itemised consents → e-KYC.
2. **Wallet** is activated automatically; set PIN; **Top-up** with scenario *Success* (credited), *Duplicate callback*
   (credited once), *Pending* (use "Simulate callback"), *Failure* (nothing credited).
3. **Apply for Financing** → Motorbike Sahulat → choose dealer & bike, tenure, guarantor, documents → submit.
4. Existing AMCL customers (same pattern in every tenant):
   | Login | What to show |
   |---|---|
   | `customer25@amcl.demo` | Approved – read the **KFS in English/Urdu** and acknowledge |
   | `customer26@amcl.demo` | KFS acknowledged – **sign documents with OTP** (DPN/affidavit shown as wet-ink at branch) |
   | `customer24@amcl.demo` | **Cooling-off** countdown – cancel without charge |
   | `customer30@amcl.demo` | Application **returned** with an objection to answer, then resubmit |
   | `customer27…29@amcl.demo` | Applications waiting at approval stages 1 / 3 / 5 |
   | `customer12@amcl.demo` | Active financing: schedule, **pay instalment** (wallet or Raast/QR-IBAN/1Bill/Easypaisa/JazzCash), receipts |
   | `customer14@amcl.demo`, `customer18@amcl.demo` | Overdue financing with late-payment (charity) amounts and recovery contacts |
   | `customer11@amcl.demo` | Severely delinquent (doubtful classification) |
   | `customer9@amcl.demo` / `customer10@amcl.demo` | Closed – **NOC issued** / closed awaiting NOC |
   | `customer3@amcl.demo` / `customer4@amcl.demo` / `customer5@amcl.demo` | KYC manual review / failed (NADRA mismatch) / AML potential match (see ops KYC queue) |
5. **Complaints & Support**: open a transaction dispute linked to a top-up. Switch language to **اردو** anytime.

## 3. Loan origination — `/login?org=amcl`
* **Loan Officer** (`loan_officer1@amcl.demo`): leads → convert; create an application for a walk-in customer.
* **Branch Manager** (`branch_manager@amcl.demo`): applications queue → start review → schedule a field visit.
* **Visit Officer** (`visit_officer1@amcl.demo`): Field visits → check in (GPS) → proof photo → recommendation.
* **Credit Analyst** (`credit_analyst@amcl.demo`): bureau check (applicant & guarantor) → credit assessment (DBR, grade, risk).
* **Branch Manager**: send for approval. Approval tasks then flow **CRO → Manager Operations → Compliance → Risk →
  Disbursement Approver** (`cro@`, `manager_operations@`, `compliance_officer@`, `risk_officer@`, `disbursement_approver@amcl.demo`):
  approve / return (customer sees the objection) / reject; self-approval and double-stage approval are blocked.
* **Compliance Officer**: KYC & AML review queue (manual review + PEP match), document verification, blacklist.
* **Tenant Admin** (`admin@amcl.demo`): Approval workflows → add a stage or change thresholds (in-flight cases keep their plan).

## 4. Disbursement & servicing
* **Disbursement Approver**: Disbursements → ready queue shows blockers → initiate with scenario *Failure* (no loan, back
  in queue) then *Success* (loan account, schedule, GL voucher, customer notified, vendor paid).
* **Accountant** (`accountant@amcl.demo`): record a cash repayment; **Finance Officer** reverses it with a reason (the
  recorder cannot).
* **Collections**: overdue/due lists, "Run delinquency now". **Recovery Agent** (`recovery_agent1@amcl.demo`): only assigned
  cases; log a call with promise-to-pay (outside 07:00–22:00 is refused; third parties cannot be contacted).
* **Loan account**: schedule, payments, NOC issue for closed loans, portfolio transfer.

## 5. Wallet & payments operations
* **Payment orders**: see each order's callbacks (processed / duplicate / rejected) and masked integration logs; simulate
  callbacks or a status inquiry for pending / timed-out orders.
* **Wallets**: ledger with before/after balances and integrity check; restrict/suspend with reason; edit limits.
* **Wallet transactions**: reverse an internal transfer (initiator cannot reverse).

## 6. Finance (FMS) — Accountant (maker) / Finance Officer (checker)
* Journal voucher: create balanced JV → submit → Finance Officer approves → posted; reverse with linked voucher.
* Payables: vendor bill (WHT) → payment request → approval → release (scenario Success / Failure).
* Reconciliation: open the in-review batch — amount mismatch & unidentified receipt (moved to suspense); run a new batch.
* Periods: try posting into a closed month (blocked); reopen requires Tenant Admin and a reason.
* Provisioning, write-off request, fixed assets & depreciation, payroll, expenses, budgets vs actual, charity fund, funds & investor report.
* Reports: trial balance (balanced), GL drill-down to source transactions, P&L, balance sheet (balanced), cash flow,
  lending (PAR, approval turnaround), wallet (three-way safeguarding check), regulatory (simulated formats).

## 7. Vendor portal — `/login?org=amcl` with `vendor@amcl.demo`
Orders financed through the dealer, **New customer request** (point-of-sale origination), payments received
(simulated references), items, merchant QR (simulated), documents. Public vendor request form: `/t/amcl/vendor-request`.

## 8. Security checks to show
Log in as `loan_officer1@amcl.demo` and open `/ops/finance/journals` → 403. Change an id in a URL to another tenant's
record → 404. Audit log (Admin › Audit) shows every action with old/new values and correlation ids. Integration
settings show only masked secrets.
