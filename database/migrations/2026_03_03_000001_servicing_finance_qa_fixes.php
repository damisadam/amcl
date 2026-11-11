<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * QA fixes (servicing / finance):
 *  - H7: disbursement maker-checker (released_by / released_at)
 *  - S6: journal reversal requested by one user, approved by another (reversal_* columns)
 *  - F5: Murabaha accounts + posting rules for EXISTING tenants (new tenants get them from the provisioner templates)
 *  - F7 / journal_reversal: new document types and approval workflow for existing tenants
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $t) {
            $t->foreignId('released_by')->nullable()->after('initiated_by')->constrained('users')->nullOnDelete();
            $t->timestamp('released_at')->nullable()->after('released_by');
        });
        Schema::table('journal_vouchers', function (Blueprint $t) {
            $t->foreignId('reversal_requested_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $t->timestamp('reversal_requested_at')->nullable()->after('reversal_requested_by');
            $t->string('reversal_reason', 500)->nullable()->after('reversal_requested_at');
        });

        $coa = collect(config('chart_of_accounts'))->keyBy(0);
        $rules = config('posting_rules');
        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $parent = DB::table('accounts')->where('tenant_id', $tenantId)->where('code', '1200')->value('id');
            if (! $parent) {
                continue; // tenant without a provisioned chart
            }
            foreach (['1230', '1240', '1250'] as $code) {
                [, $name, $type, $level, , $role, $cf] = $coa[$code];
                if (! DB::table('accounts')->where('tenant_id', $tenantId)->where('system_role', $role)->exists()) {
                    DB::table('accounts')->insert(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'type' => $type, 'level' => $level, 'parent_id' => $parent,
                        'is_postable' => true, 'is_active' => true, 'system_role' => $role, 'cash_flow_category' => $cf, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $byRole = DB::table('accounts')->where('tenant_id', $tenantId)->whereNotNull('system_role')->pluck('id', 'system_role');
            foreach (['loan_disbursement', 'loan_disbursement_wallet', 'loan_repayment_bank', 'loan_repayment_wallet', 'loan_write_off'] as $event) {
                DB::table('posting_rules')->where('tenant_id', $tenantId)->where('event_type', $event)->delete();
                foreach ($rules[$event] as $i => [$side, $role, $key]) {
                    DB::table('posting_rules')->insert(['tenant_id' => $tenantId, 'event_type' => $event, 'line_no' => $i + 1, 'side' => $side, 'account_id' => $byRole[$role],
                        'amount_key' => $key, 'description' => Str::headline($role), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            foreach ([['VENDOR_INVOICE', "Vendor invoice in institution's name", 'loan', 'none', 'asset_ownership'],
                ['PURCHASE_REQUISITION', 'Purchase requisition / agency agreement', 'agreement', 'digital', null],
                ['MURABAHA_OFFER', 'Murabaha offer & acceptance', 'agreement', 'digital', null]] as [$code, $name, $cat, $sign, $step]) {
                DB::table('document_types')->insertOrIgnore(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'category' => $cat, 'signing_method' => $sign,
                    'verification_step' => $step, 'tracks_expiry' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $fo = DB::table('roles')->where('tenant_id', $tenantId)->where('key', 'finance_officer')->value('id');
            if ($fo && ! DB::table('approval_workflows')->where('tenant_id', $tenantId)->where('type', 'journal_reversal')->exists()) {
                $wf = DB::table('approval_workflows')->insertGetId(['tenant_id' => $tenantId, 'type' => 'journal_reversal', 'name' => 'Journal Reversal approval',
                    'priority' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('approval_stages')->insert(['tenant_id' => $tenantId, 'approval_workflow_id' => $wf, 'sequence' => 1, 'name' => 'Finance Officer (checker)',
                    'role_id' => $fo, 'sla_hours' => 24, 'comment_required_on_approve' => false, 'comment_required_on_reject' => true, 'allow_return' => true,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reversal_requested_by');
            $t->dropColumn(['reversal_requested_at', 'reversal_reason']);
        });
        Schema::table('disbursements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('released_by');
            $t->dropColumn('released_at');
        });
    }
};
