# Roles and permissions

_Generated from `config/permissions.php` and `config/roles.php` by `php scripts/generate_docs.php`._

Roles are **templates** copied into each tenant at provisioning; tenant admins can then edit permissions, create
custom roles and assign several roles to one user (Admin › Roles & permissions). Permissions marked 🔒 are sensitive.
Customers and vendor users are user *types* with fixed, record-scoped capabilities (own records only).

## Role descriptions

| Role | Key | Purpose |
|---|---|---|
| Tenant Admin | `tenant_admin` | Configuration, users, roles, approval hierarchies and read access; operational decisions require an explicit operational role. |
| Loan Officer | `loan_officer` | Originates leads and applications, collects documents, raises visits. |
| Branch Manager | `branch_manager` | Oversees branch portfolio, verification queue and assignments. |
| CRO (Credit Review Officer) | `cro` | Approval stage 1 – credit review of the case file. |
| Manager Operations | `manager_operations` | Approval stage 2 – operational checks. |
| Compliance Officer | `compliance_officer` | Approval stage 3 – AML/CFT, KYC and conduct compliance. |
| Risk Officer | `risk_officer` | Approval stage 4 – risk rating and DBR policy. |
| Disbursement Approver | `disbursement_approver` | Approval stage 5 – final sanction and disbursement release. |
| Credit Analyst | `credit_analyst` | Income analysis, DBR, bureau checks and scoring. |
| Approval Committee | `approval_committee` | Committee approvals for large amounts. |
| Visit Officer | `visit_officer` | Field verification visits (Visit Officer portal). |
| Recovery Agent | `recovery_agent` | Works assigned delinquent cases (Recovery portal). |
| Audit Officer | `audit_officer` | Read-only access to all modules, audit trail and AML flags. |
| Finance Officer | `finance_officer` | Approves and posts finance transactions, treasury and reconciliation. |
| Accountant | `accountant` | Prepares vouchers, bills, payment requests and reconciliations. |
| Customer Support | `customer_support` | Grievance redressal and wallet support. |
| Platform Admin (platform) | `platform_admin` | Operates the platform; no default access to tenant customer or financial data. |
| Platform Support (platform) | `platform_support` | Monitors health; may request time-limited tenant support access. |

## Permission matrix (default templates)

**ADM** = Tenant Admin · **LO** = Loan Officer · **BM** = Branch Manager · **CRO** = CRO (Credit Review Officer) · **MO** = Manager Operations · **CMP** = Compliance Officer · **RSK** = Risk Officer · **DA** = Disbursement Approver · **CA** = Credit Analyst · **AC** = Approval Committee · **VO** = Visit Officer · **RA** = Recovery Agent · **AUD** = Audit Officer · **FO** = Finance Officer · **ACC** = Accountant · **CS** = Customer Support

### Dashboard

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard.view`<br><small>View operational dashboard</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ | ✓ | ✓ |

### Administration

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `users.view`<br><small>View users</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |
| `users.manage`<br><small>Create / edit / deactivate users</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `users.reset_password`<br><small>Force password reset / unlock users</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `roles.view`<br><small>View roles</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |
| `roles.manage`<br><small>Manage roles & permissions</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `org.manage`<br><small>Manage regions, areas, branches, departments, employees</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `config.manage`<br><small>Company configuration (holidays, working days, payment types, categories)</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `config.blacklist`<br><small>Manage blacklist</small> | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |
| `content.manage`<br><small>Manage FAQs, banners, notices & notification templates</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `integrations.view`<br><small>View integration settings (masked)</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |
| `integrations.manage` 🔒<br><small>Edit integration credentials & settings</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `integrations.go_live` 🔒<br><small>Approve switching a provider to live mode</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `audit.view`<br><small>View audit logs</small> | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  | ✓ |  |  |  |
| `audit.export` 🔒<br><small>Export audit logs</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |

### Customers & KYC

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `customers.view`<br><small>View customers</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `customers.create`<br><small>Create customers</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `customers.update`<br><small>Update customers</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `customers.archive`<br><small>Archive customers</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `customers.export` 🔒<br><small>Export customer data</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `customers.view_sensitive` 🔒<br><small>View full CNIC, contacts and KYC evidence</small> | ✓ |  | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |  |  |  |
| `customers.approve_changes`<br><small>Approve sensitive customer changes (e.g. CNIC)</small> |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `kyc.view`<br><small>View KYC verification results</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `kyc.review`<br><small>Decide manual KYC reviews</small> |  |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |
| `aml.view` 🔒<br><small>View AML/CFT flags and screening results</small> | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  | ✓ |  |  |  |
| `aml.review` 🔒<br><small>Clear / confirm AML/CFT matches</small> |  |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |
| `documents.view`<br><small>View & download documents</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  |  |
| `documents.upload`<br><small>Upload documents</small> | ✓ | ✓ | ✓ |  |  | ✓ |  |  |  |  | ✓ |  |  |  |  |  |
| `documents.verify`<br><small>Verify / reject documents</small> |  |  | ✓ |  |  | ✓ |  |  | ✓ |  |  |  |  |  |  |  |

### Products & Vendors

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `products.view`<br><small>View products</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |
| `products.manage`<br><small>Create / version products, charges & required documents</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `vendors.view`<br><small>View vendors</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  | ✓ | ✓ | ✓ |  |
| `vendors.manage`<br><small>Create / edit vendors</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `vendors.approve`<br><small>Approve vendor onboarding requests</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |

### Loan Origination

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `leads.view`<br><small>View leads</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |
| `leads.manage`<br><small>Create / update / convert leads</small> |  | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `applications.view`<br><small>View financing applications</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `applications.view_all`<br><small>View applications across all branches</small> | ✓ |  |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  | ✓ |
| `applications.create`<br><small>Create / edit applications</small> |  | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `applications.submit`<br><small>Submit applications</small> |  | ✓ | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `applications.verify`<br><small>Verification queue: start review, raise objections</small> |  |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  |  |
| `applications.assess`<br><small>Perform credit assessment</small> |  |  |  |  |  |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `applications.return`<br><small>Return applications for correction</small> |  |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  |  |  |  |
| `applications.reject`<br><small>Reject applications</small> |  |  |  | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  |  |  |  |  |  |
| `applications.reevaluate`<br><small>Initiate re-evaluation / reapplication</small> |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `bureau.request`<br><small>Request Tasdeeq / Data Check reports</small> |  | ✓ | ✓ | ✓ |  |  | ✓ |  | ✓ |  |  |  |  |  |  |  |
| `field_visits.view`<br><small>View all field visits</small> | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  | ✓ | ✓ |  |  | ✓ |  |  |  |
| `field_visits.assign`<br><small>Schedule / assign field visits</small> |  | ✓ | ✓ |  |  |  |  |  | ✓ |  |  |  |  |  |  |  |
| `field_visits.perform`<br><small>Perform assigned visits (Visit Officer portal)</small> |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |  |  |
| `approvals.act`<br><small>Act on approval tasks assigned to my roles</small> |  |  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ |  |  |  | ✓ |  |  |
| `approvals.configure`<br><small>Configure approval hierarchies</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `agreements.manage`<br><small>Generate KFS, agreements and signature requests</small> |  |  |  |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |
| `agreements.wet_ink`<br><small>Confirm receipt of wet-ink documents and send guarantor signing links (branch)</small> |  | ✓ | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |

### Loan Servicing

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `disbursements.view`<br><small>View disbursements</small> | ✓ |  | ✓ |  | ✓ |  |  | ✓ |  |  |  |  | ✓ | ✓ |  |  |
| `disbursements.initiate`<br><small>Request or release disbursements (requester and releaser must differ)</small> |  |  |  |  |  |  |  | ✓ |  |  |  |  |  |  |  |  |
| `loans.view`<br><small>View loan accounts</small> | ✓ | ✓ | ✓ | ✓ | ✓ |  | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |  | ✓ |
| `loans.manage`<br><small>Portfolio transfer & loan maintenance</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `loans.issue_noc`<br><small>Issue NOC on closure</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `repayments.view`<br><small>View repayments</small> | ✓ | ✓ | ✓ |  |  |  |  |  |  |  |  |  | ✓ |  | ✓ |  |
| `repayments.record`<br><small>Record manual repayments</small> |  |  | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `repayments.reverse` 🔒<br><small>Reverse repayments</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `recovery.view`<br><small>View all delinquent accounts</small> | ✓ |  | ✓ |  |  |  | ✓ |  |  |  |  |  | ✓ |  |  |  |
| `recovery.assign`<br><small>Assign recovery agents</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `recovery.work`<br><small>Work assigned recovery cases (Recovery portal)</small> |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |  |  |
| `staff_lending.manage`<br><small>Manage corporate clients, staff & salary data</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |

### Wallet & Payments

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `wallet.view`<br><small>View wallets & transactions</small> | ✓ |  | ✓ |  |  |  |  |  |  |  |  |  | ✓ | ✓ | ✓ | ✓ |
| `wallet.manage`<br><small>Activate / restrict / suspend / close wallets, edit limits</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `wallet.reverse` 🔒<br><small>Reverse / refund wallet transactions</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `payments.view`<br><small>View payment orders & callbacks</small> | ✓ |  |  |  |  |  |  | ✓ |  |  |  |  | ✓ | ✓ | ✓ | ✓ |
| `payments.simulate`<br><small>Trigger simulated provider callbacks (demo mode)</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `complaints.view`<br><small>View complaints & disputes</small> | ✓ | ✓ | ✓ |  |  | ✓ |  |  |  |  |  |  | ✓ |  |  | ✓ |
| `complaints.manage`<br><small>Handle complaints & disputes</small> | ✓ |  | ✓ |  |  | ✓ |  |  |  |  |  |  |  |  |  | ✓ |

### Finance (FMS)

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `fms.view`<br><small>View finance module</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ | ✓ |  |
| `fms.coa.manage`<br><small>Manage chart of accounts & cost centers</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.posting_rules.manage` 🔒<br><small>Manage sub-ledger posting rules</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `fms.journals.create`<br><small>Create / submit journal vouchers</small> |  |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.journals.post`<br><small>Post approved journal vouchers</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.journals.reverse` 🔒<br><small>Request reversal of posted journal vouchers (approved by a second user)</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ |  |
| `fms.periods.manage`<br><small>Close accounting periods</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.periods.reopen` 🔒<br><small>Reopen closed accounting periods</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |
| `fms.payables.manage`<br><small>Vendor bills & payment requests</small> |  |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.payables.execute`<br><small>Execute approved vendor payments</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.treasury.manage`<br><small>Bank accounts & fund transfers</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.reconciliation.perform`<br><small>Run reconciliation & resolve exceptions</small> |  |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.reconciliation.approve`<br><small>Approve reconciliation batches</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.funds.manage`<br><small>Funding sources & profit distribution</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.assets.manage`<br><small>Fixed assets & depreciation</small> |  |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.payroll.manage`<br><small>Payroll runs</small> |  |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.expenses.create`<br><small>Raise expense claims / purchase requests</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.budgets.manage`<br><small>Budgets & forecasts</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |
| `fms.provisioning.manage`<br><small>Provisioning & write-off requests</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.islamic.manage`<br><small>Charity fund & Shariah reporting</small> |  |  |  |  |  |  |  |  |  |  |  |  |  | ✓ |  |  |
| `fms.tax.view`<br><small>Tax reports</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ |  |

### Reports

| Permission | ADM | LO | BM | CRO | MO | CMP | RSK | DA | CA | AC | VO | RA | AUD | FO | ACC | CS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `reports.lms`<br><small>Lending reports</small> | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ |  |  |  |
| `reports.wallet`<br><small>Wallet reports</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ |  |  |
| `reports.fms`<br><small>Financial statements & finance reports</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ | ✓ |  |
| `reports.regulatory`<br><small>Regulatory reports (SECP/SBP, Raast Annexure-A)</small> | ✓ |  |  |  |  | ✓ |  |  |  |  |  |  | ✓ | ✓ |  |  |
| `reports.export` 🔒<br><small>Export reports</small> | ✓ |  |  |  |  |  |  |  |  |  |  |  | ✓ | ✓ |  |  |

## Platform permissions

| Permission | Platform Admin | Platform Support |
|---|---|---|
| `platform.tenants.view`<br><small>View tenants</small> | ✓ | ✓ |
| `platform.tenants.manage`<br><small>Create / configure tenants</small> | ✓ |  |
| `platform.tenants.suspend`<br><small>Activate / suspend / deactivate tenants</small> | ✓ |  |
| `platform.settings.manage`<br><small>Manage platform settings</small> | ✓ |  |
| `platform.health.view`<br><small>View integration & queue health</small> | ✓ | ✓ |
| `platform.audit.view`<br><small>View platform audit events</small> | ✓ |  |
| `platform.demo.reset`<br><small>Reset demo environment</small> | ✓ |  |
| `platform.support_access.request`<br><small>Request time-limited tenant support access</small> | ✓ | ✓ |
| `platform.support_access.grant`<br><small>Grant / revoke tenant support access</small> | ✓ |  |
