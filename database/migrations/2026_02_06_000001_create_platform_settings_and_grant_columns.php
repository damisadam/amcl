<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Platform-wide settings and support-access grant lifecycle columns (request → grant → expire/revoke). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->json('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::table('support_access_grants', function (Blueprint $t) {
            $t->unsignedSmallInteger('duration_hours')->default(4)->after('ticket_ref');
            $t->timestamp('granted_at')->nullable()->after('granted_by');
            $t->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_access_grants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('revoked_by');
            $t->dropColumn(['duration_hours', 'granted_at']);
        });
        Schema::dropIfExists('platform_settings');
    }
};
