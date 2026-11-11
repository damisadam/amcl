<?php

namespace Tests\Concerns;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Documents\DocumentStore;
use App\Services\Kyc\OnboardingService;
use App\Services\Products\ProductService;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;

/** Shared fixtures: provisioned tenants, staff by role, KYC-passed customers, a product and vendor. */
trait BuildsTenants
{
    protected function makeTenant(string $code = 'alpha', array $attrs = []): Tenant
    {
        $tenant = app(TenantProvisioner::class)->provision(array_merge([
            'code' => $code, 'name' => ucfirst($code).' Finance', 'city' => 'Lahore', 'support_phone' => '042-1110000', 'support_email' => "help@{$code}.test",
        ], $attrs), ['name' => ucfirst($code).' Admin', 'email' => "admin@{$code}.test", 'password' => 'Password@123']);
        TenantContext::set($tenant);

        return $tenant;
    }

    protected function admin(Tenant $tenant): User
    {
        return TenantContext::bypass(fn () => User::where('tenant_id', $tenant->id)->where('email', "admin@{$tenant->code}.test")->firstOrFail());
    }

    protected function staff(Tenant $tenant, string|array $roles, ?string $name = null): User
    {
        $roles = (array) $roles;
        $name ??= ucwords(str_replace('_', ' ', $roles[0])).' '.uniqid();

        return TenantContext::run($tenant, function () use ($tenant, $roles, $name) {
            $u = new User(['name' => $name, 'email' => uniqid('u').'@'.$tenant->code.'.test']);
            $u->forceFill(['tenant_id' => $tenant->id, 'type' => UserType::Staff, 'status' => UserStatus::Active, 'password' => 'Password@123',
                'branch_id' => Branch::where('is_head_office', true)->value('id'), 'password_changed_at' => now()])->save();
            $u->roles()->attach(Role::where('tenant_id', $tenant->id)->whereIn('key', $roles)->pluck('id'));

            return $u->fresh();
        });
    }

    /** A registered, mobile-verified customer. $cnicLastDigit drives the deterministic KYC outcome (0–6 pass). */
    protected function customer(Tenant $tenant, bool $kycPassed = true, int $cnicLastDigit = 3, array $profile = []): User
    {
        return TenantContext::run($tenant, function () use ($tenant, $kycPassed, $cnicLastDigit, $profile) {
            $onb = app(OnboardingService::class);
            $mobile = '03'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $user = $onb->register($tenant, ['name' => 'Test Customer', 'mobile' => $mobile, 'password' => 'Password@123']);
            $onb->markMobileVerified($user);
            $c = $user->customer;
            $onb->updateProfile($c, array_merge([
                'full_name' => 'Test Customer', 'cnic' => '35202-'.random_int(1000000, 9999999).'-'.$cnicLastDigit, 'date_of_birth' => '1990-01-01',
                'gender' => 'male', 'current_address' => 'Lahore', 'permanent_address' => 'Lahore', 'city' => 'Lahore',
                'employment_type' => 'salaried', 'monthly_income' => '90000', 'existing_obligations' => '0',
            ], $profile));
            $onb->recordConsents($c, ['terms_of_use' => 1, 'privacy_policy' => 1, 'nadra_verification' => 1, 'credit_bureau' => 1, 'esignature' => 1], 'en', '127.0.0.1', 'test');
            if ($kycPassed) {
                app(DocumentStore::class)->store(UploadedFile::fake()->image('cnic.jpg'), $c, DocumentType::where('code', 'CNIC_FRONT')->first(), $user);
                $onb->runEkyc($c->fresh());
            }

            return $user->fresh();
        });
    }

    protected function vendor(Tenant $tenant): Vendor
    {
        return TenantContext::run($tenant, function () {
            $v = Vendor::create(['code' => 'V'.uniqid(), 'business_name' => 'Test Motors', 'category' => 'motorbike', 'iban' => 'PK36SIML0000001123456702',
                'bank_name' => 'Sim Bank', 'account_title' => 'Test Motors', 'is_merchant' => true, 'merchant_code' => 'M'.random_int(10000, 99999)]);
            $v->forceFill(['status' => 'active'])->save();
            $v->items()->create(['name' => 'Honda CD-70', 'price' => '165000.00']);

            return $v;
        });
    }

    protected function product(Tenant $tenant, array $terms = [], array $docs = [['CNIC_FRONT']]): Product
    {
        return TenantContext::run($tenant, fn () => app(ProductService::class)->create(
            ['code' => 'P'.uniqid(), 'name' => 'Motorbike Sahulat', 'category' => 'motorbike', 'shariah_structure' => 'murabaha'],
            array_merge(['min_amount' => 50000, 'max_amount' => 400000, 'min_tenure' => 6, 'max_tenure' => 24, 'profit_rate' => 24, 'profit_method' => 'flat',
                'down_payment_pct' => 10, 'min_income' => 25000, 'max_dbr' => 40, 'requires_vendor' => true, 'disbursement_mode' => 'vendor',
                'late_charge_per_day' => 50, 'cooling_off_hours' => 24], $terms),
            [['PF', 'Processing fee', 'processing_fee', 'percentage', '2', 'upfront_deducted'], ['TKF', 'Takaful', 'takaful', 'percentage', '1.5', 'added_to_instalments']],
            $docs));
    }

    /** Murabaha sequence (QA F7): one staff user records the vendor invoice, another confirms ownership. No-op for other products. */
    protected function confirmAssetOwnership(\App\Models\LoanApplication $app): void
    {
        $tenant = Tenant::findOrFail($app->tenant_id);
        TenantContext::run($tenant, function () use ($tenant, $app) {
            $svc = app(\App\Services\Lending\MurabahaService::class);
            $app = \App\Models\LoanApplication::findOrFail($app->id);
            if ($svc->assetOwnershipConfirmed($app)) {
                return;
            }
            $svc->recordVendorInvoice($app, UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'), $this->staff($tenant, 'loan_officer'), 'INV-TEST-'.$app->id);
            $svc->confirmOwnership($app, $this->staff($tenant, 'disbursement_approver'));
        });
    }
}
