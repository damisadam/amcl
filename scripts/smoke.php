<?php
// Service-level end-to-end smoke run. Usage: QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null php artisan tinker scripts/smoke.php
use App\Enums\UserStatus; use App\Enums\UserType; use App\Models\{User, Role, Branch, Vendor, DocumentType, PaymentCallback}; use App\Services\Approvals\ApprovalEngine;
use App\Services\Lending\{ApplicationService, UnderwritingService, AgreementService, DisbursementService}; use App\Services\Kyc\OnboardingService; use App\Services\Wallet\WalletService; use App\Support\TenantContext;
use App\Services\Accounting\FinancialReports; use Illuminate\Http\UploadedFile;

$tenant = app(App\Services\Tenancy\TenantProvisioner::class)->provision(['code' => 'smk'.rand(100,999), 'name' => 'Smoke MFI', 'city' => 'Lahore', 'support_phone' => '042-111', 'support_email' => 'help@smoke.test'],
    ['name' => 'Admin', 'email' => 'admin@smoke.test', 'password' => 'Password@123']);
TenantContext::set($tenant);
$mk = function ($role, $name) use ($tenant) {
    $u = new User(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@smoke.test']);
    $u->forceFill(['tenant_id' => $tenant->id, 'type' => UserType::Staff, 'status' => UserStatus::Active, 'password' => 'Password@123', 'branch_id' => Branch::value('id')])->save();
    $u->roles()->attach(Role::where('tenant_id', $tenant->id)->where('key', $role)->value('id'));
    return $u->fresh();
};
$lo = $mk('loan_officer', 'Loan Officer'); $ca = $mk('credit_analyst', 'Credit Analyst'); $bm = $mk('branch_manager', 'Branch Manager');
$vo = $mk('visit_officer', 'Visit Officer'); $cro = $mk('cro', 'Cro One'); $mo = $mk('manager_operations', 'Mgr Ops'); $co = $mk('compliance_officer', 'Comp One');
$ro = $mk('risk_officer', 'Risk One'); $da = $mk('disbursement_approver', 'Disb One');
auth()->setUser($lo);

$vendor = Vendor::create(['code' => 'V1', 'business_name' => 'Lahore Motors', 'category' => 'motorbike', 'iban' => 'PK36SIML0000001123456702', 'bank_name' => 'Sim Bank', 'account_title' => 'Lahore Motors']);
$vendor->forceFill(['status' => 'active'])->save();
$item = $vendor->items()->create(['name' => 'Honda CD-70 2026', 'price' => '165000.00']);
$product = app(App\Services\Products\ProductService::class)->create(
    ['code' => 'MBS', 'name' => 'Motorbike Sahulat', 'category' => 'motorbike', 'shariah_structure' => 'murabaha'],
    ['min_amount' => 50000, 'max_amount' => 400000, 'min_tenure' => 6, 'max_tenure' => 24, 'profit_rate' => 24, 'profit_method' => 'flat', 'down_payment_pct' => 10,
     'min_income' => 25000, 'max_dbr' => 40, 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'late_charge_per_day' => 50, 'cooling_off_hours' => 24],
    [['PF', 'Processing fee', 'processing_fee', 'percentage', '2', 'upfront_deducted'], ['TKF', 'Takaful', 'takaful', 'percentage', '1.5', 'added_to_instalments'],
     ['LPC', 'Late payment (charity)', 'late_payment', 'flat', '50', 'on_event']],
    [['CNIC_FRONT'], ['SALARY_SLIP']]);

$onb = app(OnboardingService::class);
$cu = $onb->register($tenant, ['name' => 'Ali Raza', 'mobile' => '03001234567', 'password' => 'Password@123']);
$onb->markMobileVerified($cu);
$c = $cu->customer;
$onb->updateProfile($c, ['full_name' => 'Ali Raza', 'cnic' => '35202-1234567-3', 'date_of_birth' => '1990-05-10', 'gender' => 'male', 'father_husband_name' => 'Raza Khan',
    'current_address' => 'House 1, Johar Town, Lahore', 'permanent_address' => 'House 1, Johar Town, Lahore', 'city' => 'Lahore', 'employment_type' => 'salaried',
    'employer_name' => 'Acme', 'monthly_income' => '85000', 'existing_obligations' => '5000']);
$onb->recordConsents($c, ['terms_of_use' => 1, 'privacy_policy' => 1, 'nadra_verification' => 1, 'credit_bureau' => 1, 'esignature' => 1], 'en', '127.0.0.1', 'smoke');
$store = app(App\Services\Documents\DocumentStore::class);
$img = UploadedFile::fake()->image('cnic.jpg', 600, 400);
$store->store($img, $c, DocumentType::where('code', 'CNIC_FRONT')->first(), $cu);
echo "KYC: ".$onb->runEkyc($c->fresh())->value."\n";
$c->refresh(); echo "wallet: ".$c->wallet?->status->value."\n";

$apps = app(ApplicationService::class);
$app = $apps->createDraft($c, $product, ['vendor_item_id' => $item->id, 'tenure' => 12, 'purpose' => 'Commute'], $cu);
$store->store(UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf'), $app, DocumentType::where('code', 'SALARY_SLIP')->first(), $cu);
echo "amount ".$app->requested_amount." instalment ".$app->indicative_instalment."\n";
$apps->submit($app, $cu);
$apps->startReview($app, $bm);
$uw = app(UnderwritingService::class);
$uw->requestBureau($app, $ca);
$v = $uw->scheduleVisit($app, $bm, 'residence', $vo, today()->toDateString());
$uw->checkIn($v, $vo, 31.47, 74.27);
$store->store(UploadedFile::fake()->image('visit.jpg'), $v, DocumentType::where('code', 'VISIT_PHOTO')->first(), $vo);
$uw->submitVisit($v->fresh(), $vo, ['recommendation' => 'positive', 'address_match' => true, 'remarks' => 'Verified']);
$a = $uw->assess($app->fresh(), $ca, []);
echo "assessment: dbr {$a->dbr} score {$a->internal_score} grade {$a->grade} risk {$a->risk_rating} rec {$a->recommendation}\n";
$apps->sendForApproval($app->fresh(), $bm);
$eng = app(ApprovalEngine::class);
foreach ([$cro, $mo, $co, $ro, $da] as $u) { $r = $app->fresh()->approvalRequest; $u->load('roles'); $eng->approve($r, $u, 'ok'); }
$app->refresh(); echo "status: ".$app->status->value." kfs v".$app->kfs?->version." apr ".($app->kfs->terms['apr'] ?? '')."\n";
$ag = app(AgreementService::class);
$ag->acknowledgeKfs($app, $cu, 'en', '127.0.0.1', 'smoke');
foreach ($app->signatureRequests()->get() as $sr) { $sr->signing_method === 'digital' ? $ag->sign($sr, $cu, '127.0.0.1', 'smoke') : $ag->confirmWetInk($sr, $da, 'received'); }
$app->refresh(); echo "status: ".$app->status->value." ends ".$app->cooling_off_ends_at."\n";
$app->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
$ds = app(DisbursementService::class);
print_r($ds->blockers($app->fresh()));
$d = $ds->initiate($app->fresh(), $da);
$d->refresh(); echo "disbursement ".$d->status->value." loan ".$d->loanAccount?->account_no."\n";
$loan = $d->loanAccount; echo "receivable ".$loan->total_receivable." instalments ".$loan->instalments()->count()."\n";

$ws = app(WalletService::class); auth()->setUser($cu);
$w = $c->wallet()->first(); $ws->setPin($w, '4826');
$t = $ws->topUp($w, '20000', 'raast', 'k1'); echo "topup ".$t->fresh()->status->value." bal ".$w->fresh()->balance."\n";
$t2 = $ws->topUp($w, '15000', 'jazzcash', 'k2', 'duplicate_callback'); echo "dup topup ".$t2->fresh()->status->value." bal ".$w->fresh()->balance." callbacks ".PaymentCallback::count()."\n";
$t3 = $ws->topUp($w, '7000', 'easypaisa', 'k3', 'failure'); echo "failed topup ".$t3->fresh()->status->value." bal ".$w->fresh()->balance."\n";
$p = $ws->payInstalment($w->fresh(), $loan, $loan->instalments()->first()->total_due, 'k4', '4826'); echo "instalment paid; bal ".$w->fresh()->balance." loan outstanding ".$loan->fresh()->totalOutstanding()."\n";
$inst = $loan->instalments()->first(); echo "inst1 ".$inst->status->value."\n";
$wd = $ws->withdraw($w->fresh(), '2000', $c->linkedBankAccounts()->firstOrCreate(['iban' => 'PK00SIML0000000000001111'], ['bank_name' => 'HBL', 'account_title' => 'Ali Raza']), 'k5', '4826');
echo "withdraw ".$wd->fresh()->status->value." bal ".$w->fresh()->balance." held ".$w->fresh()->held_balance."\n";
$tb = app(FinancialReports::class)->trialBalance(null, today()->toDateString());
echo "TB balanced: ".($tb['balanced'] ? 'yes' : 'NO')." DR ".$tb['total_debit']." CR ".$tb['total_credit']."\n";
foreach ($tb['rows'] as $r) echo "  ".$r['account']->code." ".$r['account']->name." ".$r['debit']." / ".$r['credit']."\n";
