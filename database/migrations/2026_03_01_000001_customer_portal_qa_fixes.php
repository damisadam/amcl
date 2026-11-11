<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (customer portal):
 *  C3 – customer email is a login id: unique per tenant.
 *  C4 – record which document version the signer opened before signing.
 *  H1 – keep the first submission date when a returned application is resubmitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->unique(['tenant_id', 'email']));
        Schema::table('signature_requests', function (Blueprint $t) {
            $t->timestamp('viewed_at')->nullable()->after('document_hash');
            $t->string('viewed_hash', 64)->nullable()->after('viewed_at');
        });
        Schema::table('loan_applications', fn (Blueprint $t) => $t->timestamp('first_submitted_at')->nullable()->after('submitted_at'));
    }

    public function down(): void
    {
        Schema::table('loan_applications', fn (Blueprint $t) => $t->dropColumn('first_submitted_at'));
        Schema::table('signature_requests', fn (Blueprint $t) => $t->dropColumn(['viewed_at', 'viewed_hash']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropUnique(['tenant_id', 'email']));
    }
};
