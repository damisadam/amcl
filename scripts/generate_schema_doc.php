<?php
// Writes docs/DATABASE_SCHEMA.md from the live MySQL schema. Usage: php artisan tinker --execute "require base_path('scripts/generate_schema_doc.php');"
use Illuminate\Support\Facades\DB;
$db = DB::getDatabaseName();
$tables = collect(DB::select('SELECT TABLE_NAME t, TABLE_COMMENT c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$db]));
$skip = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];
$groups = [
    'Platform & identity' => ['tenants', 'tenant_settings', 'users', 'roles', 'permissions', 'permission_role', 'role_user', 'user_devices', 'otp_codes', 'support_access_grants', 'platform_settings', 'audit_logs', 'number_sequences'],
    'Organisation & configuration' => ['regions', 'areas', 'branches', 'departments', 'employees', 'holidays', 'payment_types', 'loan_categories', 'blacklist_entries', 'document_types', 'documents', 'content_items', 'notification_templates', 'notification_logs', 'notifications', 'integration_settings', 'integration_logs'],
    'Customers, vendors & products' => ['customers', 'next_of_kins', 'customer_notes', 'customer_change_requests', 'consents', 'kyc_verifications', 'corporate_clients', 'corporate_staff', 'staff_salaries', 'vendors', 'vendor_items', 'products', 'product_versions', 'charges', 'product_required_documents', 'branch_product'],
    'Lending' => ['funds', 'branch_fund', 'approval_workflows', 'approval_stages', 'approval_requests', 'approval_actions', 'leads', 'loan_applications', 'application_status_histories', 'guarantors', 'bureau_checks', 'credit_assessments', 'field_visits', 'objections', 'key_fact_statements', 'signature_requests', 'loan_accounts', 'instalments', 'disbursements', 'loan_payments', 'payment_allocations', 'recovery_contacts', 'portfolio_transfers'],
    'Wallet & payments' => ['payment_orders', 'payment_callbacks', 'wallets', 'wallet_transactions', 'wallet_ledger_entries', 'linked_bank_accounts', 'bill_payments', 'complaints', 'complaint_messages'],
    'Finance (FMS)' => ['accounts', 'cost_centers', 'posting_rules', 'fiscal_years', 'accounting_periods', 'journal_vouchers', 'journal_lines', 'bank_accounts', 'settlement_records', 'reconciliation_batches', 'reconciliation_items', 'suspense_items', 'vendor_bills', 'vendor_payments', 'other_receivables', 'loan_provisions', 'write_offs', 'fund_transfers', 'fixed_assets', 'asset_depreciations', 'payroll_runs', 'payroll_lines', 'expense_claims', 'budgets', 'budget_lines', 'profit_distributions', 'charity_disbursements'],
];
$md = "# Database schema\n\n_Generated from the live MySQL schema by `scripts/generate_schema_doc.php`._ All money columns are `decimal(18,2)`. "
    ."Every tenant-owned table has `tenant_id` (FK `tenants`, indexed) and is scoped by `BelongsToTenant`. Unique keys enforce idempotency "
    ."(payment orders, wallet transactions, loan payments, disbursements, journal vouchers, callback inbox, wallet ledger postings).\n\n";
$all = $tables->pluck('t')->diff($skip)->values();
$listed = collect($groups)->flatten();
$groups['Other'] = $all->diff($listed)->values()->all();
foreach ($groups as $group => $names) {
    $names = array_values(array_intersect($names, $all->all()));
    if (! $names) continue;
    $md .= "## $group\n\n";
    foreach ($names as $t) {
        $cols = DB::select('SELECT COLUMN_NAME n, COLUMN_TYPE ty, IS_NULLABLE nu FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$db, $t]);
        $fks = collect(DB::select('SELECT COLUMN_NAME c, REFERENCED_TABLE_NAME r FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', [$db, $t]))->pluck('r', 'c');
        $uniq = collect(DB::select("SELECT INDEX_NAME i, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY INDEX_NAME", [$db, $t]))->pluck('c')->all();
        $md .= "### `$t`\n\n".implode(', ', array_map(fn ($c) => '`'.$c->n.'`'.($fks[$c->n] ?? null ? ' → '.$fks[$c->n] : '').' _'.$c->ty.($c->nu === 'YES' ? '?' : '').'_', $cols))."\n";
        if ($uniq) $md .= "\nUnique: ".implode('; ', array_map(fn ($u) => "($u)", $uniq))."\n";
        $md .= "\n";
    }
}
file_put_contents(base_path('docs/DATABASE_SCHEMA.md'), $md);
echo 'tables: '.$all->count()."\n";
