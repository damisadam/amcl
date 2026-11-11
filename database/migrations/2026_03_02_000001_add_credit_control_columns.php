<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes C2 / C5 / H5: guarantor AML screening, GPS proof-of-presence on field visits,
 * and a fingerprint of the inputs a credit assessment was based on (stale-assessment detection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantors', function (Blueprint $t) {
            $t->string('aml_status', 20)->nullable()->after('verification_status'); // clear|potential_match|confirmed_match
            $t->string('aml_risk', 10)->nullable()->after('aml_status');
            $t->json('aml_result')->nullable()->after('aml_risk');
            $t->timestamp('aml_checked_at')->nullable()->after('aml_result');
        });
        Schema::table('field_visits', function (Blueprint $t) {
            $t->decimal('gps_accuracy_m', 8, 1)->nullable()->after('longitude');
            $t->unsignedInteger('distance_from_address_m')->nullable()->after('gps_accuracy_m');
        });
        Schema::table('customers', function (Blueprint $t) {
            // Optional reference location of the current address (from a previous visit / geocoding) to flag distant check-ins.
            $t->decimal('address_latitude', 10, 7)->nullable()->after('city');
            $t->decimal('address_longitude', 10, 7)->nullable()->after('address_latitude');
        });
        Schema::table('credit_assessments', function (Blueprint $t) {
            $t->string('input_fingerprint', 64)->nullable()->after('score_breakdown');
            $t->json('inputs_snapshot')->nullable()->after('input_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('credit_assessments', fn (Blueprint $t) => $t->dropColumn(['input_fingerprint', 'inputs_snapshot']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['address_latitude', 'address_longitude']));
        Schema::table('field_visits', fn (Blueprint $t) => $t->dropColumn(['gps_accuracy_m', 'distance_from_address_m']));
        Schema::table('guarantors', fn (Blueprint $t) => $t->dropColumn(['aml_status', 'aml_risk', 'aml_result', 'aml_checked_at']));
    }
};
