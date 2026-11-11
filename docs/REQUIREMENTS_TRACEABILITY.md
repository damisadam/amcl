# Requirements traceability

Source: **DFS** = Digital Financing App doc, **WAL** = Digital Wallet doc, **LMS** = Loan Management System doc,
**FMS** = Finance Management System doc (all in `AMCL V1.5 MultiTenant Combine Doc.docx`), **AGR** = Pamex Soft &
Al-Mawakhat Agreement V1.5. Status: ✅ implemented · 🟦 implemented with simulated integration · 🟨 partial / demo
simplification (see KNOWN_LIMITATIONS) · ⚖️ configurable pending decision (see DECISIONS).
Tests: `C/…` = tests/Feature/Core, `Cu` = tests/Feature/Customer, `Or` = Ops/Origination, `Sv` = Ops/Servicing,
`WA` = Ops/Wallet + Ops/Admin, `Fi` = Finance + Reports, `PV` = Platform + Vendor.

## Digital Financing System (DFS)
| Ref | Requirement | Module / screen | Entities | Permissions | Tests | Status |
|---|---|---|---|---|---|---|
| DFS 1.1 | Urdu/English UI incl. KFS & disclosures; tenant selector | `/`, `/t/{code}`, customer layout, KFS view | tenants, users.locale | public | Cu render (both locales) | ✅ |
| DFS 1.2 | Tenant-specific 12+ Shariah product catalogue, indicative terms, eligibility hints, configurable | app.products, ops.products | products, product_versions | products.manage | Cu, Sv | ✅ (8 products/tenant) |
| DFS 1.3 | CNIC OCR prefill + stored image | onboarding wizard, KycGateway::ocr | documents, kyc_verifications | — | Cu onboarding | 🟦 |
| DFS 1.4 | NADRA Verisys; failure → rejection/manual review | OnboardingService::runEkyc | kyc_verifications | kyc.review | Cu, Or | 🟦 |
| DFS 1.5 | Live selfie + liveness, single auditable record | onboarding (camera capture) | documents (SELFIE), kyc_verifications | — | Cu | 🟦 |
| DFS 1.6 | Sanctions/PEP screening → AML risk rating shared with LMS | KycGateway::screen, ops.kyc | customers.aml_status/aml_risk | aml.view, aml.review | Or | 🟦 |
| DFS 1.7 | Itemised timestamped consents (NADRA, Tasdeeq, data sharing) | onboarding consents, profile consent history | consents | — | Cu, C/Lending (bureau requires consent) | ✅ |
| DFS 2.1 | Tasdeeq / Data Check only after consent | UnderwritingService::requestBureau | bureau_checks | bureau.request | C, Or | 🟦 |
| DFS 2.2 | DBR / credit scoring engine; DBR ceiling as hard gate | EligibilityService, UnderwritingService::assess | credit_assessments | applications.assess | C, Or | ✅ |
| DFS 2.3 | Murabaha / Ijarah / hire-purchase structuring drives documents | products.shariah_structure, agreement pack | products, document_types | products.manage | Sv | ✅ |
| DFS 2.4 | KFS (amount, rate/APR, tenor, fees) EN/UR, acknowledged & timestamped before signing | AgreementService::generateKfs/acknowledgeKfs | key_fact_statements | agreements.manage | C, Cu | ✅ |
| DFS 3.1 | e-sign of Agreement, Undertaking, DPN, Affidavit; DPN/Affidavit flagged for special path | AgreementService::sign/confirmWetInk | signature_requests, document_types.signing_method | agreements.manage | C, Cu, Or | 🟦 |
| DFS 3.2 | Guarantor e-sign | signature requests for guarantors | signature_requests, guarantors | agreements.manage | C | 🟨 (staff-assisted) |
| DFS 3.3 | Signing audit trail (who, what, when, device/IP) | signature_requests, audit_logs | — | — | Cu | ✅ |
| DFS 4.1 | Disbursement to customer bank or vendor; schedule, instalments, accounting, status Active | DisbursementService | disbursements, loan_accounts, instalments, journal_vouchers | disbursements.initiate | C, Sv | 🟦 |
| DFS 4.2 | Instalment payment via Raast with callback → mark paid, outstanding, accounting, notification | RepaymentService::initiateCollection, CallbackProcessor | loan_payments, payment_orders, payment_callbacks | — | Cu, C | 🟦 |
| DFS 4.3 | Merchant QR / IBAN alias collection | app.pay channel qr_iban | payment_orders | — | Cu | 🟦 (labelled simulated) |
| DFS 4.4 | 1Bill/1LINK instalment payments | app.pay channel one_bill | payment_orders | — | Cu | 🟦 |
| DFS 4.5 | Utility bill payment via 1Bill (inquiry, pay, history, accounting) | app.wallet.bills | bill_payments | — | Cu, C | 🟦 (wallet-funded) |
| DFS 4.6 | Easypaisa / JazzCash repayment | app.pay channels | payment_orders | — | Cu | 🟦 |
| DFS 4.7 | Live ledger (paid / due / remaining) | app.financing.show | instalments, loan_payments | — | Cu | ✅ |
| DFS 5.1 | Reminders via WhatsApp/SMS/email/push with fallback | Notifier, ServicingService::sendReminders | notification_logs, notifications | content.manage | Cu, WA | 🟦 (no push) |
| DFS 5.2 | NOC issuance on full repayment | ServicingService::issueNoc | loan_accounts.noc_*, documents | loans.issue_noc | C, Sv | ✅ |
| DFS 5.3 | Structured grievance channel, named contacts, timelines | app.support, ops.complaints | complaints, complaint_messages | complaints.manage | Cu, WA | ✅ |
| DFS 6.1 | 24h cooling-off, cancel without charge, block disbursement | AgreementService, DisbursementService::blockers | loan_applications.cooling_off_* | — | C, Cu | ✅ |
| DFS 6.2 | No access to contacts/gallery | Permissions-Policy header; web app never requests them | — | — | — | ✅ |
| DFS 6.3 | Data residency, encryption at rest/in transit | deployment concern; secrets encrypted | — | — | — | 🟨 (hosting) |
| DFS 6.4 | RBAC, MFA, device binding | roles/permissions, user_devices | users, roles, user_devices | roles.manage | WA | 🟨 ⚖️ D-016 |
| DFS 6.5 | Collections conduct: 07:00–22:00, recorded calls, no third parties | ServicingService::logContact | recovery_contacts | recovery.work | Sv | ✅ |
| DFS 6.6 | Time-stamped audit logging across journey | Auditor everywhere | audit_logs | audit.view | all | ✅ |

## Digital Wallet (WAL)
| Ref | Requirement | Module / screen | Entities | Permissions | Tests | Status |
|---|---|---|---|---|---|---|
| WAL 1 | Registration, OTP, CNIC/KYC, face, AML, activation; per-tenant wallets, no mixing | OnboardingService, WalletService::open/activate | wallets | wallet.manage | Cu, C | 🟦 |
| WAL 2 | Bank→wallet top-up via Raast/JazzCash/Easypaisa/sponsor bank, webhook, notify | app.wallet.topup, WalletTopupHandler | wallet_transactions, payment_orders, wallet_ledger_entries | — | C, Cu | 🟦 |
| WAL 3 | Wallet→bank withdrawal with balance validation | app.wallet.withdraw (hold/capture) | linked_bank_accounts | — | C, Cu | 🟦 |
| WAL 4 | Wallet-to-wallet transfer, ledger only, same tenant | WalletService::transfer | wallet_transactions | — | C, Cu | ✅ |
| WAL 5 | Utility bill payment from wallet | app.wallet.bills | bill_payments | — | Cu | 🟦 |
| WAL 6 | Merchant QR payment (internal ledger / external via Raast) | app.wallet.merchant, vendor.merchant | vendors.merchant_code | — | Cu, PV | 🟦 |
| WAL 7 | Transaction history incl. refunds, reversals | app.transactions, ops.wallet-transactions | wallet_transactions | wallet.view | Cu, WA | ✅ |
| WAL 8 | Notifications (received, sent, bill, withdrawal, top-up, failed, refunds) | Notifier + Reverb | notifications | — | Cu | ✅ |
| WAL 9 | Settings: PIN, biometric, devices, linked banks, limits, security | app.wallet.settings | wallets, user_devices | — | Cu | 🟨 (biometric simulated) |
| WAL 10 | Support: complaints, disputes, refund requests, help | app.support, ops.complaints | complaints | complaints.manage | Cu, WA | 🟨 (no live chat) |
| WAL key principle | Internal vs external transactions drive accounting & reconciliation | posting rules, reconciliation | posting_rules, settlement_records | fms.* | C, Fi | ✅ ⚖️ D-001/D-002 |

## Loan Management System (LMS)
| Ref | Requirement | Module / screen | Entities | Permissions | Tests | Status |
|---|---|---|---|---|---|---|
| LMS 1.1 | User management, password policy, lockout, audit, link to employee, tenant scoped | ops.admin.users, AuthService | users, employees | users.manage | WA | ✅ |
| LMS 1.2 | Roles, granular & field-level permissions, multiple roles, 5-stage configurable approval | ops.admin.roles, ops.admin.workflows | roles, permissions, approval_workflows/stages | roles.manage, approvals.configure | WA, C | ✅ |
| LMS 2.1–2.4 | Client add/update (change tracking, CNIC approval), soft delete, 360° profile, tenant-scoped CIF | ops.customers.* | customers, customer_change_requests | customers.* | Or | ✅ |
| LMS 2.5 | Documents: categorise, versions, expiry alerts | DocumentStore, customer docs tab, KYC expiring list | documents | documents.* | Or, Cu | ✅ |
| LMS 2.6 | Notes | customer notes | customer_notes | customers.update | Or | ✅ |
| LMS 2.7 | Corporate client | ops.staff-lending | corporate_clients | staff_lending.manage | Sv | ✅ |
| LMS 2.8 / 4.3 / 4.4 | Vendor client & vendor-assisted POS origination | vendor.originate | customers.origin_vendor_id, loan_applications.channel=vendor | vendor user | PV | ✅ |
| LMS 3.1–3.4 | Products, sub-products, Islamic products (6 structures), loan product config | ops.products | products, product_versions | products.manage | Sv | ✅ |
| LMS 3.5 | Charges (processing, Takaful, late, prepayment, documentation; flat/%; upfront/added) | product versions | charges | products.manage | C, Sv | ✅ ⚖️ D-009/D-011 |
| LMS 4.1–4.2 | Vendor requests (web), vendor profile/bank/status | `/t/{code}/vendor-request`, ops.vendors | vendors, vendor_items | vendors.approve | PV, Sv | ✅ |
| LMS 5.1–5.4 | Staff registry, salaries, staff loan application, salary deduction | ops.staff-lending | corporate_staff, staff_salaries | staff_lending.manage | Sv | ✅ |
| LMS 5.5 | Salary disbursement | — | — | — | — | ⚖️ D-014 |
| LMS 6.1 | Lead arrival from all channels with status tracking | ops.leads | leads | leads.manage | Or | ✅ |
| LMS 6.2 | Loan request with guarantors | ops.applications.create, app.apply | loan_applications, guarantors | applications.create | Or, Cu | ✅ |
| LMS 6.3 / 10.4 | Tasdeeq applicant + guarantor | case file bureau tab | bureau_checks | bureau.request | Or | 🟦 |
| LMS 6.4 / 7.x | Team visit management & Visit Officer portal (location report, documents, proof, remarks) | ops.visits | field_visits, documents | field_visits.* | Or | ✅ |
| LMS 6.5 | Loan document management & e-sign readiness flags | document types, signature requests | document_types, signature_requests | config.manage | WA, Cu | ✅ ⚖️ D-013 |
| LMS 6.6 | Approved cases, KFS gate, cooling-off timer | ops.disbursements (ready) | loan_applications | disbursements.view | Sv | ✅ |
| LMS 6.7 | Disbursed cases, posting to FMS, closure & NOC status | ops.loans | loan_accounts | loans.view | Sv, C | ✅ |
| LMS 6.8 | Rejected cases with reason codes | ApplicationService::REJECTION_CODES | loan_applications.rejection_* | applications.reject | Or | ✅ |
| LMS 6.9 | Objections, return to user with pop-up notification | objections, approvals return | objections | applications.return | Or, Cu | ✅ |
| LMS 6.10 | Reapplication with 30-day default wait (admin-configurable) | ApplicationService::reapply, TenantSettings | loan_applications.previous_application_id | applications.reevaluate | Or, Cu | ✅ |
| LMS 6.11 | Re-evaluation request | reapply(reevaluation) | loan_applications.is_reevaluation | applications.reevaluate | Or | ✅ |
| LMS 8.1–8.3 | Recovery portal: assigned cases, delay notices, status notes, lifecycle reminders | ops.recovery, ServicingService | recovery_contacts | recovery.* | Sv | ✅ |
| LMS 9.1 | Offices/branches Tenant → Region → Area → Branch | ops.admin.org | regions, areas, branches | org.manage | WA | ✅ |
| LMS 9.2 | Blacklist checked at onboarding & request | config blacklist, EligibilityService | blacklist_entries | config.blacklist | WA, C | ✅ |
| LMS 9.3 / 9.9 | Holiday calendar & working days in due dates | ops.admin.config, FinancingCalculator | holidays, tenant_settings | config.manage | WA | ✅ |
| LMS 9.4 | Manage employees | ops.admin.org | employees | org.manage | WA | ✅ |
| LMS 9.5 | Loan approval hierarchy per product/branch/amount | ops.admin.workflows | approval_workflows | approvals.configure | WA, C | ✅ |
| LMS 9.6 / 9.7 | Loan categories; currency (PKR) | config | loan_categories, tenants.base_currency | config.manage | WA | ✅ |
| LMS 9.8 / 9.12 | Fund management; office access to funds | ops.finance.funds, ops.admin.org | funds, branch_fund | fms.funds.manage, org.manage | Fi, WA | ✅ |
| LMS 9.10 / 9.11 | Payment types; office loan products | config, org | payment_types, branch_product | config.manage | WA | ✅ |
| LMS 9.13 | Loan case / portfolio transfer with audit | ops.loans.transfer | portfolio_transfers | loans.manage | Sv | ✅ |
| LMS 10.0–10.6 | e-KYC, income & business cash-flow analysis, scoring with DBR, AML/CFT, risk rating | onboarding, case file assessment | credit_assessments, kyc_verifications | applications.assess, aml.* | C, Or | ✅ / 🟦 |
| LMS 11.1–11.2 | Document type management; mandatory per product/amount tier linked to verification step | config document types, product required docs | document_types, product_required_documents | config.manage, products.manage | WA, Sv | ✅ |
| LMS 12.1–12.3 | Tenant isolation, provisioning, per-tenant credentials, platform vs tenant admin | TenantProvisioner, platform portal | tenants, integration_settings | platform.* | C/TenantIsolation, PV | ✅ |

## Finance Management System (FMS)
| Ref | Requirement | Module / screen | Entities | Permissions | Tests | Status |
|---|---|---|---|---|---|---|
| FMS 1.1 | Per-tenant hierarchical chart of accounts (receivables, profit, charity, safeguarded wallet) | ops.finance.accounts | accounts | fms.coa.manage | Fi | ✅ |
| FMS 1.2 | Manual & system JVs with maker-checker, source reference | ops.finance.journals | journal_vouchers, journal_lines | fms.journals.* | C/GL, Fi | ✅ |
| FMS 1.3 | GL posting real-time/batch | GeneralLedger::postEvent | journal_lines | — | C | ✅ |
| FMS 1.4 | Branch / cost centre / business line segmentation | journal line dimensions, branch P&L | cost_centers | fms.view | Fi | ✅ |
| FMS 1.5 | Fiscal year, period close, no backdated posting | ops.finance.periods | fiscal_years, accounting_periods | fms.periods.* | C/GL, Fi | ✅ |
| FMS 2.1–2.3 | LMS / DFS / Wallet posting engines with event templates; internal vs external | posting rules | posting_rules | fms.posting_rules.manage | C, Fi | ✅ ⚖️ D-002/D-004 |
| FMS 2.4 | Inter-system reconciliation vs bank settlement | ops.finance.reconciliation | reconciliation_batches/items, settlement_records | fms.reconciliation.* | Fi | 🟦 |
| FMS 2.5 | Suspense account management | reconciliation → suspense, reclassify | suspense_items | fms.reconciliation.perform | Fi | ✅ |
| FMS 3.1–3.4 | Vendor bills (asset & operational), approval workflow, payment method, ledger & aging, advances | ops.finance.payables | vendor_bills, vendor_payments | fms.payables.* | Fi | ✅ |
| FMS 4.1–4.4 | Loan & other receivables, aging, provisioning & write-off | ops.finance.receivables/provisioning | other_receivables, loan_provisions, write_offs | fms.provisioning.manage | Fi | ✅ ⚖️ D-012 |
| FMS 5.1–5.5 | Bank accounts incl. safeguarded, Raast settlement recon, cash flow, fund transfers, liquidity alerts | ops.finance.treasury | bank_accounts, fund_transfers | fms.treasury.manage | Fi | 🟦 |
| FMS 6.1–6.4 | Funding sources, utilisation, investor reporting, Shariah profit distribution | ops.finance.funds | funds, profit_distributions | fms.funds.manage | Fi | ✅ |
| FMS 7.1–7.4 | Fixed asset register, depreciation, disposal, branch tracking | ops.finance.assets | fixed_assets, asset_depreciations | fms.assets.manage | Fi | ✅ |
| FMS 8.1–8.4 | Payroll, staff loan deduction reconciliation, benefits, PF/gratuity | ops.finance.payroll, staff lending | payroll_runs/lines | fms.payroll.manage | Fi, Sv | 🟨 (internal records; no payroll provider) |
| FMS 9.1–9.4 | Operational expenses, purchase requests, petty cash, finance approval workflow | ops.finance.expenses | expense_claims | fms.expenses.create | Fi | ✅ |
| FMS 10.1–10.3 | Budgets, variance, forecasting | ops.finance.budgets | budgets, budget_lines | fms.budgets.manage | Fi | ✅ |
| FMS 11.1–11.3 | Islamic profit recognition, charity fund, Shariah audit support | ops.finance.islamic | charity_disbursements | fms.islamic.manage | C, Fi | ✅ ⚖️ D-004/D-010 |
| FMS 12.1–12.3 | WHT, sales tax/FED, FBR reporting | ops.finance.tax | journal lines (wht_payable) | fms.tax.view | Fi | 🟨 (WHT only; simulated format) |
| FMS 13.1–13.4 | Statements (P&L, BS, CF, budget vs actual, targeted vs actual), SBP/SECP returns, Raast Annexure-A, dashboards | ops.reports.* | — | reports.* | Fi | ✅ / 🟨 D-015 |
| FMS 14.1–14.3 | Change log, SoD per type/threshold, audit drill-down | audit log, approval engine, GL drill-down | audit_logs | audit.view | C, WA, Fi | ✅ |
| FMS 15–16 | FMS configuration and per-tenant isolation | settings, workflows | tenant_settings | config.manage | C, Fi | ✅ |

## Agreement (AGR)
| Ref | Requirement | Where | Status |
|---|---|---|---|
| AGR §6 | Wallet integrated in DFS app with single onboarding/KYC | customer portal | ✅ |
| AGR §8.1 | Central web admin portal across LMS/DFS/Wallet/FMS (super & sub admins, branches, config, dashboards, monitoring) | ops + platform portals | ✅ |
| AGR §8.2 | Unified CIF shared across systems and reflected in FMS | customers.cif_no, journal_lines.customer_id | ✅ |
| AGR §8.3 | HO → Region → Branch → Department → User hierarchy, branch-wise visibility & reports | org, RecordAccess branch scoping, branch filters | ✅ |
| AGR §8.4 | No-code configuration (products, eligibility, rates, charges, docs, FAQs, banners, notifications, limits, features) | admin + products screens | ✅ |
| AGR §8.5 | Scalability, backup, DR | — | 🟨 not benchmarked |
| AGR §9.2 | Central API management layer (credentials, rate limits, retries, logging, monitoring) | IntegrationManager, integration settings, platform health | 🟦 |
| AGR §9.3 | Ledger design must allow a future deposit sub-ledger (not repurposing wallet liabilities) | separate wallet liability accounts; configurable COA | ✅ (design) |
| AGR §9.5 | Multi-tenant architecture | tenancy layer | ✅ |
| AGR Sched. D | MVP: onboarding, identity, product, Shariah structuring, approval, disbursement, e-sign, Raast repayment | full journey | ✅ / 🟦 |
| Client brief | Web-only first release, all APIs dummy, ≥5 tenants with complete data, API keys per tenant, green/black/white | whole platform | ✅ |
