<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic approval engine, funds, and the LMS/DFS lending lifecycle:
 * leads → applications → assessment → approval → KFS → e-sign → cooling-off
 * → disbursement → loan account → instalments → repayment/recovery → closure/NOC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('type', 30);                         // own_equity|investor|bank_line|donor
            $t->string('provider_name')->nullable();
            $t->decimal('committed_amount', 18, 2);
            $t->string('structure', 30)->nullable();        // mudarabah|musharakah|wakalah|equity
            $t->decimal('investor_profit_share_pct', 5, 2)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('terms', 1000)->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('branch_fund', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('fund_id')->constrained()->cascadeOnDelete();
            $t->primary(['branch_id', 'fund_id']);
        });

        // Configurable approval hierarchies for loans AND finance (JV, vendor payment, expense, fund transfer, write-off).
        Schema::create('approval_workflows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);                         // ApprovalType
            $t->string('name');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('min_amount', 18, 2)->nullable();
            $t->decimal('max_amount', 18, 2)->nullable();
            $t->unsignedSmallInteger('priority')->default(100); // lower = evaluated first
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('approval_stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->string('name');
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->decimal('min_amount', 18, 2)->nullable();   // stage only applies at/above this amount
            $t->boolean('comment_required_on_approve')->default(false);
            $t->boolean('comment_required_on_reject')->default(true);
            $t->boolean('allow_return')->default(true);
            $t->unsignedSmallInteger('sla_hours')->nullable();
            $t->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->morphs('approvable');
            $t->foreignId('approval_workflow_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);
            $t->decimal('amount', 18, 2)->default(0);
            $t->json('stage_plan');                         // snapshot of applicable stages at submission
            $t->unsignedSmallInteger('current_sequence')->nullable();
            $t->foreignId('current_stage_id')->nullable()->constrained('approval_stages')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // delegation
            $t->string('status', 20)->default('pending');   // pending|approved|rejected|returned|cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('stage_entered_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'current_stage_id']);
        });

        Schema::create('approval_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('approval_stage_id')->nullable()->constrained()->nullOnDelete();
            $t->string('stage_name')->nullable();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->string('action', 20);                       // approve|reject|return|delegate|submit|resubmit
            $t->string('comment', 2000)->nullable();
            $t->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('lead_no', 30);
            $t->string('name');
            $t->string('mobile', 20);
            $t->string('cnic', 15)->nullable();
            $t->string('channel', 20);                      // branch|call_center|website|app|vendor|dfs
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('requested_amount', 18, 2)->nullable();
            $t->string('status', 20)->default('new');       // new|contacted|qualified|converted|dropped
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('converted_application_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'lead_no']);
        });

        Schema::create('loan_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('application_no', 30);
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_version_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_item_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 20);                      // app|web|branch|vendor|call_center
            $t->string('status', 30)->default('draft');     // ApplicationStatus
            $t->decimal('asset_price', 18, 2)->nullable();
            $t->decimal('down_payment', 18, 2)->default(0);
            $t->decimal('requested_amount', 18, 2);
            $t->decimal('approved_amount', 18, 2)->nullable();
            $t->unsignedSmallInteger('tenure');
            $t->string('frequency', 10)->default('monthly');
            $t->string('purpose', 500)->nullable();
            $t->string('asset_description', 500)->nullable();
            $t->decimal('indicative_instalment', 18, 2)->nullable();
            $t->decimal('dbr', 6, 2)->nullable();
            $t->string('credit_grade', 3)->nullable();
            $t->string('risk_rating', 10)->nullable();
            $t->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('previous_application_id')->nullable()->constrained('loan_applications')->nullOnDelete();
            $t->boolean('is_reevaluation')->default(false);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->string('rejection_code', 40)->nullable();
            $t->string('rejection_notes', 1000)->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamp('kfs_acknowledged_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('cooling_off_ends_at')->nullable();
            $t->boolean('cooling_off_waived')->default(false);
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason', 500)->nullable();
            $t->timestamp('disbursed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'application_no']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'customer_id']);
        });

        Schema::create('application_status_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('comment', 1000)->nullable();
            $t->boolean('visible_to_customer')->default(true);
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('guarantors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('cnic', 15);
            $t->string('mobile', 20);
            $t->string('relation', 30)->nullable();
            $t->string('address')->nullable();
            $t->string('occupation')->nullable();
            $t->decimal('monthly_income', 18, 2)->default(0);
            $t->string('verification_status', 20)->default('pending'); // pending|verified|failed
            $t->timestamp('consent_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('bureau_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->string('subject_type', 20);                 // applicant|guarantor
            $t->unsignedBigInteger('subject_id');
            $t->string('subject_cnic', 15);
            $t->string('provider', 40);                     // tasdeeq|datacheck
            $t->string('status', 20);                       // clear|adverse|no_hit|error
            $t->unsignedSmallInteger('score')->nullable();
            $t->unsignedTinyInteger('active_facilities')->nullable();
            $t->decimal('total_outstanding', 18, 2)->nullable();
            $t->decimal('monthly_obligations', 18, 2)->nullable();
            $t->unsignedSmallInteger('max_dpd_12m')->nullable();
            $t->json('report')->nullable();
            $t->foreignId('consent_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('integration_log_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('credit_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->decimal('monthly_income', 18, 2);
            $t->decimal('other_income', 18, 2)->default(0);
            $t->decimal('household_expenses', 18, 2)->default(0);
            $t->decimal('existing_obligations', 18, 2)->default(0);
            $t->decimal('proposed_instalment', 18, 2);
            $t->decimal('dbr', 6, 2);
            $t->decimal('dbr_limit', 6, 2);
            $t->boolean('dbr_pass');
            $t->json('business_cashflow')->nullable();      // inflows/outflows for SME
            $t->unsignedSmallInteger('bureau_score')->nullable();
            $t->unsignedSmallInteger('internal_score');
            $t->string('grade', 3);                         // A, B+, B, C, D
            $t->string('visit_result', 20)->nullable();
            $t->string('aml_result', 20)->nullable();
            $t->string('guarantor_result', 20)->nullable();
            $t->string('risk_rating', 10);                  // low|medium|high
            $t->json('score_breakdown')->nullable();
            $t->string('recommendation', 20);               // approve|decline|refer
            $t->string('notes', 2000)->nullable();
            $t->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('field_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('visit_type', 20);                   // residence|business|collateral
            $t->string('address');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('scheduled_for');
            $t->string('status', 20)->default('scheduled'); // scheduled|in_progress|completed|cancelled
            $t->timestamp('checked_in_at')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('address_match')->nullable();
            $t->string('living_conditions', 30)->nullable();
            $t->string('business_conditions', 30)->nullable();
            $t->string('neighbor_verification', 500)->nullable();
            $t->json('checklist')->nullable();
            $t->string('recommendation', 20)->nullable();   // positive|negative|follow_up
            $t->string('remarks', 2000)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'assigned_to', 'status']);
        });

        Schema::create('objections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('stage')->nullable();
            $t->string('category', 30)->default('documents');
            $t->string('body', 2000);
            $t->boolean('visible_to_customer')->default(false);
            $t->string('status', 20)->default('open');     // open|responded|resolved
            $t->string('response', 2000)->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('seen_at')->nullable();           // pop-up acknowledged at next login
            $t->timestamps();
        });

        Schema::create('key_fact_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('version')->default(1);
            $t->json('terms');                              // financed amount, rate, APR, tenor, fees, total payable...
            $t->timestamp('generated_at');
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('acknowledged_locale', 5)->nullable();
            $t->string('acknowledged_ip', 45)->nullable();
            $t->string('acknowledged_user_agent', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('signature_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->string('signer_type', 20);                  // applicant|guarantor
            $t->unsignedBigInteger('signer_id');
            $t->string('signer_name');
            $t->string('signing_method', 20);               // digital|wet_ink
            $t->string('status', 20)->default('pending');   // pending|sent|signed|declined|wet_ink_pending|wet_ink_received
            $t->string('provider', 40)->nullable();
            $t->string('provider_ref')->nullable();
            $t->string('document_hash', 64)->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('signed_ip', 45)->nullable();
            $t->string('signed_user_agent', 500)->nullable();
            $t->foreignId('integration_log_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('loan_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('account_no', 30);
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_version_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('shariah_structure', 30);
            $t->decimal('profit_rate', 6, 3);
            $t->string('frequency', 10);
            $t->unsignedSmallInteger('tenure');
            $t->decimal('principal', 18, 2);                // financed amount (asset cost financed)
            $t->decimal('profit_total', 18, 2);
            $t->decimal('charges_total', 18, 2)->default(0); // charges added to instalments
            $t->decimal('total_receivable', 18, 2);
            $t->decimal('principal_outstanding', 18, 2);
            $t->decimal('profit_outstanding', 18, 2);
            $t->decimal('charges_outstanding', 18, 2)->default(0);
            $t->decimal('late_charges_outstanding', 18, 2)->default(0);
            $t->decimal('total_paid', 18, 2)->default(0);
            $t->string('status', 20)->default('active');    // LoanAccountStatus
            $t->unsignedSmallInteger('dpd')->default(0);
            $t->string('classification', 20)->default('regular'); // regular|oaem|substandard|doubtful|loss
            $t->json('terms_snapshot');
            $t->date('first_due_date');
            $t->date('maturity_date');
            $t->timestamp('disbursed_at');
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('noc_issued_at')->nullable();
            $t->string('noc_no', 30)->nullable();
            $t->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('recovery_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'account_no']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'customer_id']);
        });

        Schema::create('instalments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->date('due_date');
            $t->decimal('principal_due', 18, 2);
            $t->decimal('profit_due', 18, 2);
            $t->decimal('charges_due', 18, 2)->default(0);
            $t->decimal('late_charge_due', 18, 2)->default(0);
            $t->decimal('total_due', 18, 2);
            $t->decimal('principal_paid', 18, 2)->default(0);
            $t->decimal('profit_paid', 18, 2)->default(0);
            $t->decimal('charges_paid', 18, 2)->default(0);
            $t->decimal('late_charge_paid', 18, 2)->default(0);
            $t->decimal('total_paid', 18, 2)->default(0);
            $t->string('status', 20)->default('pending');   // InstalmentStatus
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->unique(['loan_account_id', 'number']);
            $t->index(['tenant_id', 'due_date', 'status']);
        });

        Schema::create('disbursements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->foreignId('loan_application_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_account_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('gross_amount', 18, 2);
            $t->decimal('deductions', 18, 2)->default(0);   // upfront deducted charges
            $t->decimal('net_amount', 18, 2);
            $t->string('recipient_type', 20);               // vendor|customer_bank|wallet
            $t->unsignedBigInteger('recipient_id')->nullable();
            $t->string('recipient_name');
            $t->string('recipient_iban', 34)->nullable();
            $t->foreignId('bank_account_id')->nullable();   // source tenant bank account (fk added in accounting migration)
            $t->string('provider', 40);
            $t->string('scenario', 40)->nullable();
            $t->string('status', 20)->default('initiated'); // DisbursementStatus
            $t->unsignedBigInteger('payment_order_id')->nullable();
            $t->string('idempotency_key', 100);
            $t->string('failure_reason', 500)->nullable();
            $t->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('loan_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->string('receipt_no', 40)->nullable();
            $t->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 18, 2);
            $t->string('channel', 30);                      // PaymentChannel
            $t->string('status', 20)->default('pending');   // pending|succeeded|failed|reversed
            $t->unsignedBigInteger('payment_order_id')->nullable();
            $t->unsignedBigInteger('wallet_transaction_id')->nullable();
            $t->string('idempotency_key', 100);
            $t->string('external_ref')->nullable();
            $t->json('allocation')->nullable();
            $t->decimal('unallocated_amount', 18, 2)->default(0);
            $t->foreignId('reversal_of_id')->nullable()->constrained('loan_payments')->nullOnDelete();
            $t->string('reversal_reason', 500)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('failure_reason', 500)->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'received_at']);
        });

        Schema::create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_payment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instalment_id')->constrained()->cascadeOnDelete();
            $t->string('component', 20);                    // late_charge|charges|profit|principal
            $t->decimal('amount', 18, 2);
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('recovery_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('loan_account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $t->string('contact_type', 20);                 // call|sms|visit|app_notification|whatsapp
            $t->string('contacted_party', 20)->default('borrower'); // borrower|guarantor (no third parties: DFS §6.5)
            $t->timestamp('contacted_at');
            $t->string('outcome', 30);                      // promise_to_pay|dispute|unreachable|settled|escalate_legal|paid|other
            $t->date('ptp_date')->nullable();
            $t->decimal('ptp_amount', 18, 2)->nullable();
            $t->string('ptp_status', 20)->nullable();       // open|kept|broken
            $t->string('call_recording_ref')->nullable();
            $t->string('notes', 2000)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'loan_account_id']);
        });

        Schema::create('portfolio_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 30);
            $t->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $t->foreignId('from_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('to_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('loan_account_ids');
            $t->string('reason', 500);
            $t->foreignId('transferred_by')->constrained('users')->cascadeOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['portfolio_transfers', 'recovery_contacts', 'payment_allocations', 'loan_payments', 'disbursements', 'instalments',
            'loan_accounts', 'signature_requests', 'key_fact_statements', 'objections', 'field_visits', 'credit_assessments',
            'bureau_checks', 'guarantors', 'application_status_histories', 'loan_applications', 'leads', 'approval_actions',
            'approval_requests', 'approval_stages', 'approval_workflows', 'branch_fund', 'funds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
