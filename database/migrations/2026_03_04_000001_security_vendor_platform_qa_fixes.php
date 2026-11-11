<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (Q4): TOTP MFA for privileged users (S1), two-person demo reset (S4),
 * vendor item price-change approval (M20) and generated tenant logos (M21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable()->after('mfa_enabled');          // encrypted base32 TOTP secret
            $t->timestamp('mfa_confirmed_at')->nullable()->after('mfa_secret');
            $t->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at'); // JSON list of bcrypt hashes
            $t->unsignedBigInteger('mfa_last_timestep')->nullable()->after('mfa_recovery_codes'); // replay protection
        });

        Schema::create('demo_reset_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $t->string('reason', 500);
            $t->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::table('vendor_items', function (Blueprint $t) {
            $t->decimal('pending_price', 18, 2)->nullable()->after('price');
            $t->foreignId('pending_price_requested_by')->nullable()->after('pending_price')->constrained('users')->nullOnDelete();
            $t->timestamp('pending_price_requested_at')->nullable()->after('pending_price_requested_by');
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->text('logo_path')->nullable()->change(); // may hold a generated SVG data URI
        });
    }

    public function down(): void
    {
        Schema::table('vendor_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pending_price_requested_by');
            $t->dropColumn(['pending_price', 'pending_price_requested_at']);
        });
        Schema::dropIfExists('demo_reset_requests');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mfa_secret', 'mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_timestep']));
    }
};
