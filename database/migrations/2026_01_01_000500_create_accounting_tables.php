<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Management System: per-tenant double-entry books, sub-ledger posting rules,
 * treasury, reconciliation, AP/AR, provisioning, fixed assets, payroll, expenses,
 * budgeting, Islamic/charity accounting and tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('type', 20);                         // asset|liability|equity|income|expense
            $t->string('level', 20)->default('account');    // head|sub_head|account
            $t->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->boolean('is_postable')->default(true);
            $t->boolean('is_active')->default(true);
            $t->string('system_role', 50)->nullable();      // AccountRole used by posting rules
            $t->string('cash_flow_category', 20)->nullable(); // operating|investing|financing (for cash-flow statement)
            $t->string('description')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'system_role']);
        });

        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('business_line', 20)->nullable();    // lending|wallet|corporate
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        // Tenant-configurable mapping of business event → journal lines (no hardcoded account codes).
        Schema::create('posting_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('event_type', 50);                   // AccountingEvent
            $t->unsignedSmallInteger('line_no');
            $t->string('side', 6);                          // debit|credit
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->string('amount_key', 40);                   // key into the event's amount map
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'event_type', 'line_no']);
        });

        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 20);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('open');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $t->string('name', 20);                         // e.g. 2026-07
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('open');      // open|closed
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reopened_at')->nullable();
            $t->string('reopen_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'start_date']);
        });

        Schema::create('journal_vouchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('voucher_no', 30);
            $t->date('voucher_date');
            $t->foreignId('accounting_period_id')->nullable()->constrained()->nullOnDelete();
            $t->string('origin', 10);                       // manual|system
            $t->string('source_module', 20)->nullable();    // lms|dfs|wallet|fms|ap|treasury
            $t->string('event_type', 50)->nullable();
            $t->nullableMorphs('source');                   // originating transaction
            $t->string('narration', 1000);
            $t->string('status', 20)->default('draft');     // JournalStatus
            $t->decimal('total_debit', 18, 2)->default(0);
            $t->decimal('total_credit', 18, 2)->default(0);
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reversal_of_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $t->string('idempotency_key', 120)->nullable(); // system postings: event + source id
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'voucher_no']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'voucher_date']);
        });

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('journal_voucher_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->decimal('debit', 18, 2)->default(0);
            $t->decimal('credit', 18, 2)->default(0);
            $t->string('description')->nullable();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'account_id']);
        });

        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('bank_name');
            $t->string('iban', 34);
            $t->string('purpose', 20);                      // operating|safeguarded|disbursement|collection|payroll
            $t->string('provider', 40)->nullable();         // settlement provider for reconciliation
            $t->foreignId('account_id')->constrained()->cascadeOnDelete(); // GL account
            $t->decimal('minimum_balance', 18, 2)->default(0);
            $t->boolean('is_simulated')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('disbursements', fn (Blueprint $t) => $t->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete());
        Schema::table('payment_orders', fn (Blueprint $t) => $t->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete());

        // Lines from the (simulated) bank/provider settlement statement.
        Schema::create('settlement_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 40);
            $t->date('value_date');
            $t->string('provider_ref', 60)->nullable();
            $t->string('narration')->nullable();
            $t->string('direction', 10);                    // credit|debit (from bank's view)
            $t->decimal('amount', 18, 2);
            $t->string('status', 20)->default('unmatched'); // unmatched|matched|suspense|ignored
            $t->boolean('is_simulated')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'bank_account_id', 'value_date']);
            $t->index(['provider_ref']);
        });

        Schema::create('reconciliation_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('status', 20)->default('draft');     // draft|in_review|pending_approval|approved
            $t->unsignedInteger('matched_count')->default(0);
            $t->unsignedInteger('exception_count')->default(0);
            $t->decimal('internal_total', 18, 2)->default(0);
            $t->decimal('external_total', 18, 2)->default(0);
            $t->decimal('difference', 18, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });

        Schema::create('reconciliation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reconciliation_batch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('settlement_record_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('internal_amount', 18, 2)->nullable();
            $t->decimal('external_amount', 18, 2)->nullable();
            $t->string('result', 30);                       // matched|amount_mismatch|missing_external|missing_internal|duplicate_reference|manual_match
            $t->string('resolution', 30)->nullable();       // manual_match|suspense|written_back|ignored
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
        });

        Schema::create('suspense_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->foreignId('settlement_record_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('reason', 500);
            $t->string('status', 20)->default('open');      // open|reclassified
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reclass_journal_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('resolution_notes', 1000)->nullable();
            $t->timestamps();
        });

        Schema::create('vendor_bills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('bill_no', 30);
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                         // disbursement|operational
            $t->foreignId('loan_application_id')->nullable()->constrained()->nullOnDelete();
            $t->string('vendor_invoice_ref')->nullable();
            $t->date('bill_date');
            $t->date('due_date');
            $t->decimal('amount', 18, 2);
            $t->decimal('wht_amount', 18, 2)->default(0);
            $t->decimal('paid_amount', 18, 2)->default(0);
            $t->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description', 1000)->nullable();
            $t->string('status', 20)->default('open');      // open|partially_paid|paid|cancelled
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'bill_no']);
        });

        Schema::create('vendor_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('payment_no', 30);
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_bill_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('disbursement_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_advance')->default(false);
            $t->decimal('amount', 18, 2);
            $t->decimal('wht_amount', 18, 2)->default(0);
            $t->decimal('net_amount', 18, 2);
            $t->string('method', 20);                       // raast|bank_transfer|cheque
            $t->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('draft');     // VendorPaymentStatus
            $t->string('scenario', 40)->nullable();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('idempotency_key', 100);
            $t->string('purpose', 500)->nullable();
            $t->string('failure_reason', 500)->nullable();
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'payment_no']);
            $t->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('other_receivables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->string('party_name');
            $t->string('kind', 30);                         // staff_advance|insurance_claim|partner_due|other
            $t->decimal('amount', 18, 2);
            $t->decimal('received_amount', 18, 2)->default(0);
            $t->date('due_date');
            $t->string('status', 20)->default('open');
            $t->string('notes', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('loan_provisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $t->date('as_of');
            $t->unsignedSmallInteger('dpd');
            $t->string('classification', 20);
            $t->decimal('rate', 5, 2);
            $t->decimal('outstanding', 18, 2);
            $t->decimal('required_provision', 18, 2);
            $t->decimal('movement', 18, 2);
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['loan_account_id', 'as_of']);
        });

        Schema::create('write_offs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('reason', 1000);
            $t->string('status', 20)->default('pending_approval'); // pending_approval|approved|rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('fund_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->foreignId('from_bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $t->foreignId('to_bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('purpose', 500);
            $t->string('status', 20)->default('pending_approval'); // pending_approval|completed|rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('asset_code', 30);
            $t->string('name');
            $t->string('category', 30);                     // furniture|it_equipment|vehicle|building
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->date('acquired_on');
            $t->decimal('cost', 18, 2);
            $t->decimal('salvage_value', 18, 2)->default(0);
            $t->unsignedSmallInteger('useful_life_months');
            $t->decimal('accumulated_depreciation', 18, 2)->default(0);
            $t->string('status', 20)->default('active');    // active|disposed
            $t->date('disposed_on')->nullable();
            $t->decimal('disposal_proceeds', 18, 2)->nullable();
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'asset_code']);
        });

        Schema::create('asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['fixed_asset_id', 'accounting_period_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('period', 7);
            $t->string('status', 20)->default('draft');     // draft|pending_approval|posted
            $t->decimal('total_gross', 18, 2)->default(0);
            $t->decimal('total_tax', 18, 2)->default(0);
            $t->decimal('total_deductions', 18, 2)->default(0);
            $t->decimal('total_net', 18, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'period']);
        });

        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->decimal('basic', 18, 2);
            $t->decimal('allowances', 18, 2)->default(0);
            $t->decimal('gross', 18, 2);
            $t->decimal('income_tax', 18, 2)->default(0);
            $t->decimal('provident_fund', 18, 2)->default(0);
            $t->decimal('eobi', 18, 2)->default(0);
            $t->decimal('staff_loan_deduction', 18, 2)->default(0);
            $t->decimal('net', 18, 2);
            $t->timestamps();
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->string('kind', 20);                         // expense|reimbursement|purchase_request|petty_cash
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('expense_account_id')->constrained('accounts')->cascadeOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->date('expense_date');
            $t->decimal('amount', 18, 2);
            $t->string('description', 1000);
            $t->string('status', 20)->default('draft');     // draft|pending_approval|approved|rejected|paid
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });

        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('status', 20)->default('draft');     // draft|approved
            $t->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->timestamps();
        });

        Schema::create('profit_distributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fund_id')->constrained()->cascadeOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->cascadeOnDelete();
            $t->decimal('gross_profit', 18, 2);
            $t->decimal('investor_share_pct', 5, 2);
            $t->decimal('investor_share', 18, 2);
            $t->decimal('wht_amount', 18, 2)->default(0);
            $t->string('status', 20)->default('calculated'); // calculated|posted
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('charity_disbursements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->string('beneficiary');
            $t->decimal('amount', 18, 2);
            $t->string('purpose', 500);
            $t->string('status', 20)->default('pending_approval');
            $t->foreignId('journal_voucher_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('payment_orders', fn (Blueprint $t) => $t->dropForeign(['bank_account_id']));
        Schema::table('disbursements', fn (Blueprint $t) => $t->dropForeign(['bank_account_id']));
        foreach (['charity_disbursements', 'profit_distributions', 'budget_lines', 'budgets', 'expense_claims', 'payroll_lines', 'payroll_runs',
            'asset_depreciations', 'fixed_assets', 'fund_transfers', 'write_offs', 'loan_provisions', 'other_receivables', 'vendor_payments',
            'vendor_bills', 'suspense_items', 'reconciliation_items', 'reconciliation_batches', 'settlement_records', 'bank_accounts',
            'journal_lines', 'journal_vouchers', 'accounting_periods', 'fiscal_years', 'posting_rules', 'cost_centers', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
