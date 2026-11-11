<?php

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Account;
use App\Models\ApprovalWorkflow;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\IntegrationSetting;
use App\Models\LoanCategory;
use App\Models\NotificationTemplate;
use App\Models\PaymentType;
use App\Models\Permission;
use App\Models\PostingRule;
use App\Models\Region;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\PeriodService;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provisions a new tenant (LMS §12.2). Templates are COPIED into tenant-owned records, after which
 * each tenant configures its own roles, chart, rules and workflows independently.
 */
class TenantProvisioner
{
    public const DEFAULT_FEATURES = ['lms', 'dfs', 'wallet', 'fms', 'vendor_portal', 'staff_lending', 'bill_payments'];

    /** Sync the code-defined permission catalogue and platform roles (idempotent). */
    public static function syncPermissions(): void
    {
        foreach (config('permissions.platform') as $key => $label) {
            Permission::updateOrCreate(['key' => $key], ['module' => 'platform', 'label' => $label, 'scope' => 'platform', 'is_sensitive' => false]);
        }
        foreach (config('permissions.tenant') as $module => $perms) {
            foreach ($perms as $key => $label) {
                $sensitive = str_ends_with($key, '*');
                Permission::updateOrCreate(['key' => rtrim($key, '*')], ['module' => $module, 'label' => $label, 'scope' => 'tenant', 'is_sensitive' => $sensitive]);
            }
        }
        foreach (config('roles.platform') as $key => $def) {
            $role = Role::firstOrCreate(['tenant_id' => null, 'key' => $key], ['name' => $def['name'], 'description' => $def['description'], 'scope' => 'platform', 'is_system' => true]);
            $role->permissions()->sync(self::expand($def['permissions'], 'platform'));
        }
    }

    public static function expand(array $patterns, string $scope): array
    {
        $all = Permission::where('scope', $scope)->pluck('id', 'key');

        return $all->filter(fn ($id, $key) => collect($patterns)->contains(fn ($p) => Str::is($p, $key)))->values()->all();
    }

    /**
     * @param  array  $data  name, code, legal_name, ... ; admin: [name, email, password]
     */
    public function provision(array $data, array $admin, ?User $by = null): Tenant
    {
        self::syncPermissions();

        return DB::transaction(function () use ($data, $admin, $by) {
            $tenant = Tenant::create(array_merge([
                'status' => TenantStatus::Active,
                'features' => self::DEFAULT_FEATURES,
                'activated_at' => now(),
            ], $data));

            return TenantContext::run($tenant, function () use ($tenant, $admin, $by) {
                $this->organisation($tenant);
                $roles = $this->roles($tenant);
                $this->documentTypes();
                $this->configuration();
                $this->chartAndRules();
                $this->fiscalYears();
                $this->bankAccounts($tenant);
                $this->workflows($roles);
                $this->integrations();
                $this->notificationTemplates();

                $user = new User(['name' => $admin['name'], 'email' => $admin['email'], 'designation' => 'Tenant Administrator']);
                $user->forceFill([
                    'tenant_id' => $tenant->id, 'type' => UserType::Staff, 'status' => UserStatus::Active, 'password' => $admin['password'],
                    'branch_id' => Branch::where('is_head_office', true)->value('id'), 'must_change_password' => $admin['must_change_password'] ?? false,
                    'password_changed_at' => now(), 'created_by' => $by?->id,
                ])->save();
                $user->roles()->attach($roles['tenant_admin']->id, ['assigned_at' => now(), 'assigned_by' => $by?->id]);

                Auditor::record('platform.tenant_provisioned', $tenant, [], ['code' => $tenant->code, 'admin' => $admin['email']], tenantId: $tenant->id);

                return $tenant;
            });
        });
    }

    private function organisation(Tenant $tenant): void
    {
        $region = Region::create(['code' => 'HO', 'name' => 'Head Office Region']);
        $area = $region->areas()->create(['code' => 'HO-A', 'name' => 'Head Office Area']);
        $area->branches()->create(['code' => 'HO', 'name' => 'Head Office', 'is_head_office' => true, 'city' => $tenant->city, 'address' => $tenant->address]);
        foreach ([['OPS', 'Operations'], ['CRD', 'Credit & Risk'], ['CMP', 'Compliance'], ['FIN', 'Finance'], ['REC', 'Recovery'], ['IT', 'IT & Admin']] as [$c, $n]) {
            Department::create(['code' => $c, 'name' => $n]);
        }
        CostCenter::create(['code' => 'CC-LEND', 'name' => 'Lending Operations', 'business_line' => 'lending']);
        CostCenter::create(['code' => 'CC-WAL', 'name' => 'Wallet Operations', 'business_line' => 'wallet']);
        CostCenter::create(['code' => 'CC-HO', 'name' => 'Head Office Administration', 'business_line' => 'corporate']);
    }

    /** @return array<string, Role> */
    private function roles(Tenant $tenant): array
    {
        $out = [];
        foreach (config('roles.tenant') as $key => $def) {
            $role = Role::forceCreate(['tenant_id' => $tenant->id, 'key' => $key, 'name' => $def['name'], 'description' => $def['description'], 'scope' => 'tenant', 'is_system' => $key === 'tenant_admin']);
            $role->permissions()->sync(self::expand($def['permissions'], 'tenant'));
            $out[$key] = $role;
        }

        return $out;
    }

    private function documentTypes(): void
    {
        $types = [
            ['CNIC_FRONT', 'CNIC (front)', 'شناختی کارڈ (سامنے)', 'identity', null, true, 'none', 'kyc'],
            ['CNIC_BACK', 'CNIC (back)', 'شناختی کارڈ (پیچھے)', 'identity', null, true, 'none', 'kyc'],
            ['SELFIE', 'Live selfie', 'لائیو سیلفی', 'identity', null, false, 'none', 'kyc'],
            ['UTILITY_BILL', 'Utility bill (last 3 months)', 'یوٹیلیٹی بل', 'identity', 90, false, 'none', 'field_visit'],
            ['SALARY_SLIP', 'Salary slip', 'تنخواہ کی پرچی', 'income', 90, false, 'none', 'income_analysis'],
            ['BANK_STATEMENT', 'Bank statement (6 months)', 'بینک اسٹیٹمنٹ', 'income', 90, false, 'none', 'income_analysis'],
            ['BUSINESS_PROOF', 'Business proof / registration', 'کاروبار کا ثبوت', 'business', null, false, 'none', 'business_cashflow'],
            ['GUARANTOR_CNIC', 'Guarantor CNIC', 'ضامن کا شناختی کارڈ', 'guarantor', null, true, 'none', 'tasdeeq'],
            ['QUOTATION', 'Vendor quotation / invoice', 'کوٹیشن', 'loan', 30, false, 'none', null],
            ['FEE_CHALLAN', 'School fee challan', 'فیس چالان', 'loan', 60, false, 'none', null],
            ['APPLICATION_FORM', 'Financing application form', 'درخواست فارم', 'agreement', null, false, 'digital', null],
            ['FIN_AGREEMENT', 'Financing agreement', 'معاہدہ تمویل', 'agreement', null, false, 'digital', null],
            ['UNDERTAKING', 'Customer undertaking', 'حلف نامہ صارف', 'agreement', null, false, 'digital', null],
            ['DPN', 'Demand promissory note (DPN)', 'ڈیمانڈ پرامیسری نوٹ', 'agreement', null, false, 'conditional', null],
            ['AFFIDAVIT', 'Affidavit', 'بیان حلفی', 'agreement', null, false, 'conditional', null],
            ['KFS', 'Key Fact Statement', 'کلیدی حقائق کا بیان', 'agreement', null, false, 'none', null],
            ['RECEIPT', 'Payment receipt', 'رسید', 'loan', null, false, 'none', null],
            ['NOC', 'No Objection Certificate', 'این او سی', 'loan', null, false, 'none', null],
            ['VISIT_PHOTO', 'Visit proof photo', 'دورہ کی تصویر', 'field', null, false, 'none', 'field_visit'],
            ['VENDOR_REG', 'Vendor registration / licence', 'وینڈر رجسٹریشن', 'vendor', null, true, 'none', null],
            ['VENDOR_BANK', 'Vendor bank letter', 'وینڈر بینک لیٹر', 'vendor', null, false, 'none', null],
            ['JV_SUPPORT', 'Voucher supporting document', 'ثبوت', 'other', null, false, 'none', null],
            ['VENDOR_INVOICE', 'Vendor invoice in institution\'s name', 'ادارے کے نام وینڈر انوائس', 'loan', null, false, 'none', 'asset_ownership'],
            ['PURCHASE_REQUISITION', 'Purchase requisition / agency agreement', 'خریداری کی درخواست / وکالہ معاہدہ', 'agreement', null, false, 'digital', null],
            ['MURABAHA_OFFER', 'Murabaha offer & acceptance', 'مرابحہ پیشکش و قبولیت', 'agreement', null, false, 'digital', null],
        ];
        foreach ($types as [$code, $name, $ur, $cat, $validity, $expiry, $sign, $step]) {
            DocumentType::create(['code' => $code, 'name' => $name, 'name_ur' => $ur, 'category' => $cat, 'validity_days' => $validity,
                'tracks_expiry' => $expiry, 'signing_method' => $sign, 'verification_step' => $step]);
        }
    }

    private function configuration(): void
    {
        foreach (['raast' => 'Raast', 'qr_iban' => 'Merchant QR / IBAN alias', 'one_bill' => '1Bill / 1LINK', 'easypaisa' => 'Easypaisa', 'jazzcash' => 'JazzCash',
            'wallet' => 'Wallet', 'cash' => 'Cash at branch', 'bank_transfer' => 'Bank transfer', 'salary_deduction' => 'Salary deduction', 'cheque' => 'Cheque'] as $code => $name) {
            PaymentType::create(['code' => $code, 'name' => $name]);
        }
        foreach ([['VEH', 'Vehicle financing', 'Consumer – vehicles'], ['CON', 'Consumer durables', 'Consumer – durables'], ['EDU', 'Education', 'Consumer – education'],
            ['SME', 'Micro & SME enterprise', 'Microenterprise'], ['ENR', 'Renewable energy', 'Green financing'], ['STF', 'Staff / payroll-backed', 'Consumer – salaried']] as [$c, $n, $r]) {
            LoanCategory::create(['code' => $c, 'name' => $n, 'regulatory_category' => $r]);
        }
    }

    private function chartAndRules(): void
    {
        $ids = [];
        foreach (config('chart_of_accounts') as [$code, $name, $type, $level, $parent, $role, $cf]) {
            $a = Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'level' => $level, 'parent_id' => $parent ? $ids[$parent] : null,
                'is_postable' => $level === 'account', 'system_role' => $role, 'cash_flow_category' => $cf]);
            $ids[$code] = $a->id;
        }
        $byRole = Account::whereNotNull('system_role')->pluck('id', 'system_role');
        foreach (config('posting_rules') as $event => $lines) {
            foreach ($lines as $i => [$side, $role, $key]) {
                PostingRule::create(['event_type' => $event, 'line_no' => $i + 1, 'side' => $side, 'account_id' => $byRole[$role], 'amount_key' => $key,
                    'description' => Str::headline($role)]);
            }
        }
    }

    private function fiscalYears(): void
    {
        $svc = app(PeriodService::class);
        $now = now();
        $startYear = $now->month >= 7 ? $now->year : $now->year - 1;
        $svc->createFiscalYear($startYear - 1);
        $svc->createFiscalYear($startYear);
    }

    private function bankAccounts(Tenant $tenant): void
    {
        $role = Account::whereNotNull('system_role')->pluck('id', 'system_role');
        $prefix = 'PK'.str_pad((string) (10 + $tenant->id), 2, '0', STR_PAD_LEFT).'SIML';
        foreach ([['operating', 'Operating Account', 'operating_bank', 'sponsor_bank'], ['disbursement', 'Disbursement Account', 'disbursement_bank', 'raast'],
            ['collection', 'Raast Collection Account', 'collection_bank', 'raast'], ['safeguarded', 'Safeguarded Wallet Account', 'safeguarded_bank', 'raast']] as $i => [$purpose, $name, $r, $provider]) {
            BankAccount::create(['name' => $name, 'bank_name' => 'Sponsor Bank (simulated)', 'iban' => $prefix.str_pad((string) ($tenant->id * 10 + $i), 16, '0', STR_PAD_LEFT),
                'purpose' => $purpose, 'provider' => $provider, 'account_id' => $role[$r], 'minimum_balance' => $purpose === 'disbursement' ? '500000.00' : '0.00', 'is_simulated' => true]);
        }
    }

    /** @param array<string, Role> $roles */
    private function workflows(array $roles): void
    {
        // Committee (large amounts) decides BEFORE final disbursement approval, which is always the last stage.
        $loan = ApprovalWorkflow::create(['type' => 'loan', 'name' => 'Standard financing approval', 'priority' => 100]);
        foreach ([['CRO review', 'cro', null, 24], ['Manager Operations', 'manager_operations', null, 24], ['Compliance Officer', 'compliance_officer', null, 24],
            ['Risk Officer', 'risk_officer', null, 24], ['Approval Committee (≥ PKR 1,000,000)', 'approval_committee', '1000000.00', 48],
            ['Disbursement Approval', 'disbursement_approver', null, 24]] as $i => [$name, $role, $min, $sla]) {
            $loan->stages()->create(['tenant_id' => $loan->tenant_id, 'sequence' => $i + 1, 'name' => $name, 'role_id' => $roles[$role]->id, 'sla_hours' => $sla,
                'min_amount' => $min, 'comment_required_on_approve' => in_array($role, ['compliance_officer', 'approval_committee'], true)]);
        }

        $finance = [
            'journal_voucher' => [['Finance Officer review', 'finance_officer', null]],
            'vendor_payment' => [['Finance Officer', 'finance_officer', null], ['Approval Committee (≥ PKR 2,000,000)', 'approval_committee', '2000000.00']],
            'expense' => [['Finance Officer', 'finance_officer', null]],
            'fund_transfer' => [['Finance Officer', 'finance_officer', null], ['Approval Committee', 'approval_committee', null]],
            'write_off' => [['Risk Officer', 'risk_officer', null], ['Finance Officer', 'finance_officer', null], ['Approval Committee', 'approval_committee', null]],
            'journal_reversal' => [['Finance Officer (checker)', 'finance_officer', null]],
            'charity_disbursement' => [['Compliance (Shariah)', 'compliance_officer', null], ['Finance Officer', 'finance_officer', null]],
            'payroll' => [['Finance Officer', 'finance_officer', null]],
            'reconciliation' => [['Finance Officer', 'finance_officer', null]],
        ];
        foreach ($finance as $type => $stages) {
            $wf = ApprovalWorkflow::create(['type' => $type, 'name' => Str::headline($type).' approval', 'priority' => 100]);
            foreach ($stages as $i => [$name, $role, $min]) {
                $wf->stages()->create(['tenant_id' => $wf->tenant_id, 'sequence' => $i + 1, 'name' => $name, 'role_id' => $roles[$role]->id, 'min_amount' => $min, 'sla_hours' => 24]);
            }
        }
    }

    private function integrations(): void
    {
        foreach (config('integrations.providers') as $key => $def) {
            $creds = [];
            foreach ($def['fields'] as $f) {
                $creds[$f] = $f === 'base_url' ? 'https://sandbox.'.str_replace('_', '-', $key).'.example.invalid' : (in_array($f, config('integrations.secret_fields'), true) ? 'demo-'.Str::random(24) : 'DEMO-'.strtoupper(Str::random(6)));
            }
            IntegrationSetting::create([
                'provider' => $key, 'enabled' => $key !== 'push', 'mode' => 'demo', 'environment' => 'sandbox', 'base_url' => $creds['base_url'] ?? null,
                'credentials' => $creds, 'options' => ['timeout_seconds' => 15, 'max_retries' => 3, 'default_scenario' => 'success', 'callback_delay_seconds' => 0],
            ]);
        }
    }

    private function notificationTemplates(): void
    {
        $t = [
            ['application_status', 'sms', 'Application {application_no}: {status}', 'Your financing application {application_no} is now "{status}". Track it in the app.', 'آپ کی درخواست {application_no} کی حیثیت: {status}'],
            ['instalment_reminder', 'sms', 'Instalment reminder', '{body}', null],
            ['payment_received', 'sms', 'Payment received', '{body}', null],
            ['disbursement', 'email', 'Your financing has been disbursed', '{body}', null],
            ['noc_issued', 'email', 'No Objection Certificate issued', '{body}', null],
            ['wallet_topup', 'sms', 'Wallet top-up', '{body}', null],
        ];
        foreach ($t as [$event, $channel, $subject, $body, $bodyUr]) {
            NotificationTemplate::create(['event' => $event, 'channel' => $channel, 'subject' => $subject, 'body' => $body, 'body_ur' => $bodyUr]);
        }
    }
}
