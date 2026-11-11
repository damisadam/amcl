<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Customer Information File (Agreement §8.2), KYC/e-KYC (DFS §1, LMS §10),
 * vendors (LMS §4), corporate/staff lending (LMS §5) and product management (LMS §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('business_name');
            $t->string('owner_name')->nullable();
            $t->string('cnic', 15)->nullable();
            $t->string('ntn', 20)->nullable();
            $t->string('registration_no')->nullable();
            $t->string('category', 40);                     // VendorCategory
            $t->string('kind', 20)->default('asset');       // asset (disbursement recipient) | operational
            $t->boolean('is_merchant')->default(false);     // accepts wallet QR payments
            $t->string('merchant_code', 30)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('iban', 34)->nullable();
            $t->string('account_title')->nullable();
            $t->string('preferred_payment_method', 20)->default('raast'); // raast|bank_transfer|cheque
            $t->decimal('markup_share_pct', 5, 2)->nullable();
            $t->string('pricing_notes', 1000)->nullable();
            $t->string('status', 20)->default('pending');   // VendorStatus
            $t->string('source', 20)->default('internal');  // internal|web_request
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'merchant_code']);
        });

        Schema::create('vendor_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('sku', 40)->nullable();
            $t->string('product_category', 40)->nullable();
            $t->decimal('price', 18, 2);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('corporate_clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('company_name');
            $t->string('registration_no')->nullable();
            $t->string('ntn', 20)->nullable();
            $t->string('address')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('contact_phone', 20)->nullable();
            $t->json('authorized_signatories')->nullable();
            $t->unsignedTinyInteger('salary_day')->default(1);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('cif_no', 30);                       // unified customer id within tenant
            $t->string('client_type', 20)->default('individual'); // individual|corporate|vendor_referred|staff
            $t->string('full_name');
            $t->string('full_name_ur')->nullable();
            $t->string('father_husband_name')->nullable();
            $t->string('cnic', 15)->nullable();             // 12345-1234567-1 format; synthetic in demo
            $t->date('cnic_issue_date')->nullable();
            $t->date('cnic_expiry_date')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('gender', 10)->nullable();
            $t->string('marital_status', 20)->nullable();
            $t->string('mobile', 20);
            $t->string('email')->nullable();
            $t->string('current_address')->nullable();
            $t->string('permanent_address')->nullable();
            $t->string('city')->nullable();
            $t->string('occupation')->nullable();
            $t->string('employment_type', 20)->nullable();  // salaried|self_employed|business|unemployed
            $t->string('employer_name')->nullable();
            $t->string('business_name')->nullable();
            $t->string('business_type')->nullable();
            $t->string('income_source')->nullable();
            $t->decimal('monthly_income', 18, 2)->default(0);
            $t->decimal('other_income', 18, 2)->default(0);
            $t->decimal('existing_obligations', 18, 2)->default(0); // monthly
            $t->string('kyc_status', 20)->default('not_started');   // KycStatus
            $t->string('aml_status', 20)->default('not_screened');  // AmlStatus (sensitive)
            $t->string('aml_risk', 10)->nullable();                 // low|medium|high
            $t->string('risk_rating', 10)->nullable();
            $t->boolean('is_blacklisted')->default(false);
            $t->string('blacklist_reason')->nullable();
            $t->string('status', 20)->default('pending');           // CustomerStatus
            $t->string('onboarding_channel', 20)->default('branch'); // app|web|branch|vendor|call_center
            $t->string('onboarding_step', 30)->nullable();
            $t->foreignId('origin_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->foreignId('corporate_client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('preferred_locale', 5)->default('en');
            $t->string('photo_document_id')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->string('archived_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'cif_no']);
            $t->unique(['tenant_id', 'cnic']);
            $t->unique(['tenant_id', 'mobile']);
            $t->index(['tenant_id', 'kyc_status']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('corporate_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('corporate_client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('employee_no', 30);
            $t->string('name');
            $t->string('cnic', 15)->nullable();
            $t->string('designation')->nullable();
            $t->date('joined_on')->nullable();
            $t->string('salary_band', 10)->nullable();
            $t->decimal('monthly_salary', 18, 2)->default(0);
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'corporate_client_id', 'employee_no']);
        });

        Schema::create('staff_salaries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('corporate_staff_id')->constrained('corporate_staff')->cascadeOnDelete();
            $t->string('period', 7);                        // YYYY-MM
            $t->decimal('gross', 18, 2);
            $t->decimal('loan_deduction', 18, 2)->default(0);
            $t->decimal('net', 18, 2);
            $t->string('source', 20)->default('manual');    // manual|import
            $t->timestamps();
            $t->unique(['corporate_staff_id', 'period']);
        });

        Schema::create('next_of_kins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('relation', 30);
            $t->string('cnic', 15)->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
        });

        Schema::create('customer_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 30)->default('general');
            $t->text('body');
            $t->timestamps();
        });

        Schema::create('customer_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('field', 40);
            $t->string('old_value')->nullable();
            $t->string('new_value')->nullable();
            $t->string('reason', 500);
            $t->string('status', 20)->default('pending');   // pending|approved|rejected
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_comment', 500)->nullable();
            $t->timestamps();
        });

        Schema::create('consents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('consent_type', 40);                 // ConsentType
            $t->string('version', 10)->default('1.0');
            $t->boolean('granted');
            $t->string('locale', 5)->default('en');
            $t->text('text_snapshot');
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamp('recorded_at');
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'customer_id', 'consent_type']);
        });

        Schema::create('kyc_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('step', 30);                         // KycStep
            $t->string('provider', 40)->nullable();
            $t->string('status', 20);                       // passed|failed|manual_review|pending
            $t->decimal('score', 6, 2)->nullable();
            $t->json('result')->nullable();
            $t->foreignId('integration_log_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_decision', 20)->nullable();
            $t->string('review_notes', 1000)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'customer_id', 'step']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('products')->nullOnDelete(); // sub-product
            $t->foreignId('loan_category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('name_ur')->nullable();
            $t->string('category', 40);                     // ProductCategory
            $t->text('description')->nullable();
            $t->text('description_ur')->nullable();
            $t->string('icon', 40)->nullable();
            $t->string('shariah_structure', 30);            // ShariahStructure
            $t->boolean('shariah_board_approved')->default(false);
            $t->string('shariah_approval_ref')->nullable();
            $t->string('client_segment', 30)->default('individual');
            $t->boolean('is_staff_product')->default(false);
            $t->boolean('is_active')->default(true);
            $t->boolean('show_in_catalog')->default(true);
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        // Versioned product terms: loans keep the version they were approved under.
        Schema::create('product_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('version');
            $t->string('status', 20)->default('active');    // draft|active|retired
            $t->decimal('min_amount', 18, 2);
            $t->decimal('max_amount', 18, 2);
            $t->unsignedSmallInteger('min_tenure');         // in instalment periods (months or weeks)
            $t->unsignedSmallInteger('max_tenure');
            $t->string('frequency', 10)->default('monthly'); // monthly|weekly
            $t->decimal('profit_rate', 6, 3);               // annual disclosed profit/markup rate %
            $t->string('profit_method', 20)->default('flat'); // flat|reducing
            $t->decimal('down_payment_pct', 5, 2)->default(0);
            $t->decimal('min_income', 18, 2)->default(0);
            $t->decimal('max_dbr', 5, 2)->default(40);      // DBR ceiling %
            $t->boolean('dbr_hard_gate')->default(true);
            $t->unsignedTinyInteger('min_age')->default(18);
            $t->unsignedTinyInteger('max_age')->default(60);
            $t->unsignedTinyInteger('guarantors_required')->default(0);
            $t->boolean('requires_vendor')->default(false);
            $t->string('disbursement_mode', 20)->default('vendor'); // vendor|customer_bank|wallet
            $t->unsignedSmallInteger('cooling_off_hours')->default(24);
            $t->boolean('cooling_off_waivable')->default(false);
            $t->boolean('allow_partial_payment')->default(true);
            $t->boolean('allow_prepayment')->default(true);
            $t->json('allocation_order')->nullable();       // e.g. ["late_charge","charges","profit","principal"]
            $t->string('late_payment_treatment', 20)->default('charity'); // charity|none
            $t->decimal('late_charge_per_day', 18, 2)->default(0);
            $t->unsignedTinyInteger('grace_days')->default(3);
            $t->string('min_credit_grade', 3)->nullable();
            $t->json('eligibility')->nullable();            // employment types, cities, etc.
            $t->date('effective_from');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['product_id', 'version']);
        });

        Schema::create('charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_version_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('type', 30);                         // ChargeType
            $t->string('calculation', 20);                  // flat|percentage
            $t->decimal('value', 18, 3);
            $t->string('apply_at', 30);                     // upfront_deducted|upfront_paid|added_to_instalments|on_event
            $t->timestamps();
        });

        Schema::create('product_required_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_version_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $t->string('applies_to', 20)->default('applicant'); // applicant|guarantor
            $t->boolean('is_mandatory')->default(true);
            $t->decimal('min_amount', 18, 2)->nullable();   // required only above this amount tier
            $t->timestamps();
        });

        Schema::create('branch_product', function (Blueprint $t) {
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['branch_product', 'product_required_documents', 'charges', 'product_versions', 'products', 'kyc_verifications', 'consents',
            'customer_change_requests', 'customer_notes', 'next_of_kins', 'staff_salaries', 'corporate_staff', 'customers',
            'corporate_clients', 'vendor_items', 'vendors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
