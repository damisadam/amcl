<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform, tenancy and identity foundation.
 *
 * Every tenant-owned table in this application carries a non-null tenant_id.
 * Platform-level records (platform users, platform roles) use tenant_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();              // url slug / entry point, e.g. "amcl"
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('registration_no')->nullable();
            $t->string('license_ref')->nullable();          // NBFC / SECP licence reference (fictional for demos)
            $t->string('ntn')->nullable();
            $t->boolean('is_demo')->default(true);
            $t->string('status', 20)->default('active');    // TenantStatus
            $t->string('primary_contact_name')->nullable();
            $t->string('primary_contact_email')->nullable();
            $t->string('primary_contact_phone')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('support_phone')->nullable();
            $t->string('support_email')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('primary_color', 9)->default('#15803d');
            $t->string('tagline')->nullable();
            $t->string('tagline_ur')->nullable();
            $t->string('default_locale', 5)->default('en');
            $t->string('base_currency', 3)->default('PKR');
            $t->string('wallet_safeguarding_model', 30)->default('tenant_safeguarded'); // see DECISIONS.md D-001
            $t->json('features')->nullable();               // feature entitlements
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->string('suspension_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('tenant_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('group', 40);
            $t->string('key', 80);
            $t->json('value')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'group', 'key']);
        });

        Schema::create('regions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('areas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('region_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('area_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('phone')->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('employee_code', 30);
            $t->string('name');
            $t->string('cnic', 15)->nullable();
            $t->string('designation')->nullable();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reports_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->date('joined_on')->nullable();
            $t->decimal('basic_salary', 18, 2)->default(0);
            $t->decimal('allowances', 18, 2)->default(0);
            $t->string('bank_iban', 34)->nullable();
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'employee_code']);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('type', 20);                         // UserType: platform|staff|customer|vendor
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('mobile', 20)->nullable();
            $t->string('cnic', 15)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('mobile_verified_at')->nullable();
            $t->string('password');
            $t->string('status', 20)->default('active');    // UserStatus
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->unsignedBigInteger('vendor_id')->nullable()->index();
            $t->string('designation')->nullable();
            $t->string('locale', 5)->default('en');
            $t->unsignedSmallInteger('failed_login_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->boolean('mfa_enabled')->default(false);
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'email']);
            $t->unique(['tenant_id', 'mobile']);
            $t->index(['tenant_id', 'type', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        // Permission catalogue is global (code-defined); role assignments are per tenant.
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();               // e.g. "lms.applications.approve"
            $t->string('module', 40);
            $t->string('label');
            $t->string('scope', 20)->default('tenant');     // tenant|platform
            $t->boolean('is_sensitive')->default(false);
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('key', 60);
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('scope', 20)->default('tenant');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['tenant_id', 'key']);
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->primary(['role_id', 'user_id']);
        });

        Schema::create('user_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('fingerprint', 64);
            $t->string('label')->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->string('ip', 45)->nullable();
            $t->boolean('is_trusted')->default(false);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'fingerprint']);
        });

        Schema::create('otp_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('identifier', 100);                 // mobile number or user id
            $t->string('purpose', 40);                      // registration|login_mfa|password_reset|wallet_pin|transaction
            $t->string('code_hash');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'identifier', 'purpose']);
        });

        // Time-limited, justified, audited platform support access to a tenant (D-005).
        Schema::create('support_access_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('platform_user_id')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 500);
            $t->string('ticket_ref')->nullable();
            $t->timestamp('expires_at');
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_type', 20)->nullable();
            $t->string('action', 100);
            $t->nullableMorphs('auditable');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->string('correlation_id', 64)->nullable()->index();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'action']);
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'support_access_grants', 'otp_codes', 'user_devices', 'role_user', 'permission_role', 'roles', 'permissions',
            'sessions', 'password_reset_tokens', 'users', 'employees', 'departments', 'branches', 'areas', 'regions', 'tenant_settings', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
