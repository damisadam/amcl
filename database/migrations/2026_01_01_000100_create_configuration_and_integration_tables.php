<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company configuration (LMS §9), document types (LMS §11), CMS content,
 * notification templates and the integration/API management layer (Agreement §9.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->string('name');
            $t->timestamps();
            $t->unique(['tenant_id', 'date']);
        });

        Schema::create('payment_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);                         // PaymentChannel value
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('loan_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('regulatory_category')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('blacklist_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('cnic', 15)->nullable();
            $t->string('name');
            $t->string('reason', 30);                       // prior_default|fraud|aml|other
            $t->string('notes', 1000)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'cnic']);
        });

        Schema::create('document_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->string('name_ur')->nullable();
            $t->string('category', 30);                     // identity|income|business|guarantor|loan|agreement|vendor|field|other
            $t->unsignedSmallInteger('validity_days')->nullable(); // e.g. utility bill within 90 days
            $t->boolean('tracks_expiry')->default(false);
            $t->string('signing_method', 20)->default('none'); // none|digital|conditional|wet_ink  (LMS §6.5)
            $t->string('verification_step', 40)->nullable();   // income_analysis|tasdeeq|kyc|...
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->morphs('documentable');
            $t->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->string('disk', 30)->default('private');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->unsignedSmallInteger('version')->default(1);
            $t->foreignId('previous_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->string('status', 20)->default('pending');   // pending|verified|rejected|superseded
            $t->date('expires_on')->nullable();
            $t->boolean('is_synthetic')->default(false);    // generated demo file
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'expires_on']);
        });

        Schema::create('content_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);                         // faq|banner|notice|help
            $t->string('title');
            $t->string('title_ur')->nullable();
            $t->text('body')->nullable();
            $t->text('body_ur')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('event', 60);
            $t->string('channel', 20);                      // sms|email|whatsapp|push|in_app
            $t->string('subject')->nullable();
            $t->string('subject_ur')->nullable();
            $t->text('body');
            $t->text('body_ur')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'event', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 60);
            $t->string('channel', 20);
            $t->string('recipient');
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('status', 20);                       // sent|failed|fallback
            $t->string('provider_ref')->nullable();
            $t->foreignId('fallback_of_id')->nullable()->constrained('notification_logs')->nullOnDelete();
            $t->boolean('simulated')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        // Tenant-specific provider configuration. Credentials are encrypted at rest (cast: encrypted:array).
        Schema::create('integration_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 40);
            $t->boolean('enabled')->default(true);
            $t->string('mode', 10)->default('demo');        // demo|live
            $t->string('environment', 20)->default('sandbox');
            $t->string('base_url')->nullable();
            $t->text('credentials')->nullable();            // encrypted JSON
            $t->json('options')->nullable();                // timeout, retries, webhook url, default scenario...
            $t->foreignId('live_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('live_approved_at')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'provider']);
        });

        Schema::create('integration_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('provider', 40);
            $t->string('operation', 60);
            $t->string('mode', 10);
            $t->string('correlation_id', 64)->index();
            $t->string('idempotency_key', 100)->nullable();
            $t->string('scenario', 40)->nullable();
            $t->json('request')->nullable();                // masked
            $t->json('response')->nullable();
            $t->string('status', 20);                       // success|failure|pending|timeout|error
            $t->string('error_code', 60)->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->nullableMorphs('subject');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'provider', 'created_at']);
            $t->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['integration_logs', 'integration_settings', 'notifications', 'notification_logs', 'notification_templates', 'content_items',
            'documents', 'document_types', 'blacklist_entries', 'loan_categories', 'payment_types', 'holidays'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
