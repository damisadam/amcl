<?php

/*
| Role templates copied into every new tenant at provisioning time. After provisioning each
| tenant owns independent role records and may edit them. "*" wildcards match permission keys.
| Platform roles are created once (tenant_id = null).
*/

$readAll = ['dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'products.view', 'vendors.view', 'leads.view',
    'applications.view', 'applications.view_all', 'field_visits.view', 'disbursements.view', 'loans.view', 'repayments.view', 'recovery.view',
    'wallet.view', 'payments.view', 'complaints.view', 'fms.view', 'reports.lms', 'reports.wallet', 'reports.fms', 'reports.regulatory'];

return [
    'platform' => [
        'platform_admin' => ['name' => 'Platform Admin', 'description' => 'Operates the platform; no default access to tenant customer or financial data.', 'permissions' => ['platform.*']],
        'platform_support' => ['name' => 'Platform Support', 'description' => 'Monitors health; may request time-limited tenant support access.', 'permissions' => ['platform.tenants.view', 'platform.health.view', 'platform.support_access.request']],
    ],

    'tenant' => [
        // Tenant Admin administers the institution but holds NO operational decision rights by default (segregation of duties):
        // no approvals.act, kyc/aml review, payout release, journal posting/reversal, payment release or reversals.
        'tenant_admin' => ['name' => 'Tenant Admin', 'description' => 'Configuration, users, roles, approval hierarchies and read access; operational decisions require an explicit operational role.', 'permissions' => array_merge($readAll, [
            'users.*', 'roles.*', 'org.manage', 'config.*', 'content.manage', 'integrations.*', 'audit.*', 'approvals.configure',
            'customers.create', 'customers.update', 'customers.archive', 'customers.export', 'customers.view_sensitive', 'aml.view', 'documents.upload',
            'products.*', 'vendors.manage', 'vendors.approve', 'loans.manage', 'loans.issue_noc', 'recovery.assign', 'staff_lending.manage',
            'wallet.manage', 'payments.simulate', 'complaints.manage', 'fms.coa.manage', 'fms.posting_rules.manage', 'fms.periods.manage', 'fms.periods.reopen',
            'fms.treasury.manage', 'fms.funds.manage', 'fms.budgets.manage', 'fms.tax.view', 'fms.expenses.create', 'reports.*',
        ])],
        'loan_officer' => ['name' => 'Loan Officer', 'description' => 'Originates leads and applications, collects documents, raises visits.', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.create', 'customers.update', 'kyc.view', 'documents.view', 'documents.upload',
            'products.view', 'vendors.view', 'leads.*', 'applications.view', 'applications.create', 'applications.submit',
            'field_visits.assign', 'field_visits.view', 'bureau.request', 'loans.view', 'repayments.view', 'complaints.view', 'reports.lms', 'agreements.wet_ink']],
        'branch_manager' => ['name' => 'Branch Manager', 'description' => 'Oversees branch portfolio, verification queue and assignments.', 'permissions' => [
            'dashboard.view', 'customers.*', 'kyc.view', 'documents.*', 'products.view', 'vendors.view', 'leads.*',
            'applications.view', 'applications.create', 'applications.submit', 'applications.verify', 'applications.return', 'applications.reevaluate',
            'field_visits.view', 'field_visits.assign', 'bureau.request', 'approvals.act', 'agreements.wet_ink', 'disbursements.view', 'loans.view', 'loans.manage',
            'repayments.view', 'repayments.record', 'recovery.view', 'recovery.assign', 'complaints.*', 'wallet.view', 'reports.lms', 'users.view']],
        'cro' => ['name' => 'CRO (Credit Review Officer)', 'description' => 'Approval stage 1 – credit review of the case file.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'applications.view', 'applications.view_all', 'applications.verify', 'applications.return',
            'applications.reject', 'bureau.request', 'field_visits.view', 'approvals.act', 'loans.view', 'reports.lms']],
        'manager_operations' => ['name' => 'Manager Operations', 'description' => 'Approval stage 2 – operational checks.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'applications.view', 'applications.view_all', 'applications.return', 'applications.reject',
            'field_visits.view', 'approvals.act', 'disbursements.view', 'loans.view', 'reports.lms']],
        'compliance_officer' => ['name' => 'Compliance Officer', 'description' => 'Approval stage 3 – AML/CFT, KYC and conduct compliance.', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.view_sensitive', 'kyc.*', 'aml.*', 'documents.*', 'applications.view', 'applications.view_all',
            'applications.return', 'applications.reject', 'approvals.act', 'config.blacklist', 'complaints.*', 'audit.view', 'reports.lms', 'reports.regulatory']],
        'risk_officer' => ['name' => 'Risk Officer', 'description' => 'Approval stage 4 – risk rating and DBR policy.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'applications.view', 'applications.view_all', 'applications.assess',
            'applications.return', 'applications.reject', 'bureau.request', 'field_visits.view', 'approvals.act', 'loans.view', 'recovery.view', 'reports.lms']],
        'disbursement_approver' => ['name' => 'Disbursement Approver', 'description' => 'Approval stage 5 – final sanction and disbursement release.', 'permissions' => [
            'dashboard.view', 'customers.view', 'documents.view', 'applications.view', 'applications.view_all', 'applications.return', 'applications.reject',
            'approvals.act', 'agreements.manage', 'agreements.wet_ink', 'disbursements.*', 'loans.view', 'payments.view', 'reports.lms']],
        'credit_analyst' => ['name' => 'Credit Analyst', 'description' => 'Income analysis, DBR, bureau checks and scoring.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'documents.verify', 'applications.view', 'applications.view_all', 'applications.verify',
            'applications.assess', 'applications.return', 'bureau.request', 'field_visits.view', 'field_visits.assign', 'loans.view', 'reports.lms']],
        'approval_committee' => ['name' => 'Approval Committee', 'description' => 'Committee approvals for large amounts.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'documents.view', 'applications.view', 'applications.view_all', 'applications.return',
            'applications.reject', 'field_visits.view', 'approvals.act', 'loans.view', 'reports.lms']],
        'visit_officer' => ['name' => 'Visit Officer', 'description' => 'Field verification visits (Visit Officer portal).', 'permissions' => ['field_visits.perform', 'documents.upload']],
        'recovery_agent' => ['name' => 'Recovery Agent', 'description' => 'Works assigned delinquent cases (Recovery portal).', 'permissions' => ['recovery.work']],
        'audit_officer' => ['name' => 'Audit Officer', 'description' => 'Read-only access to all modules, audit trail and AML flags.', 'permissions' => array_merge($readAll, [
            'audit.view', 'audit.export', 'aml.view', 'customers.view_sensitive', 'users.view', 'roles.view', 'integrations.view', 'reports.export'])],
        'finance_officer' => ['name' => 'Finance Officer', 'description' => 'Approves and posts finance transactions, treasury and reconciliation.', 'permissions' => [
            'dashboard.view', 'fms.view', 'fms.journals.post', 'fms.journals.reverse', 'fms.periods.manage', 'fms.payables.execute', 'fms.treasury.manage',
            'fms.reconciliation.approve', 'fms.funds.manage', 'fms.islamic.manage', 'fms.provisioning.manage', 'fms.tax.view', 'approvals.act', 'repayments.reverse', 'wallet.reverse',
            'vendors.view', 'payments.view', 'wallet.view', 'disbursements.view', 'loans.view', 'reports.fms', 'reports.wallet', 'reports.regulatory', 'reports.export']],
        'accountant' => ['name' => 'Accountant', 'description' => 'Prepares vouchers, bills, payment requests and reconciliations.', 'permissions' => [
            'dashboard.view', 'fms.view', 'fms.coa.manage', 'fms.journals.create', 'fms.journals.reverse', 'fms.payables.manage', 'fms.reconciliation.perform', 'fms.assets.manage',
            'fms.payroll.manage', 'fms.expenses.create', 'fms.budgets.manage', 'fms.tax.view', 'vendors.view', 'payments.view', 'wallet.view',
            'repayments.view', 'repayments.record', 'reports.fms']],
        'customer_support' => ['name' => 'Customer Support', 'description' => 'Grievance redressal and wallet support.', 'permissions' => [
            'dashboard.view', 'customers.view', 'kyc.view', 'applications.view', 'applications.view_all', 'loans.view', 'wallet.view', 'payments.view', 'complaints.*']],
    ],
];
