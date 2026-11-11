<?php

/*
| Navigation per portal. Each item: [label, route name, permission(s) (any-of; null = any user of portal), icon, feature].
| Items are hidden when the user lacks permission or the route is not registered. Server-side
| authorization is ALWAYS enforced again in each screen (hiding is only for usability).
*/

return [
    'ops' => [
        'Overview' => [
            ['Dashboard', 'ops.dashboard', ['dashboard.view'], 'home', null],
        ],
        'Customers' => [
            ['Customers', 'ops.customers.index', ['customers.view'], 'users', null],
            ['KYC & AML review', 'ops.kyc.index', ['kyc.review', 'aml.review', 'kyc.view'], 'shield', null],
            ['Leads', 'ops.leads.index', ['leads.view'], 'phone', 'lms'],
            ['Complaints & disputes', 'ops.complaints.index', ['complaints.view'], 'chat', null],
        ],
        'Lending' => [
            ['Applications', 'ops.applications.index', ['applications.view'], 'clipboard', 'lms'],
            ['Approval tasks', 'ops.approvals.index', ['approvals.act'], 'check', null],
            ['Field visits', 'ops.visits.index', ['field_visits.view', 'field_visits.perform', 'field_visits.assign'], 'map', 'lms'],
            ['Disbursements', 'ops.disbursements.index', ['disbursements.view'], 'cash', 'lms'],
            ['Loan accounts', 'ops.loans.index', ['loans.view'], 'document', 'lms'],
            ['Collections', 'ops.collections.index', ['repayments.view'], 'calendar', 'lms'],
            ['Recovery', 'ops.recovery.index', ['recovery.view', 'recovery.work'], 'phone', 'lms'],
            ['Products', 'ops.products.index', ['products.view'], 'cube', 'lms'],
            ['Vendors', 'ops.vendors.index', ['vendors.view'], 'truck', null],
            ['Staff lending', 'ops.staff-lending.index', ['staff_lending.manage'], 'building', 'staff_lending'],
        ],
        'Wallet & Payments' => [
            ['Wallets', 'ops.wallets.index', ['wallet.view'], 'wallet', 'wallet'],
            ['Wallet transactions', 'ops.wallet-transactions.index', ['wallet.view'], 'arrows', 'wallet'],
            ['Payment orders', 'ops.payments.index', ['payments.view'], 'bolt', null],
        ],
        'Finance' => [
            ['Finance overview', 'ops.finance.dashboard', ['fms.view'], 'bank', 'fms'],
            ['Journal vouchers', 'ops.finance.journals.index', ['fms.view'], 'document', 'fms'],
            ['Chart of accounts', 'ops.finance.accounts', ['fms.view'], 'clipboard', 'fms'],
            ['Posting rules', 'ops.finance.posting-rules', ['fms.posting_rules.manage', 'fms.view'], 'cog', 'fms'],
            ['Accounting periods', 'ops.finance.periods', ['fms.view'], 'calendar', 'fms'],
            ['Treasury & banks', 'ops.finance.treasury', ['fms.view'], 'bank', 'fms'],
            ['Reconciliation', 'ops.finance.reconciliation.index', ['fms.reconciliation.perform', 'fms.reconciliation.approve', 'fms.view'], 'scale', 'fms'],
            ['Payables', 'ops.finance.payables', ['fms.payables.manage', 'fms.payables.execute', 'fms.view'], 'truck', 'fms'],
            ['Receivables', 'ops.finance.receivables', ['fms.view'], 'cash', 'fms'],
            ['Funds & investors', 'ops.finance.funds', ['fms.view'], 'building', 'fms'],
            ['Provisioning & write-off', 'ops.finance.provisioning', ['fms.provisioning.manage', 'fms.view'], 'shield', 'fms'],
            ['Fixed assets', 'ops.finance.assets', ['fms.assets.manage', 'fms.view'], 'cube', 'fms'],
            ['Payroll', 'ops.finance.payroll', ['fms.payroll.manage', 'fms.view'], 'users', 'fms'],
            ['Expenses', 'ops.finance.expenses', ['fms.expenses.create', 'fms.view'], 'cash', 'fms'],
            ['Budgets', 'ops.finance.budgets', ['fms.budgets.manage', 'fms.view'], 'chart', 'fms'],
            ['Islamic & charity', 'ops.finance.islamic', ['fms.islamic.manage', 'fms.view'], 'globe', 'fms'],
            ['Tax', 'ops.finance.tax', ['fms.tax.view'], 'document', 'fms'],
        ],
        'Reports' => [
            ['Lending reports', 'ops.reports.lending', ['reports.lms'], 'chart', null],
            ['Wallet reports', 'ops.reports.wallet', ['reports.wallet'], 'chart', 'wallet'],
            ['Financial statements', 'ops.reports.financial', ['reports.fms'], 'chart', 'fms'],
            ['Regulatory reports', 'ops.reports.regulatory', ['reports.regulatory'], 'chart', null],
        ],
        'Administration' => [
            ['Users', 'ops.admin.users', ['users.view'], 'users', null],
            ['Roles & permissions', 'ops.admin.roles', ['roles.view'], 'shield', null],
            ['Organization', 'ops.admin.org', ['org.manage'], 'building', null],
            ['Company configuration', 'ops.admin.config', ['config.manage', 'config.blacklist'], 'cog', null],
            ['Approval workflows', 'ops.admin.workflows', ['approvals.configure'], 'check', null],
            ['Integration settings', 'ops.admin.integrations', ['integrations.view'], 'plug', null],
            ['Content & templates', 'ops.admin.content', ['content.manage'], 'chat', null],
            ['Audit log', 'ops.admin.audit', ['audit.view'], 'clipboard', null],
        ],
    ],

    'customer' => [
        ['Home', 'app.home', 'home', null],
        ['Apply for Financing', 'app.products', 'plus', 'dfs'],
        ['My Applications', 'app.applications', 'clipboard', 'dfs'],
        ['My Financing', 'app.financing', 'document', 'dfs'],
        ['My Wallet', 'app.wallet', 'wallet', 'wallet'],
        ['Pay Instalment', 'app.pay', 'cash', 'dfs'],
        ['Transaction History', 'app.transactions', 'arrows', null],
        ['Documents', 'app.documents', 'document', null],
        ['Notifications', 'app.notifications', 'bell', null],
        ['Complaints & Support', 'app.support', 'chat', null],
        ['Profile & Settings', 'app.profile', 'cog', null],
    ],

    'platform' => [
        ['Overview', 'platform.dashboard', ['platform.tenants.view'], 'home'],
        ['Tenants', 'platform.tenants.index', ['platform.tenants.view'], 'building'],
        ['Provision tenant', 'platform.tenants.create', ['platform.tenants.manage'], 'plus'],
        ['Integration health', 'platform.health', ['platform.health.view'], 'plug'],
        ['Queues & jobs', 'platform.queues', ['platform.health.view'], 'bolt'],
        ['Audit events', 'platform.audit', ['platform.audit.view'], 'clipboard'],
        ['Support access', 'platform.support-access', ['platform.support_access.request', 'platform.support_access.grant'], 'shield'],
        ['Platform settings', 'platform.settings', ['platform.settings.manage'], 'cog'],
        ['Demo tools', 'platform.demo', ['platform.demo.reset'], 'arrows'],
    ],

    'vendor' => [
        ['Dashboard', 'vendor.dashboard', 'home'],
        ['Financing orders', 'vendor.orders', 'clipboard'],
        ['New customer request', 'vendor.originate', 'plus'],
        ['Payments received', 'vendor.payments', 'cash'],
        ['Products & items', 'vendor.items', 'cube'],
        ['Merchant wallet', 'vendor.merchant', 'qr'],
        ['Documents', 'vendor.documents', 'document'],
        ['Profile', 'vendor.profile', 'user'],
    ],
];
