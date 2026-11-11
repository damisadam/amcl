<?php

namespace Database\Seeders\Demo;

use App\Enums\ApplicationStatus;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\ApprovalRequest;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Holiday;
use App\Models\Lead;
use App\Models\LoanAccount;
use App\Models\LoanApplication;
use App\Models\NextOfKin;
use App\Models\OtherReceivable;
use App\Models\Product;
use App\Models\Region;
use App\Models\Role;
use App\Models\SettlementRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\Wallet;
use App\Services\Accounting\FinanceOperations;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\ReconciliationService;
use App\Services\Accounting\VendorPaymentService;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\OnboardingService;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\DisbursementService;
use App\Services\Lending\RepaymentService;
use App\Services\Lending\ServicingService;
use App\Services\Lending\UnderwritingService;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Products\ProductService;
use App\Services\Support\ComplaintService;
use App\Services\Support\Numbering;
use App\Services\Tenancy\TenantProvisioner;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Builds one demo tenant by DRIVING THE REAL SERVICES with Carbon time-travel, so every record,
 * ledger entry, journal and report is produced by the same workflows a user would trigger.
 * Deterministic: all choices derive from mt_rand seeded with the tenant code.
 */
class TenantDemoBuilder
{
    private Tenant $tenant;

    /** @var array<string, list<User>> */
    private array $staff = [];

    /** @var list<User> customer users */
    private array $customers = [];

    private array $products = [];

    private array $vendors = [];

    private const CITY_COORDS = ['Lahore' => [31.52, 74.35], 'Karachi' => [24.86, 67.01], 'Islamabad' => [33.68, 73.05], 'Faisalabad' => [31.42, 73.08], 'Peshawar' => [34.01, 71.52]];

    /** customer user id => registration time */
    private array $joinedAt = [];

    private Carbon $today;

    private int $seq = 0;

    public function __construct(private array $def, private ?\Closure $log = null)
    {
        $this->today = Carbon::now()->startOfDay();
    }

    public function build(): Tenant
    {
        mt_srand(crc32($this->def['code']));
        $this->at($this->today->copy()->subMonths(13)->setTime(9, 0), function () {
            $this->provision();
            TenantContext::set($this->tenant);
            $this->organisation();
            $this->users();
            $this->configuration();
            $this->catalogue();
            $this->funding();
        });
        TenantContext::set($this->tenant);
        // Provisioning ran "13 months ago"; make sure every fiscal year up to the real today exists.
        $fyStart = $this->today->month >= 7 ? $this->today->year : $this->today->year - 1;
        foreach ([$fyStart - 1, $fyStart] as $y) {
            app(PeriodService::class)->createFiscalYear($y);
        }
        $this->say('customers');
        $this->customersAndKyc();
        $this->say('lending');
        $this->lending();
        $this->say('wallet');
        $this->walletActivity();
        $this->say('service & finance');
        $this->servicingAndSupport();
        $this->finance();
        $this->closePeriods();
        $this->runToday();
        TenantContext::clear();

        return $this->tenant;
    }

    // ------------------------------------------------------------------ helpers

    private function at(Carbon $time, \Closure $fn): mixed
    {
        $prev = Carbon::getTestNow();
        Carbon::setTestNow($time);
        try {
            return $fn();
        } finally {
            Carbon::setTestNow($prev);
        }
    }

    private function as(User $user, \Closure $fn): mixed
    {
        $prev = auth()->user();
        auth()->setUser($user->fresh(['roles']));
        try {
            return $fn($user->fresh(['roles']));
        } finally {
            $prev ? auth()->setUser($prev) : auth()->forgetUser();
        }
    }

    private function user(string $role, int $i = 0): User
    {
        return $this->staff[$role][$i % count($this->staff[$role])];
    }

    /** The staff member with $role based in $branchId (branch roles exist once per branch), else the first one. */
    private function branchUser(string $role, ?int $branchId): User
    {
        foreach ($this->staff[$role] as $u) {
            if ((int) $u->branch_id === (int) $branchId) {
                return $u;
            }
        }

        return $this->user($role);
    }

    /**
     * A non-admin staff member holding $permission. Operational decisions are made by the role that owns them,
     * never by the Tenant Admin, so the seeder keeps working when admin permissions are trimmed (segregation of duties).
     */
    private function actor(string $permission, array $prefer = []): User
    {
        foreach (array_merge($prefer, array_keys($this->staff)) as $role) {
            if ($role === 'tenant_admin' || empty($this->staff[$role])) {
                continue;
            }
            foreach ($this->staff[$role] as $u) {
                if ($u->fresh(['roles'])->hasPermission($permission)) {
                    return $u;
                }
            }
        }

        return $this->user('tenant_admin');
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function key(string $p): string
    {
        return $p.'-'.$this->def['code'].'-'.(++$this->seq);
    }

    /**
     * A synthetic guarantor CNIC that the deterministic simulators score as clean (bureau hit, score ≥ 620,
     * no AML match), so seeded guarantors pass the guarantor verification gate (see API_SIMULATION.md).
     */
    private function cleanGuarantorCnic(int $k): string
    {
        for ($i = 0; ; $i++) {
            $cnic = '99'.(500 + ($k + $i * 7) % 499).'-'.str_pad((string) (1000000 + crc32($this->def['code'].$k.'-'.$i) % 8999999), 7, '0', STR_PAD_LEFT).'-'.(($k + $i) % 7);
            $seed = preg_replace('/\D/', '', $cnic);
            if (crc32($seed) % 10 !== 0 && \App\Services\Integrations\Deterministic::number($seed, 470, 840) >= 620) {
                return $cnic;
            }
        }
    }

    /** Run an optional demo action; a business-rule failure is logged instead of aborting the build. */
    private function safe(string $label, \Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\App\Exceptions\DomainException $e) {
            $this->say("{$label} skipped: ".$e->getMessage());

            return null;
        }
    }

    private function say(string $msg): void
    {
        if ($this->log) {
            ($this->log)("  [{$this->def['code']}] {$msg}");
        }
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 400);
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% Synthetic demo document – not a real record\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    // ------------------------------------------------------------------ tenant & organisation

    private function provision(): void
    {
        $d = $this->def;
        $this->tenant = app(TenantProvisioner::class)->provision([
            'code' => $d['code'], 'name' => $d['name'], 'legal_name' => $d['legal_name'], 'city' => $d['city'],
            'registration_no' => 'DEMO-SECP-'.strtoupper($d['code']).'-0001', 'license_ref' => 'DEMO NBFC licence '.strtoupper($d['code']).'/2026 (fictional)',
            'ntn' => 'DEMO-'.mt_rand(1000000, 9999999), 'is_demo' => true, 'primary_color' => $d['color'], 'tagline' => $d['tagline'], 'tagline_ur' => $d['tagline_ur'],
            'primary_contact_name' => 'Demo Operations Head', 'primary_contact_email' => 'ops@'.$d['code'].'.demo', 'primary_contact_phone' => '0300-0000'.mt_rand(100, 999),
            'support_phone' => '0800-'.mt_rand(10000, 99999).' (demo)', 'support_email' => 'grievance@'.$d['code'].'.demo', 'address' => $d['city'].' head office (demo address)',
            'wallet_safeguarding_model' => $d['code'] === 'amcl' ? 'platform_pooled' : 'tenant_safeguarded',
            'logo_path' => \App\Support\TenantLogo::dataUri($d['initials'] ?? mb_substr($d['name'], 0, 2), $d['color'], $d['shape'] ?? 'rounded'),
        ], ['name' => $d['name'].' Administrator', 'email' => 'admin@'.$d['code'].'.demo', 'password' => DemoCatalog::PASSWORD]);
    }

    private function organisation(): void
    {
        foreach ($this->def['regions'] as [$rc, $rn, $areas]) {
            $region = Region::create(['code' => $rc, 'name' => $rn]);
            foreach ($areas as [$ac, $an, $branches]) {
                $area = $region->areas()->create(['code' => $ac, 'name' => $an.' Area']);
                foreach ($branches as $bc => $bn) {
                    $b = $area->branches()->create(['code' => $bc, 'name' => $bn, 'city' => $an, 'address' => $bn.' (demo address)', 'phone' => '042-'.mt_rand(3000000, 3999999)]);
                    CostCenter::create(['code' => 'CC-'.$bc, 'name' => $bn.' branch', 'business_line' => 'lending', 'branch_id' => $b->id]);
                }
            }
        }
    }

    private function users(): void
    {
        $branches = Branch::where('is_head_office', false)->pluck('id')->all();
        $ho = Branch::where('is_head_office', true)->value('id');
        $dept = Department::pluck('id', 'code');
        $deptFor = ['loan_officer' => 'OPS', 'branch_manager' => 'OPS', 'cro' => 'CRD', 'manager_operations' => 'OPS', 'compliance_officer' => 'CMP', 'risk_officer' => 'CRD',
            'disbursement_approver' => 'OPS', 'credit_analyst' => 'CRD', 'approval_committee' => 'CRD', 'visit_officer' => 'OPS', 'recovery_agent' => 'REC',
            'audit_officer' => 'CMP', 'finance_officer' => 'FIN', 'accountant' => 'FIN', 'customer_support' => 'OPS', 'tenant_admin' => 'IT'];
        $admin = User::where('email', 'admin@'.$this->def['code'].'.demo')->first();
        $this->staff['tenant_admin'] = [$admin];
        $n = 1;
        foreach (DemoCatalog::STAFF as $role => [$label, $count]) {
            $perBranch = $count === 'branch';
            $count = $perBranch ? count($branches) : $count;
            for ($i = 1; $i <= $count; $i++) {
                if ($role === 'tenant_admin') {
                    continue;
                }
                $male = mt_rand(0, 2) > 0;
                $name = $this->pick($male ? DemoCatalog::MALE : DemoCatalog::FEMALE).' '.$this->pick(DemoCatalog::SURNAMES);
                $branch = $perBranch ? $branches[$i - 1] : $ho;
                $emp = Employee::create(['employee_code' => 'E'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT), 'name' => $name, 'designation' => $label,
                    'branch_id' => $branch, 'department_id' => $dept[$deptFor[$role]] ?? null, 'phone' => '0300'.mt_rand(1000000, 9999999),
                    'email' => $role.($count > 1 ? $i : '').'@'.$this->def['code'].'.demo', 'joined_on' => $this->today->copy()->subMonths(mt_rand(14, 60)),
                    'basic_salary' => (string) (mt_rand(6, 25) * 10000), 'allowances' => (string) (mt_rand(1, 5) * 5000), 'bank_iban' => 'PK00DEMO'.mt_rand(1000000000, 9999999999)]);
                $u = new User(['name' => $name, 'email' => $emp->email, 'mobile' => '0333'.str_pad((string) (crc32($emp->email) % 10000000), 7, '0', STR_PAD_LEFT), 'designation' => $label]);
                $u->forceFill(['tenant_id' => $this->tenant->id, 'type' => UserType::Staff, 'status' => UserStatus::Active, 'password' => DemoCatalog::PASSWORD,
                    'branch_id' => $branch, 'department_id' => $emp->department_id, 'employee_id' => $emp->id, 'password_changed_at' => now(), 'last_login_at' => now()])->save();
                $u->roles()->attach(Role::where('tenant_id', $this->tenant->id)->where('key', $role)->value('id'), ['assigned_at' => now(), 'assigned_by' => $admin->id]);
                $this->staff[$role][] = $u;
            }
        }
        // A branch manager who also approves (multiple roles per user, LMS §1.2)
        $this->user('branch_manager')->roles()->attach(Role::where('tenant_id', $this->tenant->id)->where('key', 'approval_committee')->value('id'));
        // One suspended and one locked user for the user-management demo
        $this->extraUser('Former Officer (suspended)', 'suspended.officer', 'loan_officer', UserStatus::Suspended);
        $this->extraUser('Locked Analyst (locked)', 'locked.analyst', 'credit_analyst', UserStatus::Locked);
    }

    private function extraUser(string $name, string $local, string $role, UserStatus $status): void
    {
        $u = new User(['name' => $name, 'email' => $local.'@'.$this->def['code'].'.demo', 'designation' => 'Demo']);
        $u->forceFill(['tenant_id' => $this->tenant->id, 'type' => UserType::Staff, 'status' => $status, 'password' => DemoCatalog::PASSWORD,
            'branch_id' => Branch::where('is_head_office', false)->value('id'), 'failed_login_attempts' => $status === UserStatus::Locked ? 5 : 0])->save();
        $u->roles()->attach(Role::where('tenant_id', $this->tenant->id)->where('key', $role)->value('id'));
    }

    private function configuration(): void
    {
        $year = (int) $this->today->format('Y');
        foreach ([[$year - 1, 12, 25, 'Quaid-e-Azam Day'], [$year, 2, 5, 'Kashmir Solidarity Day'], [$year, 3, 23, 'Pakistan Day'], [$year, 5, 1, 'Labour Day'],
            [$year, 8, 14, 'Independence Day'], [$year, 11, 9, 'Iqbal Day'], [$year, 12, 25, 'Quaid-e-Azam Day'], [$year + 1, 2, 5, 'Kashmir Solidarity Day']] as [$y, $m, $dd, $name]) {
            Holiday::firstOrCreate(['date' => Carbon::create($y, $m, $dd)->toDateString()], ['name' => $name]);
        }
        $faqs = [
            ['Is the financing Shariah-compliant?', 'کیا فنانسنگ شریعہ کے مطابق ہے؟', 'Yes. Products use Murabaha, Ijarah or Musharakah structures approved by our Shariah advisor. No interest is charged; late payment amounts go to charity.', 'جی ہاں، تمام مصنوعات شریعہ بورڈ کی منظوری سے ہیں۔'],
            ['Can I cancel after signing?', 'کیا دستخط کے بعد منسوخ کر سکتا ہوں؟', 'Yes – within the 24-hour cooling-off period you can cancel without any charge.', 'جی ہاں، 24 گھنٹے کے اندر بغیر کسی چارج کے۔'],
            ['How do I pay instalments?', 'قسط کیسے ادا کریں؟', 'From your wallet, through Raast from any bank app, Merchant QR / IBAN alias, 1Bill at any bank, or Easypaisa / JazzCash.', 'والیٹ، راست، 1بل، ایزی پیسہ یا جاز کیش سے۔'],
            ['Is my data safe?', 'کیا میرا ڈیٹا محفوظ ہے؟', 'Your data is stored in Pakistan, encrypted, and the app never accesses your contacts or gallery.', 'آپ کا ڈیٹا پاکستان میں محفوظ ہے۔'],
        ];
        foreach ($faqs as $i => [$t, $tu, $b, $bu]) {
            ContentItem::create(['type' => 'faq', 'title' => $t, 'title_ur' => $tu, 'body' => $b, 'body_ur' => $bu, 'sort' => $i]);
        }
        ContentItem::create(['type' => 'banner', 'title' => 'Own your motorbike from PKR 6,000/month*', 'title_ur' => 'صرف 6,000 روپے ماہانہ میں موٹر بائیک', 'body' => '*Indicative. See KFS for final terms.']);
        ContentItem::create(['type' => 'notice', 'title' => 'Branches closed on public holidays', 'body' => 'Digital services remain available 24/7.']);
    }

    private function catalogue(): void
    {
        $svc = app(ProductService::class);
        $admin = $this->user('tenant_admin');
        foreach (DemoCatalog::products() as $i => [$code, $name, $ur, $cat, $structure, $terms, $charges, $docs, $vcat, $desc]) {
            $p = $svc->create(['code' => $code, 'name' => $name, 'name_ur' => $ur, 'category' => $cat, 'shariah_structure' => $structure, 'description' => $desc,
                'description_ur' => $ur.' – شریعہ کے مطابق فنانسنگ', 'shariah_board_approved' => true, 'shariah_approval_ref' => 'SB-DEMO-'.$code.'-2026', 'sort' => $i,
                'loan_category_id' => \App\Models\LoanCategory::where('code', match ($cat) { 'motorbike', 'rickshaw', 'scooty' => 'VEH', 'solar' => 'ENR', 'school_fees' => 'EDU', 'karobar_sme' => 'SME', default => 'CON' })->value('id')],
                $terms + ['late_charge_per_day' => 50, 'grace_days' => 3, 'max_dbr' => 45], $charges, $docs, $admin);
            $p->branches()->sync(Branch::pluck('id'));
            $this->products[$code] = $p;
        }
        // A newer version for one product to demonstrate versioning (existing loans keep v1 terms)
        $svc->newVersion($this->products['MLT'], ['profit_rate' => 19.5, 'max_amount' => 400000], [], [], $admin);

        $wallets = app(WalletService::class);
        $pendingIndex = count(DemoCatalog::VENDORS) - 1;
        foreach (DemoCatalog::VENDORS as $i => [$name, $cat, $items, $merchant]) {
            $v = Vendor::create([
                'code' => 'VND-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'business_name' => $name, 'owner_name' => $this->pick(DemoCatalog::MALE).' '.$this->pick(DemoCatalog::SURNAMES),
                'cnic' => '99'.mt_rand(100, 999).'-'.mt_rand(1000000, 9999999).'-'.mt_rand(0, 9), 'ntn' => 'DEMO-'.mt_rand(100000, 999999), 'category' => $cat,
                'kind' => $cat === 'operational' ? 'operational' : 'asset', 'is_merchant' => $merchant, 'merchant_code' => $merchant ? 'MRC'.strtoupper($this->def['code']).($i + 1) : null,
                'phone' => '0321'.mt_rand(1000000, 9999999), 'email' => 'vendor'.($i + 1).'@'.$this->def['code'].'.demo', 'address' => $this->def['city'].' market (demo)', 'city' => $this->def['city'],
                'bank_name' => $this->pick(['Meezan Bank', 'HBL', 'UBL', 'MCB', 'Bank Alfalah']), 'iban' => 'PK'.mt_rand(10, 99).'DEMO'.str_pad((string) mt_rand(0, 99999999), 16, '0', STR_PAD_LEFT),
                'account_title' => $name, 'preferred_payment_method' => $cat === 'operational' ? 'bank_transfer' : 'raast', 'source' => $i === $pendingIndex ? 'web_request' : 'internal',
            ]);
            $v->forceFill(['status' => $i === $pendingIndex ? 'pending' : 'active', 'approved_by' => $i === $pendingIndex ? null : $admin->id, 'approved_at' => $i === $pendingIndex ? null : now()])->save();
            $factor = 1 + (crc32($this->def['code']) % 9) * 0.025; // each institution negotiates slightly different dealer prices
            foreach ($items as [$item, $price, $itemCategory]) {
                $v->items()->create(['name' => $item, 'price' => (string) (round($price * $factor / 500) * 500), 'product_category' => $itemCategory, 'sku' => strtoupper(Str::slug($item, ''))]);
            }
            if ($merchant && in_array($i, [5, 7], true)) { // internal merchants (have wallets); others settle externally via Raast
                $w = $wallets->open($v);
                $wallets->activate($w);
            }
            app(DocumentStore::class)->store($this->fakePdf('registration.pdf'), $v, DocumentType::where('code', 'VENDOR_REG')->first(), $admin, null, $this->today->copy()->addYear()->toDateString());
            $this->vendors[$cat][] = $v;
        }
        // Vendor portal login for the first dealer
        $vu = new User(['name' => 'Crescent Motors Desk', 'email' => 'vendor@'.$this->def['code'].'.demo']);
        $vu->forceFill(['tenant_id' => $this->tenant->id, 'type' => UserType::Vendor, 'status' => UserStatus::Active, 'password' => DemoCatalog::PASSWORD,
            'vendor_id' => $this->vendors['motorbike'][0]->id, 'password_changed_at' => now()])->save();
    }

    private function funding(): void
    {
        $ops = app(FinanceOperations::class);
        $admin = $this->user('tenant_admin');
        $funds = [
            ['OWN', 'Own equity', 'own_equity', null, 60000000, null, null],
            ['MDR', 'Mudarabah Investor Pool (demo)', 'investor', 'Demo Islamic Investors', 40000000, 'mudarabah', 60],
            ['BNK', 'Bank Musharakah Line (demo)', 'bank_line', 'Demo Sponsor Bank', 30000000, 'musharakah', null],
        ];
        foreach ($funds as [$code, $name, $type, $provider, $amount, $structure, $share]) {
            $f = Fund::create(['code' => $code, 'name' => $name, 'type' => $type, 'provider_name' => $provider, 'committed_amount' => (string) $amount,
                'structure' => $structure, 'investor_profit_share_pct' => $share, 'start_date' => now()->toDateString(), 'end_date' => now()->addYears(3)->toDateString()]);
            $f->branches()->sync(Branch::pluck('id'));
            $ops->recordFundReceipt($f, (string) ($amount / 2), $admin, now());
        }
        // Move working capital into the disbursement account (system posting of the fund-transfer rule)
        app(GeneralLedger::class)->postEvent('fund_transfer', Fund::where('code', 'OWN')->first(), ['amount' => '45000000'],
            'Opening transfer: operating → disbursement account', now(), idempotencyKey: 'opening-transfer', module: 'treasury');
        $this->as($this->user('accountant'), fn ($u) => $ops->acquireAsset(['name' => 'Head office IT equipment', 'category' => 'it_equipment', 'branch_id' => Branch::where('is_head_office', true)->value('id'),
            'acquired_on' => now()->toDateString(), 'cost' => '1800000', 'salvage_value' => '100000', 'useful_life_months' => 36], $u));
        $this->as($this->user('accountant'), fn ($u) => $ops->acquireAsset(['name' => 'Branch furniture & fixtures', 'category' => 'furniture', 'branch_id' => Branch::where('is_head_office', false)->value('id'),
            'acquired_on' => now()->toDateString(), 'cost' => '950000', 'salvage_value' => '50000', 'useful_life_months' => 60], $u));
    }

    // ------------------------------------------------------------------ customers & KYC

    private function customersAndKyc(): void
    {
        $onb = app(OnboardingService::class);
        $store = app(DocumentStore::class);
        $branches = Branch::where('is_head_office', false)->pluck('id')->all();
        $types = DocumentType::pluck('id', 'code');
        for ($i = 0; $i < 30; $i++) {
            $female = $i % 3 === 1;
            $first = $this->pick($female ? DemoCatalog::FEMALE : DemoCatalog::MALE);
            $last = $this->pick(DemoCatalog::SURNAMES);
            $name = "{$first} {$last}";
            // KYC outcome is driven by the CNIC last digit (see docs/API_SIMULATION.md)
            $digit = match ($i) { 2 => 7, 3 => 8, 4 => 9, 5 => 7, default => $i % 7 };
            // Customers join before any of their financing history starts (M21).
            $joined = $this->today->copy()->subMonths(13)->addDays(1 + $i * 3)->setTime(10 + $i % 6, 15);
            if ($i >= 26) {
                $joined = $this->today->copy()->subDays(20 - ($i - 26) * 4)->setTime(11, 0);
            }
            $user = $this->at($joined, function () use ($onb, $store, $types, $i, $name, $first, $last, $female, $digit, $branches, $joined) {
                $mobile = '03'.str_pad((string) (100000000 + crc32($this->def['code'].$i) % 899999999), 9, '0', STR_PAD_LEFT);
                $u = $onb->register($this->tenant, ['name' => $name, 'mobile' => $mobile, 'email' => 'customer'.($i + 1).'@'.$this->def['code'].'.demo',
                    'password' => DemoCatalog::PASSWORD, 'locale' => $i % 5 === 0 ? 'ur' : 'en']);
                $onb->recordConsents($u->customer, ['terms_of_use' => 1, 'privacy_policy' => 1], 'en', '10.0.0.'.$i, 'Demo seeder');
                if ($i === 0) {
                    return $u; // registered, mobile not yet verified
                }
                $onb->markMobileVerified($u);
                $c = $u->customer;
                [$emp, $occ, $employer] = DemoCatalog::OCCUPATIONS[$i % count(DemoCatalog::OCCUPATIONS)];
                $income = (string) (($i >= 8 ? 95000 : 45000) + (crc32($name) % 12) * 10000); // borrowers (#8+) earn enough to pass the DBR gate
                $onb->updateProfile($c, [
                    'full_name' => $name, 'father_husband_name' => $this->pick(DemoCatalog::MALE).' '.$last, 'cnic' => '99'.str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT).'-'.mt_rand(1000000, 9999999).'-'.$digit,
                    'cnic_issue_date' => $this->today->copy()->subYears(mt_rand(1, 8))->toDateString(), 'cnic_expiry_date' => match ($i) { 6 => $this->today->copy()->subDays(12)->toDateString(), 7 => $this->today->copy()->addDays(18)->toDateString(), default => $this->today->copy()->addYears(mt_rand(2, 9))->toDateString() },
                    'date_of_birth' => Carbon::create(1975 + ($i * 7) % 26, 1 + $i % 12, 1 + $i % 27)->toDateString(), 'gender' => $female ? 'female' : 'male', 'marital_status' => $i % 4 ? 'married' : 'single',
                    'current_address' => 'House '.(10 + $i).', Street '.($i % 9 + 1).', '.$this->def['city'].' (demo)', 'permanent_address' => 'House '.(10 + $i).', '.$this->def['city'], 'city' => $this->def['city'],
                    'occupation' => $occ, 'employment_type' => $emp, 'employer_name' => $employer, 'business_name' => $emp === 'business' ? $first."'s ".$occ : null, 'business_type' => $emp === 'business' ? $occ : null,
                    'income_source' => $emp === 'salaried' ? 'Salary' : 'Business income', 'monthly_income' => $income, 'other_income' => $i % 4 === 0 ? '10000' : '0',
                    'existing_obligations' => (string) (($i % 3) * 3000),
                    // Deterministic home coordinates near the tenant's city (used for field-visit distance checks).
                    'address_latitude' => round(self::CITY_COORDS[$this->def['city']][0] + (($i * 37) % 41 - 20) / 1000, 7),
                    'address_longitude' => round(self::CITY_COORDS[$this->def['city']][1] + (($i * 53) % 41 - 20) / 1000, 7), 'branch_id' => $branches[$i % count($branches)], 'assigned_officer_id' => $this->branchUser('loan_officer', $branches[$i % count($branches)])->id,
                ], null, 'documents');
                NextOfKin::create(['customer_id' => $c->id, 'name' => $this->pick(DemoCatalog::MALE).' '.$last, 'relation' => $this->pick(['Brother', 'Father', 'Spouse', 'Sister']), 'mobile' => '0345'.mt_rand(1000000, 9999999), 'address' => $c->current_address]);
                if ($i === 1) {
                    return $u; // profile started, KYC not run
                }
                // Documents and e-KYC follow registration (two hours later), never precede it (M21).
                Carbon::setTestNow($joined->copy()->addHours(2));
                $store->store($this->fakeImage('cnic-front.jpg'), $c, DocumentType::find($types['CNIC_FRONT']), $u, null, $c->cnic_expiry_date?->toDateString());
                $store->store($this->fakeImage('cnic-back.jpg'), $c, DocumentType::find($types['CNIC_BACK']), $u);
                $store->store($this->fakeImage('selfie.jpg'), $c, DocumentType::find($types['SELFIE']), $u);
                $store->store($this->fakePdf('income.pdf'), $c, DocumentType::find($types[$emp === 'salaried' ? 'SALARY_SLIP' : 'BUSINESS_PROOF']), $u);
                $onb->recordConsents($c, ['nadra_verification' => 1, 'credit_bureau' => 1, 'data_sharing' => 1, 'esignature' => 1, 'communications' => $i % 4 !== 0], $c->preferred_locale, '10.0.0.'.$i, 'Demo seeder');
                $onb->runEkyc($c->fresh());
                $c->linkedBankAccounts()->create(['bank_name' => $this->pick(['Meezan Bank', 'HBL', 'UBL', 'MCB', 'Allied Bank']), 'iban' => 'PK'.mt_rand(10, 99).'DEMO'.str_pad((string) mt_rand(0, 99999999), 16, '0', STR_PAD_LEFT),
                    'account_title' => strtoupper($name), 'is_verified' => true, 'is_default' => true]);

                return $u;
            });
            $this->customers[$i] = $user->fresh();
            $this->joinedAt[$user->id] = $joined->copy();
        }
        // Manual review decisions: #5 approved by compliance after review (passes); #2 left pending; #4 AML match pending compliance.
        $this->at($this->joinedAt[$this->customers[5]->id]->copy()->addDays(2)->setTime(12, 0), fn () => $this->as($this->actor('kyc.review', ['compliance_officer']), fn ($u) => app(OnboardingService::class)
            ->reviewKyc($this->customers[5]->customer->fresh(), $u, true, 'Name spelling variant confirmed against CNIC copy; face match acceptable on manual comparison.')));
        foreach ($this->customers as $u) {
            if ($w = $u->customer?->wallet()->first()) {
                if ($w->isActive()) {
                    app(WalletService::class)->setPin($w, '4826');
                }
            }
        }
    }

    // ------------------------------------------------------------------ lending

    private function lending(): void
    {
        $plan = [
            // [customer idx, product, months ago start, target, pattern]
            [8, 'MBS', 11, 'disbursed', 'closed_noc'],
            [9, 'HAP', 10, 'disbursed', 'closed'],
            [10, 'ARK', 10, 'disbursed', 'severe'],
            [11, 'MBS', 9, 'disbursed', 'good'],
            [12, 'SLR', 9, 'disbursed', 'good'],
            [13, 'KBS', 8, 'disbursed', 'late'],
            [14, 'MLT', 7, 'disbursed', 'partial'],
            [15, 'SSC', 6, 'disbursed', 'good'],
            [16, 'EDU', 5, 'disbursed', 'good_wallet'],
            [17, 'MBS', 4, 'disbursed', 'late'],
            [18, 'HAP', 3, 'disbursed', 'good'],
            [19, 'ARK', 2, 'disbursed', 'good'],
            [20, 'MLT', 1, 'disbursed', 'fresh'],
            [21, 'MBS', 1, 'disbursement_failed', null],
            [22, 'SSC', 1, 'ready', null],
            [23, 'HAP', 0, 'cooling_off', null],
            [24, 'MLT', 0, 'approved', null],
            [25, 'MBS', 0, 'kfs_acknowledged', null],
            [26, 'KBS', 0, 'pending_approval:1', null],
            [27, 'HAP', 0, 'pending_approval:3', null],
            [28, 'MBS', 0, 'pending_approval:5', null],
            [29, 'SSC', 0, 'returned', null],
            [11, 'HAP', 0, 'under_review', null],
            [12, 'MLT', 0, 'submitted', null],
            [15, 'EDU', 0, 'draft', null],
            [18, 'MLT', 1, 'rejected_approval', null],
            [9, 'SSC', 2, 'rejected_review', null],
            [16, 'HAP', 2, 'withdrawn', null],
            [19, 'SSC', 1, 'cancelled', null],
        ];
        foreach ($plan as $n => [$ci, $code, $monthsAgo, $target, $pattern]) {
            $start = $this->today->copy()->subMonths($monthsAgo)->subDays($monthsAgo === 0 ? 12 - min($n % 10, 9) : 6)->setTime(10, 30);
            try {
                $this->driveApplication($this->customers[$ci], $this->products[$code], $start, $target, $pattern, $n);
            } catch (\Throwable $e) {
                $this->say("application #{$n} ({$code} → {$target}) skipped: ".$e->getMessage());
            }
        }
    }

    private function driveApplication(User $cu, Product $product, Carbon $start, string $target, ?string $pattern, int $n): void
    {
        $apps = app(ApplicationService::class);
        $uw = app(UnderwritingService::class);
        $customer = $cu->customer->fresh();
        $v = $product->currentVersion;
        // Nothing in a customer's financing history may precede their registration (M21).
        if (isset($this->joinedAt[$cu->id])) {
            $start = $start->copy()->max($this->joinedAt[$cu->id]->copy()->addDay()->setTime(10, 30));
        }
        $branchId = $customer->branch_id;
        $bm = $this->branchUser('branch_manager', $branchId);
        $data = ['tenure' => min($v->max_tenure, max($v->min_tenure, [6, 9, 12, 12, 18][$n % 5])), 'purpose' => 'Demo purpose: '.$product->name];
        if ($v->requires_vendor) {
            // Dealer and item must match the product's asset category (no scooty financing for a motorbike, M4/M21).
            $cat = Vendor::categoriesForProduct($product)[0] ?? 'motorbike';
            $candidates = collect($this->vendors[$cat])->filter(fn ($vd) => VendorItem::where('vendor_id', $vd->id)->where('product_category', $product->category->value)->exists())->values();
            $vendor = $candidates[$n % max(1, $candidates->count())] ?? $this->vendors[$cat][0];
            $items = VendorItem::where('vendor_id', $vendor->id)->where('product_category', $product->category->value);
            $item = (clone $items)->where('price', '<=', $v->max_amount * 1.1)->orderBy('price')->first() ?? $items->first();
            $data['vendor_item_id'] = $item->id;
            if ((float) $item->price > 300000) {
                $data['tenure'] = $v->max_tenure; // larger assets are financed over the longest tenor
            }
        } else {
            $data['requested_amount'] = (string) min((float) $v->max_amount, max((float) $v->min_amount, 100000 + ($n % 3) * 25000));
            $data['tenure'] = min($v->max_tenure, 24);
            if ($product->code === 'EDU') {
                $data['requested_amount'] = '60000';
            }
        }

        $app = $this->at($start, function () use ($apps, $customer, $product, $data, $cu, $n, $v) {
            $channelUser = $n % 4 === 0 ? $this->branchUser('loan_officer', $customer->branch_id) : $cu;
            $app = $this->as($channelUser, fn ($u) => $apps->createDraft($customer, $product, $data + ['fund_id' => Fund::where('code', $n % 3 === 0 ? 'MDR' : 'OWN')->value('id')], $u, $u->isStaff() ? 'branch' : 'web'));
            foreach ($app->productVersion->requiredDocuments()->with('documentType')->get() as $req) {
                if (in_array($req->documentType->code, ['CNIC_FRONT', 'CNIC_BACK', 'SALARY_SLIP', 'BUSINESS_PROOF'], true)
                    && $customer->documents()->where('document_type_id', $req->document_type_id)->exists()) {
                    continue;
                }
                app(DocumentStore::class)->store($req->documentType->code === 'UTILITY_BILL' ? $this->fakeImage('bill.jpg') : $this->fakePdf(strtolower($req->documentType->code).'.pdf'),
                    $app, $req->documentType, $cu);
            }
            for ($g = 0; $g < $v->guarantors_required; $g++) {
                $this->as($cu, fn ($u) => $apps->addGuarantor($app, ['name' => $this->pick(DemoCatalog::MALE).' '.$this->pick(DemoCatalog::SURNAMES), 'cnic' => $this->cleanGuarantorCnic($n * 10 + $g),
                    'mobile' => '0312'.mt_rand(1000000, 9999999), 'relation' => 'Cousin', 'occupation' => 'Shopkeeper', 'monthly_income' => '80000', 'address' => $this->def['city'], 'consent' => true], $u));
            }

            return $app;
        });
        if ($target === 'draft') {
            return;
        }
        $this->at($start->copy()->addHours(2), fn () => $this->as($app->creator, fn ($u) => $apps->submit($app->fresh(), $u)));
        if ($target === 'submitted') {
            return;
        }
        if ($target === 'withdrawn') {
            $this->at($start->copy()->addDay(), fn () => $this->as($cu, fn ($u) => $apps->withdraw($app->fresh(), $u, 'Purchased the item with family support.')));

            return;
        }
        // Review: bureau, visit, assessment
        $this->at($start->copy()->addDay()->setTime(10, 0), function () use ($apps, $uw, $app, $bm) {
            $this->as($bm, fn ($u) => $apps->startReview($app->fresh(), $u));
            $this->as($this->user('credit_analyst'), function ($u) use ($uw, $app) {
                $uw->requestBureau($app->fresh(), $u);
                foreach ($app->guarantors as $g) {
                    $uw->requestBureau($app->fresh(), $u, 'guarantor', $g);
                }
            });
        });
        if ($target === 'under_review') {
            return;
        }
        $visit = $this->at($start->copy()->addDays(2)->setTime(11, 0), function () use ($uw, $app, $n, $bm, $branchId) {
            $officer = $this->branchUser('visit_officer', $branchId);
            $visit = $this->as($bm, fn ($u) => $uw->scheduleVisit($app->fresh(), $u, $n % 3 === 0 ? 'business' : 'residence', $officer, now()->toDateString()));
            $this->as($officer, function ($u) use ($uw, $visit, $n, $app) {
                // Officer checks in on the scheduled date within ~60 m of the customer's address with a good GPS fix.
                $home = $app->customer()->first();
                $lat = (float) ($home->address_latitude ?? 31.52) + 0.0004;
                $lng = (float) ($home->address_longitude ?? 74.35) + 0.0003;
                $uw->checkIn($visit, $u, $lat, $lng, 18.0 + $n % 20);
                app(DocumentStore::class)->store($this->fakeImage('visit-proof.jpg'), $visit, DocumentType::where('code', 'VISIT_PHOTO')->first(), $u);
                $uw->submitVisit($visit->fresh(), $u, ['recommendation' => $n % 9 === 8 ? 'follow_up' : 'positive', 'address_match' => true, 'living_conditions' => 'satisfactory',
                    'neighbor_verification' => 'Neighbour confirmed residence for 3+ years.', 'remarks' => 'Applicant available; documents sighted.',
                    'checklist' => ['address_verified' => true, 'business_seen' => $n % 3 === 0, 'family_present' => true]]);
            });

            return $visit;
        });
        $this->at($start->copy()->addDays(2)->setTime(15, 0), fn () => $this->as($this->user('credit_analyst'), fn ($u) => $uw->assess($app->fresh(), $u, ['household_expenses' => '20000', 'notes' => 'Income verified from documents.'])));

        if ($target === 'rejected_review') {
            $this->at($start->copy()->addDays(3), fn () => $this->as($this->user('cro'), fn ($u) => $apps->reject($app->fresh(), $u, 'insufficient_income', 'Declared business income could not be verified from bank statements.')));

            return;
        }
        $this->at($start->copy()->addDays(3)->setTime(9, 30), fn () => $this->as($bm, fn ($u) => $apps->sendForApproval($app->fresh(), $u)));

        $engine = app(ApprovalEngine::class);
        $stageUsers = ['cro', 'manager_operations', 'compliance_officer', 'risk_officer', 'disbursement_approver', 'approval_committee'];
        $stopAt = str_starts_with($target, 'pending_approval:') ? (int) substr($target, 17) : 99;
        $t = $start->copy()->addDays(3)->setTime(11, 0);
        for ($s = 1; $s <= 6; $s++) {
            $req = ApprovalRequest::where('approvable_type', $app->getMorphClass())->where('approvable_id', $app->id)->latest('id')->first();
            if ($req->status->value !== 'pending' || $s >= $stopAt) {
                break;
            }
            $role = $req->currentStage->role->key;
            $actor = $this->user($role);
            if ($target === 'rejected_approval' && $s === 4) {
                $this->at($t, fn () => $this->as($actor, fn ($u) => $engine->reject($req, $u, 'Risk rating high for requested tenor; DBR close to policy limit.')));

                return;
            }
            if ($target === 'returned' && $s === 2) {
                $this->at($t, fn () => $this->as($actor, fn ($u) => $engine->returnForCorrection($req, $u, 'Please upload a clearer copy of the vendor quotation and confirm the asset model.')));
                $app->objections()->latest('id')->first()?->update(['visible_to_customer' => true]);

                return;
            }
            $this->at($t, fn () => $this->as($actor, fn ($u) => $engine->approve($req, $u, $role === 'compliance_officer' ? 'KYC, AML and consents verified.' : 'Recommended.')));
            $t->addHours(5);
        }
        if (str_starts_with($target, 'pending_approval')) {
            return;
        }
        $app->refresh();
        if ($app->status !== ApplicationStatus::Approved) {
            $this->say("application {$app->application_no} not approved ({$app->status->value})");

            return;
        }
        if ($target === 'approved') {
            return;
        }
        $ag = app(AgreementService::class);
        $t = $t->copy()->addDay()->setTime(18, 0);
        $murabaha = app(\App\Services\Lending\MurabahaService::class);
        if ($murabaha->requiresAssetOwnership($app->fresh())) {
            // Institution buys the asset first: vendor invoice in its name (maker), ownership confirmed by a second user (checker).
            $this->at($t->copy()->subHours(4), function () use ($murabaha, $app, $n) {
                $murabaha->recordVendorInvoice($app->fresh(), $this->fakePdf('vendor-invoice.pdf'), $this->branchUser('loan_officer', $app->branch_id), 'INV-'.strtoupper($this->def['code']).'-'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT));
                $murabaha->confirmOwnership($app->fresh(), $this->user('disbursement_approver', 0));
            });
        }
        $this->at($t, function () use ($ag, $app, $cu) {
            $ag->acknowledgeKfs($app->fresh(), $cu, $cu->locale ?? 'en', '39.32.10.'.($app->id % 200), 'Mozilla/5.0 (Linux; Android 14) Demo');
        });
        if ($target === 'kfs_acknowledged') {
            return;
        }
        $this->at($t->copy()->addMinutes(10), function () use ($ag, $app, $cu) {
            foreach ($app->signatureRequests()->get() as $sr) {
                if ($sr->signing_method === 'digital') {
                    $signer = $sr->signer_type === 'applicant' ? $cu : $this->user('disbursement_approver');
                    $this->as($signer, fn ($u) => $ag->sign($sr, $u, '39.32.10.5', 'Mozilla/5.0 (Linux; Android 14) Demo'));
                }
            }
        });
        $this->at($t->copy()->addHours(20), function () use ($ag, $app) {
            foreach ($app->signatureRequests()->where('status', 'wet_ink_pending')->get() as $sr) {
                $this->as($this->user('disbursement_approver'), fn ($u) => $ag->confirmWetInk($sr, $u, 'Stamped DPN / attested affidavit received at branch.'));
            }
        });
        if ($target === 'cooling_off') {
            $app->forceFill(['cooling_off_ends_at' => now()->addHours(15)])->save(); // keep the timer live for the demo

            return;
        }
        if ($target === 'cancelled') {
            $this->at($t->copy()->addHours(22), fn () => $this->as($cu, fn ($u) => $ag->cancelDuringCoolingOff($app->fresh(), $u, 'Decided to buy later.')));

            return;
        }
        $disbAt = $t->copy()->addDays(3)->setTime(11, 0); // after the 24h cooling-off that starts when the last wet-ink document is received
        $this->at($disbAt, fn () => $ag->completeCoolingOff($app->fresh()));
        if ($target === 'ready') {
            return;
        }
        $ds = app(DisbursementService::class);
        if ($target === 'disbursement_failed') {
            $this->at($disbAt->copy()->addHour(), fn () => $this->as($this->user('disbursement_approver', 0), fn ($u) => $ds->initiate($app->fresh(), $u, 'failure', $this->user('disbursement_approver', 1)->fresh(['roles']))));

            return;
        }
        $d = $this->at($disbAt->copy()->addHour(), fn () => $this->as($this->user('disbursement_approver', 0), fn ($u) => $ds->initiate($app->fresh(), $u, 'success', $this->user('disbursement_approver', 1)->fresh(['roles']))));
        $loan = $d->fresh()->loanAccount;
        if ($loan) {
            $loan->update(['recovery_agent_id' => null]);
            $this->repaymentHistory($loan, $pattern, $cu, $n);
        }
    }

    private function repaymentHistory(LoanAccount $loan, ?string $pattern, User $cu, int $n): void
    {
        $repay = app(RepaymentService::class);
        $wallets = app(WalletService::class);
        $channels = ['raast', 'wallet', 'cash', 'easypaisa', 'one_bill', 'jazzcash', 'qr_iban'];
        $instalments = $loan->instalments()->get();
        $paidCount = 0;
        foreach ($instalments as $k => $inst) {
            $due = $inst->due_date->copy()->setTime(9 + $k % 5, 20);
            if ($due->gte($this->today)) {
                break;
            }
            $skip = match ($pattern) {
                'severe' => $k >= 2,
                'late' => $k >= count(array_filter($instalments->all(), fn ($x) => $x->due_date->lt($this->today))) - 2,
                default => false,
            };
            if ($skip) {
                continue;
            }
            $payLate = in_array($pattern, ['good', 'good_wallet'], true) && $k === 2 && $due->copy()->addDays(8)->lt($this->today);
            if ($payLate) {
                // Paid 8 days late: the daily run accrues the late-payment amount (routed to charity when collected)
                $due = $due->copy()->addDays(8);
                $this->at($due->copy()->setTime(6, 0), fn () => app(ServicingService::class)->runDelinquency());
            }
            $amount = Money::sub(Money::add($inst->fresh()->total_due, $inst->fresh()->late_charge_due), $inst->fresh()->total_paid);
            $isLastDue = $instalments->filter(fn ($x) => $x->due_date->lt($this->today))->last()?->id === $inst->id;
            if ($pattern === 'partial' && $isLastDue) {
                $amount = Money::div($amount, 2);
            }
            $channel = $pattern === 'good_wallet' ? 'wallet' : $channels[($k + $n) % count($channels)];
            $payAt = $payLate ? $due : $due->copy()->subDays(($k + $n) % 3);
            $this->payOnce($loan, $cu, $amount, $channel, $payAt, $k);
            $paidCount++;

            if (in_array($pattern, ['closed', 'closed_noc'], true) && $k === 4) {
                // early settlement of the remaining balance
                $remaining = $loan->fresh()->totalOutstanding();
                $this->at($payAt->copy()->addDays(10), fn () => $this->as($this->user('accountant'), fn ($u) => $repay->recordManual($loan->fresh(), $remaining, 'bank_transfer', 'IBFT-'.strtoupper(Str::random(8)), $u)));
                if ($pattern === 'closed_noc') {
                    $this->at($payAt->copy()->addDays(12), fn () => $this->as($this->actor('loans.issue_noc', ['branch_manager', 'disbursement_approver']), fn ($u) => app(ServicingService::class)->issueNoc($loan->fresh(), $u)));
                }
                break;
            }
        }
        // F6: a few good payers pay their next instalment early this month, so month-to-date income is visible.
        if (in_array($pattern, ['good', 'good_wallet'], true) && $n % 2 === 1 && ($next = $loan->fresh()->nextInstalment())
            && $next->due_date->lte($this->today->copy()->addDays(45)) && $this->today->day > 1) {
            $amount = Money::sub(Money::add($next->total_due, $next->late_charge_due), $next->total_paid);
            $this->payOnce($loan, $cu, $amount, 'raast', $this->today->copy()->setTime(9, 15), 99);
        }
        // One failed collection attempt and one pending (unconfirmed) Raast collection for realism
        if ($pattern === 'late') {
            $this->at($this->today->copy()->subDays(3)->setTime(13, 0), fn () => $this->as($cu, fn ($u) => $repay->initiateCollection($loan->fresh(), '5000', 'raast', $this->key('fail'), $u, 'failure')));
            $this->at($this->today->copy()->subDays(1)->setTime(16, 0), fn () => $this->as($cu, fn ($u) => $repay->initiateCollection($loan->fresh(), '3000', 'jazzcash', $this->key('pend'), $u, 'pending')));
        }
    }

    private function payOnce(LoanAccount $loan, User $cu, string $amount, string $channel, Carbon $at, int $k): void
    {
        $repay = app(RepaymentService::class);
        $wallets = app(WalletService::class);
        $this->at($at, function () use ($repay, $wallets, $loan, $cu, $amount, $channel, $k) {
            if ($channel === 'wallet') {
                $w = $cu->customer->wallet()->first();
                if (! $w?->isActive()) {
                    $channel = 'raast';
                } else {
                    $need = Money::sub($amount, $w->availableBalance());
                    while (Money::isPositive($need)) {
                        $chunk = Money::min($need, '24000');
                        $this->as($cu, fn ($u) => $wallets->topUp($w->fresh(), $chunk, $k % 2 ? 'easypaisa' : 'raast', $this->key('tu'), 'success', $u, 'Meezan Bank'));
                        $need = Money::sub($need, $chunk);
                    }
                    if (Money::lte($amount, $w->fresh()->per_transaction_limit)) {
                        $this->as($cu, fn ($u) => $wallets->payInstalment($w->fresh(), $loan->fresh(), $amount, $this->key('wpi'), '4826', $u));

                        return;
                    }
                    $channel = 'raast';
                }
            }
            if (in_array($channel, ['cash', 'bank_transfer', 'cheque'], true)) {
                $this->as($this->user('accountant'), fn ($u) => $repay->recordManual($loan->fresh(), $amount, $channel, 'RCPT-'.strtoupper(Str::random(6)), $u));

                return;
            }
            $scenario = $k === 1 ? 'duplicate_callback' : 'success';
            $this->as($cu, fn ($u) => $repay->initiateCollection($loan->fresh(), $amount, $channel, $this->key('col'), $u, $scenario));
        });
    }

    // ------------------------------------------------------------------ wallet activity

    private function walletActivity(): void
    {
        $ws = app(WalletService::class);
        $active = collect($this->customers)->filter(fn ($u) => $u->customer?->wallet()->first()?->isActive())->values();
        if ($active->count() < 6) {
            return;
        }
        $merchants = Vendor::where('is_merchant', true)->where('status', 'active')->get();
        foreach ($active as $i => $cu) {
            $base = $this->today->copy()->subDays(60 - $i * 2)->setTime(12, 10);
            $w = fn () => $cu->customer->wallet()->first()->fresh();
            $this->safe('wallet', fn () => $this->at($base, fn () => $this->as($cu, fn ($u) => $ws->topUp($w(), (string) (5000 + ($i % 5) * 4000), ['raast', 'easypaisa', 'jazzcash'][$i % 3], $this->key('w'), 'success', $u, $this->pick(['HBL', 'Meezan Bank', 'UBL'])))));
            $other = $active[($i + 1) % $active->count()];
            $this->safe('wallet', fn () => $this->at($base->copy()->addDays(1), fn () => $this->as($cu, fn ($u) => $ws->transfer($w(), $other->customer->wallet->mobile, (string) (500 + $i * 100), $this->key('t'), '4826', $u, 'Family support'))));
            if ($i % 3 === 0) {
                $this->safe('wallet', fn () => $this->at($base->copy()->addDays(2), fn () => $this->as($cu, fn ($u) => $ws->payBill($w(), $this->pick(array_keys(WalletService::billers())), '0'.(41000000 + $i * 7919), $this->key('b'), '4826', 'success', $u))));
            }
            if ($i % 3 === 1 && $merchants->isNotEmpty()) {
                $m = $merchants[$i % $merchants->count()];
                $this->safe('wallet', fn () => $this->at($base->copy()->addDays(3), fn () => $this->as($cu, fn ($u) => $ws->payMerchant($w(), $m->merchant_code, (string) (300 + $i * 50), $this->key('m'), '4826', 'success', $u))));
            }
            if ($i % 4 === 2) {
                $this->safe('wallet', fn () => $this->at($base->copy()->addDays(4), fn () => $this->as($cu, fn ($u) => $ws->withdraw($w(), '1500', $u->customer->linkedBankAccounts()->first(), $this->key('wd'), '4826', 'success', $u))));
            }
        }
        $c0 = $active[0];
        $w0 = fn () => $c0->customer->wallet()->first()->fresh();
        $at = $this->today->copy()->subDays(2)->setTime(14, 0);
        // Status variety: pending, failed, reversed, duplicate-callback top-ups; failed bill; failed withdrawal; refunded merchant payment; reversed transfer
        $this->safe('wallet', fn () => $this->at($at, fn () => $this->as($c0, fn ($u) => $ws->topUp($w0(), '7000', 'raast', $this->key('p'), 'pending', $u, 'HBL'))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(5), fn () => $this->as($c0, fn ($u) => $ws->topUp($w0(), '4000', 'jazzcash', $this->key('f'), 'failure', $u))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(9), fn () => $this->as($c0, fn ($u) => $ws->topUp($w0(), '3000', 'easypaisa', $this->key('r'), 'reversal', $u))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(12), fn () => $this->as($c0, fn ($u) => $ws->topUp($w0(), '2500', 'raast', $this->key('d'), 'duplicate_callback', $u, 'UBL'))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(15), fn () => $this->as($c0, fn ($u) => $ws->topUp($w0(), '1200', 'raast', $this->key('to'), 'timeout', $u, 'MCB'))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(20), fn () => $this->as($c0, fn ($u) => rescue(fn () => $ws->payBill($w0(), 'SNGPL', '0'.(52000000 + 17), $this->key('bf'), '4826', 'failure', $u), report: false))));
        $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(25), fn () => $this->as($c0, fn ($u) => rescue(fn () => $ws->withdraw($w0(), '1000', $u->customer->linkedBankAccounts()->first(), $this->key('wf'), '4826', 'failure', $u), report: false))));
        $internalMerchant = Vendor::whereHas('wallet')->where('is_merchant', true)->first();
        if ($internalMerchant) {
            $mp = $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(30), fn () => $this->as($c0, fn ($u) => $ws->payMerchant($w0(), $internalMerchant->merchant_code, '800', $this->key('mr'), '4826', null, $u))));
            $mp && $this->safe('wallet', fn () => $this->at($at->copy()->addHours(2), fn () => $this->as($this->user('finance_officer'), fn ($u) => $ws->reverseInternal($mp, 'Goods returned – merchant refund approved (dispute TKT).', $u))));
        }
        $tr = $this->safe('wallet', fn () => $this->at($at->copy()->addMinutes(40), fn () => $this->as($c0, fn ($u) => $ws->transfer($w0(), $active[2]->customer->wallet->mobile, '650', $this->key('tr'), '4826', $u, 'Sent by mistake'))));
        $tr && $this->safe('wallet', fn () => $this->at($at->copy()->addHours(3), fn () => $this->as($this->user('finance_officer'), fn ($u) => $ws->reverseInternal($tr, 'Customer reported wrong recipient; recipient consented to reversal.', $u))));
        // A restricted wallet for the status demo
        $ws->changeStatus($active[$active->count() - 1]->customer->wallet()->first(), \App\Enums\WalletStatus::Restricted, 'Unusual activity under review (demo).');
    }

    // ------------------------------------------------------------------ servicing, recovery, complaints, leads

    private function servicingAndSupport(): void
    {
        $servicing = app(ServicingService::class);
        $this->at($this->today->copy()->setTime(8, 0), fn () => $servicing->runDelinquency());
        $overdue = LoanAccount::where('dpd', '>', 0)->get();
        if ($overdue->isNotEmpty()) {
            // Each overdue loan goes to the recovery agent of its own branch, assigned by that branch's manager (M21).
            foreach ($overdue->groupBy('branch_id') as $branchId => $loans) {
                $this->as($this->branchUser('branch_manager', $branchId), fn ($u) => $servicing->assignRecovery($loans->pluck('id')->all(), $this->branchUser('recovery_agent', $branchId), $u));
            }
            foreach ($overdue as $i => $loan) {
                $agent = $this->branchUser('recovery_agent', $loan->branch_id);
                $this->at($this->today->copy()->subDays(5)->setTime(11, 30), fn () => $this->as($agent, fn ($u) => $servicing->logContact($loan->fresh(), $u, [
                    'contact_type' => 'call', 'contacted_party' => 'borrower', 'outcome' => 'promise_to_pay', 'ptp_date' => $this->today->copy()->subDays(1 - $i % 3 * 3)->toDateString(), 'ptp_amount' => '5000',
                    'notes' => 'Customer cited delayed salary; promised to pay.'])));
                $this->at($this->today->copy()->subDays(2)->setTime(17, 0), fn () => $this->as($agent, fn ($u) => $servicing->sendDelayNotice($loan->fresh(), $u)));
            }
            $this->at($this->today->copy()->setTime(9, 0), fn () => $servicing->evaluatePromises());
        }

        $cs = app(ComplaintService::class);
        $support = $this->user('customer_support');
        $tickets = [
            [12, ComplaintCategory::TransactionDispute, 'Top-up deducted from bank but not in wallet', 'I topped up PKR 7,000 through Raast and my bank shows the debit but the wallet still shows pending.', ComplaintStatus::InProgress],
            [13, ComplaintCategory::Loan, 'Request instalment date change', 'My salary comes on the 10th; can my due date be moved?', ComplaintStatus::Resolved],
            [15, ComplaintCategory::RefundRequest, 'Refund for returned item', 'I returned a fan to the merchant, please refund PKR 800.', ComplaintStatus::Closed],
            [17, ComplaintCategory::CollectionConduct, 'Recovery call at late hour', 'I received a call after 10pm from a recovery number.', ComplaintStatus::Escalated],
            [20, ComplaintCategory::Service, 'App shows wrong name', 'My name is misspelled in my profile.', ComplaintStatus::Open],
            [11, ComplaintCategory::Wallet, 'Daily limit too low', 'Please increase my wallet daily limit.', ComplaintStatus::Acknowledged],
        ];
        foreach ($tickets as $i => [$ci, $cat, $subject, $body, $final]) {
            $cu = $this->customers[$ci];
            $c = $this->at($this->today->copy()->subDays(14 - $i * 2)->setTime(10, 0), fn () => $this->as($cu, fn ($u) => $cs->open($u->customer, $cat, $subject, $body, null, 'web', $u)));
            if ($final === ComplaintStatus::Open) {
                continue;
            }
            $this->at($this->today->copy()->subDays(13 - $i * 2)->setTime(11, 0), function () use ($cs, $c, $support, $final) {
                $this->as($support, function ($u) use ($cs, $c, $final) {
                    $cs->assign($c, $u, $u);
                    $cs->transition($c, ComplaintStatus::Acknowledged, $u, 'Acknowledged within SLA.');
                    if ($final === ComplaintStatus::Acknowledged) {
                        return;
                    }
                    $cs->reply($c->fresh(), $u, 'We are looking into this and will update you shortly.');
                    if ($final === ComplaintStatus::Escalated) {
                        $cs->transition($c->fresh(), ComplaintStatus::Escalated, $u, 'Escalated to Compliance – collections conduct.');

                        return;
                    }
                    $cs->transition($c->fresh(), ComplaintStatus::InProgress, $u);
                    if (in_array($final, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true)) {
                        $cs->transition($c->fresh(), ComplaintStatus::Resolved, $u, null, 'Issue resolved as requested; details shared with the customer.');
                    }
                    if ($final === ComplaintStatus::Closed) {
                        $cs->transition($c->fresh(), ComplaintStatus::Closed, $u, 'Customer confirmed.');
                    }
                });
            });
        }

        $statuses = ['new', 'contacted', 'qualified', 'converted', 'dropped', 'new', 'contacted'];
        foreach ($statuses as $i => $s) {
            Lead::create(['lead_no' => Numbering::next('lead'), 'name' => $this->pick(DemoCatalog::MALE).' '.$this->pick(DemoCatalog::SURNAMES), 'mobile' => '0300'.mt_rand(1000000, 9999999),
                'channel' => ['branch', 'call_center', 'website', 'vendor', 'dfs', 'app', 'branch'][$i], 'product_id' => $this->products[['MBS', 'ARK', 'SLR', 'HAP', 'KBS', 'MLT', 'SSC'][$i]]->id,
                'requested_amount' => (string) (100000 + $i * 25000), 'status' => $s, 'branch_id' => Branch::where('is_head_office', false)->value('id'),
                'assigned_to' => $this->user('loan_officer', $i)->id, 'notes' => 'Demo lead '.($i + 1)]);
        }
    }

    // ------------------------------------------------------------------ finance

    private function finance(): void
    {
        $ap = app(VendorPaymentService::class);
        $ops = app(FinanceOperations::class);
        $gl = app(GeneralLedger::class);
        $engine = app(ApprovalEngine::class);
        $acct = $this->user('accountant');
        $fin = $this->user('finance_officer');
        $admin = $this->user('tenant_admin');
        $opsVendors = $this->vendors['operational'];
        $rent = Account::where('system_role', 'rent_expense')->value('id');
        $it = Account::where('system_role', 'it_expense')->value('id');
        $general = Account::where('system_role', 'general_expense')->value('id');

        // Monthly rent & internet bills for the last 4 months, paid; latest month at various stages
        for ($m = 4; $m >= 1; $m--) {
            $when = $this->today->copy()->subMonths($m)->startOfMonth()->addDays(4)->setTime(10, 0);
            $bill = $this->at($when, fn () => $this->as($acct, fn ($u) => $ap->createBill($opsVendors[2], ['amount' => '450000', 'expense_account_id' => $rent, 'description' => 'Head office rent '.$when->format('M Y'),
                'vendor_invoice_ref' => 'INV-R-'.$when->format('ym'), 'bill_date' => $when->toDateString(), 'due_date' => $when->copy()->addDays(10)->toDateString()], $u)));
            $net = $this->at($when->copy()->addHour(), fn () => $this->as($acct, fn ($u) => $ap->request($opsVendors[2], ['vendor_bill_id' => $bill->id, 'method' => 'bank_transfer', 'idempotency_key' => $this->key('vp')], $u)));
            if ($m === 1) {
                break; // latest rent payment left pending approval
            }
            $this->at($when->copy()->addHours(3), fn () => $this->as($fin, fn ($u) => $engine->approve($net->approvalRequest, $u, 'Within budget.')));
            $this->at($when->copy()->addHours(4), fn () => $this->as($fin, fn ($u) => $ap->execute($net->fresh(), $u, 'success')));
            $ib = $this->at($when->copy()->addDays(2), fn () => $this->as($acct, fn ($u) => $ap->createBill($opsVendors[1], ['amount' => '85000', 'expense_account_id' => $it, 'description' => 'Connectivity '.$when->format('M Y'),
                'bill_date' => $when->toDateString(), 'due_date' => $when->copy()->addDays(15)->toDateString()], $u)));
            if ($m === 2) {
                $p = $this->at($when->copy()->addDays(3), fn () => $this->as($acct, fn ($u) => $ap->request($opsVendors[1], ['vendor_bill_id' => $ib->id, 'method' => 'raast', 'idempotency_key' => $this->key('vp')], $u)));
                $this->at($when->copy()->addDays(3)->addHour(), fn () => $this->as($fin, fn ($u) => $engine->approve($p->approvalRequest, $u, 'OK')));
                $this->at($when->copy()->addDays(3)->addHours(2), fn () => $this->as($fin, fn ($u) => $ap->execute($p->fresh(), $u, 'failure'))); // failed payment
            }
        }
        // Approved, awaiting release + an advance to a supplier
        $ob = $this->at($this->today->copy()->subDays(6)->setTime(10, 0), fn () => $this->as($acct, fn ($u) => $ap->createBill($opsVendors[0], ['amount' => '64000', 'expense_account_id' => $general, 'description' => 'Stationery & printed forms', 'bill_date' => now()->toDateString()], $u)));
        $op = $this->at($this->today->copy()->subDays(5)->setTime(10, 0), fn () => $this->as($acct, fn ($u) => $ap->request($opsVendors[0], ['vendor_bill_id' => $ob->id, 'method' => 'raast', 'idempotency_key' => $this->key('vp')], $u)));
        $this->at($this->today->copy()->subDays(5)->setTime(12, 0), fn () => $this->as($fin, fn ($u) => $engine->approve($op->approvalRequest, $u, 'Approved')));
        $this->at($this->today->copy()->subDays(4)->setTime(10, 0), fn () => $this->as($acct, fn ($u) => $ap->request($this->vendors['solar'][0], ['is_advance' => true, 'amount' => '500000', 'method' => 'raast', 'purpose' => 'Bulk order advance for solar kits', 'idempotency_key' => $this->key('adv')], $u)));

        // Manual journal vouchers: posted accrual, pending approval, draft, and a reversed one
        $j1 = $this->at($this->today->copy()->subMonths(1)->endOfMonth()->setTime(17, 0), fn () => $this->as($acct, fn ($u) => $gl->createManual(['voucher_date' => now()->toDateString(), 'narration' => 'Accrual of audit fee for the month'],
            [['account_id' => $general, 'debit' => '120000', 'credit' => 0, 'cost_center_id' => CostCenter::where('code', 'CC-HO')->value('id')], ['account_id' => Account::where('system_role', 'vendor_payable')->value('id'), 'debit' => 0, 'credit' => '120000']], $u)));
        $this->submitJv($j1, $acct, $fin, true);
        $j2 = $this->at($this->today->copy()->subDays(3)->setTime(15, 0), fn () => $this->as($acct, fn ($u) => $gl->createManual(['voucher_date' => now()->toDateString(), 'narration' => 'Reclassification of IT expense to general admin'],
            [['account_id' => $general, 'debit' => '15000', 'credit' => 0], ['account_id' => $it, 'debit' => 0, 'credit' => '15000']], $u)));
        $this->submitJv($j2, $acct, $fin, false);
        $this->at($this->today->copy()->subDays(1)->setTime(15, 0), fn () => $this->as($acct, fn ($u) => $gl->createManual(['voucher_date' => now()->toDateString(), 'narration' => 'DRAFT – petty cash replenishment Gulberg'],
            [['account_id' => Account::where('system_role', 'petty_cash')->value('id'), 'debit' => '25000', 'credit' => 0], ['account_id' => Account::where('system_role', 'operating_bank')->value('id'), 'debit' => 0, 'credit' => '25000']], $u)));
        $j4 = $this->at($this->today->copy()->subDays(8)->setTime(12, 0), fn () => $this->as($acct, fn ($u) => $gl->createManual(['voucher_date' => now()->toDateString(), 'narration' => 'Bank charges for the month (entered twice in error)'],
            [['account_id' => Account::where('system_role', 'bank_charges')->value('id'), 'debit' => '3500', 'credit' => 0], ['account_id' => Account::where('system_role', 'operating_bank')->value('id'), 'debit' => 0, 'credit' => '3500']], $u)));
        $this->submitJv($j4, $acct, $fin, true);
        // Reversal is maker-checker: the accountant requests it, the finance officer approves (S6).
        $this->at($this->today->copy()->subDays(7)->setTime(12, 0), function () use ($j4, $acct, $fin, $engine) {
            $req = $this->as($acct, fn ($u) => app(\App\Services\Accounting\JournalWorkflow::class)->requestReversal($j4->fresh(), $u, 'Duplicate entry – already recorded'));
            $this->as($fin, fn ($u) => $engine->approve($req->fresh(), $u, 'Duplicate confirmed against bank statement.'));
        });

        // Expenses: paid and pending
        $e1 = $this->at($this->today->copy()->subDays(20)->setTime(10, 0), fn () => $this->as($this->user('branch_manager'), fn ($u) => $ops->raiseExpense(['expense_account_id' => $general, 'amount' => '18500', 'description' => 'Branch generator fuel', 'kind' => 'expense'], $u)));
        $this->at($this->today->copy()->subDays(19)->setTime(10, 0), fn () => $this->as($fin, fn ($u) => $engine->approve($e1->approvalRequest, $u, 'OK')));
        $this->at($this->today->copy()->subDays(2)->setTime(10, 0), fn () => $this->as($this->user('loan_officer'), fn ($u) => $ops->raiseExpense(['expense_account_id' => $general, 'amount' => '4200', 'description' => 'Field visit travel reimbursement', 'kind' => 'reimbursement'], $u)));

        // Payroll: last month posted, this month pending approval
        $last = $this->today->copy()->subMonth();
        $run = $this->at($last->copy()->endOfMonth()->setTime(9, 0)->min($this->today), fn () => $this->as($acct, fn ($u) => $ops->preparePayroll($last->format('Y-m'), $u)));
        $this->at($last->copy()->endOfMonth()->setTime(12, 0)->min($this->today), fn () => $this->as($fin, fn ($u) => $engine->approve($run->approvalRequest, $u, 'Payroll verified against HR register.')));
        $this->at($this->today->copy()->setTime(9, 0), fn () => $this->as($acct, fn ($u) => $ops->preparePayroll($this->today->format('Y-m'), $u)));

        // Depreciation for every elapsed period since acquisition
        foreach (AccountingPeriod::whereDate('end_date', '<', $this->today->copy()->startOfMonth())->whereDate('start_date', '>=', $this->today->copy()->subMonths(13)->startOfMonth())->orderBy('start_date')->get() as $p) {
            $this->at($p->end_date->copy()->setTime(18, 0), fn () => $ops->runDepreciation($p));
        }
        // Other receivables
        OtherReceivable::create(['reference' => Numbering::next('receivable'), 'party_name' => 'Staff advance – '.$this->user('visit_officer')->name, 'kind' => 'staff_advance', 'amount' => '30000', 'received_amount' => '10000', 'due_date' => $this->today->copy()->addMonth(), 'status' => 'open']);
        OtherReceivable::create(['reference' => Numbering::next('receivable'), 'party_name' => 'Takaful operator claim (demo)', 'kind' => 'insurance_claim', 'amount' => '145000', 'due_date' => $this->today->copy()->subDays(10), 'status' => 'open']);

        // Budget for the current fiscal year
        $fy = \App\Models\FiscalYear::whereDate('start_date', '<=', $this->today)->whereDate('end_date', '>=', $this->today)->first();
        if ($fy) {
            $budget = \App\Models\Budget::create(['fiscal_year_id' => $fy->id, 'name' => $fy->name.' operating budget', 'status' => 'approved']);
            foreach ($fy->periods as $p) {
                foreach ([[$rent, '450000'], [$it, '90000'], [$general, '150000'], [Account::where('system_role', 'salaries_expense')->value('id'), '2400000'], [Account::where('system_role', 'profit_income')->value('id'), '900000']] as [$acc, $amt]) {
                    $budget->lines()->create(['account_id' => $acc, 'accounting_period_id' => $p->id, 'amount' => $amt]);
                }
            }
        }

        // Charity disbursement request (from collected late-payment amounts) – pending approval
        if (Money::isPositive($ops->charityBalance())) {
            $this->as($fin, fn ($u) => rescue(fn () => $ops->requestCharityDisbursement('Edhi Foundation (demo beneficiary)', Money::min($ops->charityBalance(), '2000'), 'Monthly charity distribution of late payment amounts', $u), report: false));
        }
        // Investor profit distribution calculated for the last completed period
        $prev = AccountingPeriod::whereDate('end_date', '<', $this->today->copy()->startOfMonth())->orderByDesc('start_date')->first();
        if ($prev) {
            rescue(fn () => $ops->calculateProfitDistribution(Fund::where('code', 'MDR')->first(), $prev), report: false);
        }

        // Reconciliation for the collection account: inject a bank-side difference and an unidentified receipt
        $collection = BankAccount::where('purpose', 'collection')->first();
        $from = $this->today->copy()->subMonth()->startOfMonth()->toDateString();
        $to = $this->today->copy()->subMonth()->endOfMonth()->toDateString();
        $line = SettlementRecord::where('bank_account_id', $collection->id)->whereBetween('value_date', [$from, $to])->first();
        $line?->update(['amount' => Money::sub($line->amount, '250'), 'narration' => $line->narration.' – bank short-credit (demo difference)']);
        SettlementRecord::create(['bank_account_id' => $collection->id, 'provider' => 'raast', 'value_date' => $to, 'provider_ref' => 'RAAST-UNREF-'.strtoupper(Str::random(6)),
            'narration' => 'RAAST CREDIT – no customer reference (SIMULATED)', 'direction' => 'credit', 'amount' => '7350.00']);
        $batch = $this->at($this->today->copy()->subDays(2)->setTime(16, 0), fn () => $this->as($acct, fn ($u) => app(ReconciliationService::class)->run($collection, $from, $to, $u)));
        $unref = $batch->items()->where('result', 'missing_internal')->first();
        if ($unref) {
            $this->as($acct, fn ($u) => app(ReconciliationService::class)->moveToSuspense($unref, $u, 'Unidentified Raast receipt – investigating sender.'));
        }
        // A fully reconciled, approved batch for the safeguarded account
        $safe = BankAccount::where('purpose', 'safeguarded')->first();
        $b2 = $this->at($this->today->copy()->subDays(1)->setTime(16, 0), fn () => $this->as($acct, fn ($u) => app(ReconciliationService::class)->run($safe, $this->today->copy()->subMonths(13)->toDateString(), $this->today->copy()->subDays(40)->toDateString(), $u)));
        foreach ($b2->items()->whereNotIn('result', ['matched'])->get() as $it2) {
            $this->as($acct, fn ($u) => app(ReconciliationService::class)->annotate($it2, $u, 'timing_difference', 'Provider settled next business day.'));
        }
        $this->as($acct, function ($u) use ($b2, $engine, $fin) {
            $b = app(ReconciliationService::class)->submit($b2->fresh(), $u);
            if ($b->fresh()->status->value === 'pending_approval') {
                $req = ApprovalRequest::where('approvable_type', $b->getMorphClass())->where('approvable_id', $b->id)->first();
                $this->as($fin, fn ($f) => $engine->approve($req, $f, 'Reconciled.'));
            }
        });
    }

    private function submitJv($jv, User $maker, User $checker, bool $approve): void
    {
        $jv->forceFill(['status' => \App\Enums\JournalStatus::PendingApproval, 'submitted_at' => now()])->save();
        $req = $this->as($maker, fn ($u) => app(ApprovalEngine::class)->submit($jv, \App\Enums\ApprovalType::JournalVoucher, (string) $jv->total_debit, $u));
        if ($approve) {
            $this->as($checker, fn ($u) => app(ApprovalEngine::class)->approve($req, $u, 'Checked supporting documents.'));
        }
    }

    // ------------------------------------------------------------------ period close & today's jobs

    private function closePeriods(): void
    {
        $svc = app(PeriodService::class);
        $closer = $this->user('finance_officer');
        $cutoff = $this->today->copy()->subMonth()->startOfMonth();
        foreach (AccountingPeriod::whereDate('end_date', '<', $cutoff)->orderBy('start_date')->get() as $p) {
            try {
                $this->as($closer, fn ($u) => $svc->close($p, $u));
            } catch (\Throwable $e) {
                $this->say("period {$p->name} left open: ".$e->getMessage());
                break;
            }
        }
    }

    private function runToday(): void
    {
        // Demo users were created "in the past"; mark passwords as current so the 90-day expiry policy doesn't force a change on first demo login.
        User::where('tenant_id', $this->tenant->id)->whereIn('type', ['staff', 'vendor', 'customer'])->update(['password_changed_at' => now(), 'must_change_password' => false]);
        // Demo staff share a documented TOTP secret so two-step verification works in demos (demo mode only; see DEMO_CREDENTIALS.md).
        User::where('tenant_id', $this->tenant->id)->where('type', 'staff')->get()->each(fn (User $u) => \App\Services\Identity\Mfa::enrolDemo($u));
        app(ServicingService::class)->runDelinquency();
        app(FinanceOperations::class)->runProvisioning();
        // Write-off request for a loss-classified account, if any
        $loss = LoanAccount::where('classification', 'loss')->whereIn('status', ['active', 'overdue'])->first();
        if ($loss) {
            $this->as($this->user('risk_officer'), fn ($u) => rescue(fn () => app(FinanceOperations::class)->requestWriteOff($loss, 'Borrower relocated, unreachable for 12+ months; asset not recoverable.', $u), report: false));
        }
        // Collection reminders generate notifications for upcoming instalments
        app(ServicingService::class)->sendReminders();
        // Expired / expiring documents are already present via CNIC expiry dates on customers #6/#7.
        Wallet::query()->count();
    }
}
