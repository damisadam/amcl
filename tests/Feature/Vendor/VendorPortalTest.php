<?php

namespace Tests\Feature\Vendor;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Livewire\Public\VendorRequest;
use App\Livewire\Vendor;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Customer;
use App\Models\LoanApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor as VendorModel;
use App\Models\VendorItem;
use App\Models\VendorPayment;
use App\Services\Lending\ApplicationService;
use App\Services\Lending\VendorOriginationService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class VendorPortalTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function vendorUser(Tenant $tenant, VendorModel $vendor): User
    {
        return TenantContext::run($tenant, function () use ($tenant, $vendor) {
            $u = new User(['name' => 'Vendor User', 'email' => uniqid('v').'@vendor.test']);
            $u->forceFill(['tenant_id' => $tenant->id, 'type' => UserType::Vendor, 'status' => UserStatus::Active, 'vendor_id' => $vendor->id,
                'password' => 'Password@123', 'password_changed_at' => now()])->save();

            return $u->fresh();
        });
    }

    private function originationData(VendorModel $vendor, int $productId, string $cnic = '35202-7654321-3', string $mobile = '03011112222'): array
    {
        return ['full_name' => 'Bilal Ahmed', 'cnic' => $cnic, 'mobile' => $mobile, 'date_of_birth' => '1991-03-04', 'address' => 'Gulberg, Lahore', 'city' => 'Lahore',
            'employment_type' => 'salaried', 'monthly_income' => '90000', 'product_id' => $productId, 'vendor_item_id' => $vendor->items()->value('id'), 'tenure' => 12,
            'consents' => ['terms_of_use' => true, 'privacy_policy' => true, 'nadra_verification' => true, 'credit_bureau' => true, 'data_sharing' => true]];
    }

    public function test_vendor_origination_creates_customer_and_draft_when_kyc_pending(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $product = $this->product($t);
        $vu = $this->vendorUser($t, $vendor);
        TenantContext::set($t);

        Livewire::actingAs($vu)->test(Vendor\Originate::class)
            ->set('full_name', 'Bilal Ahmed')->set('cnic', '35202-7654321-3')->set('mobile', '03011112222')->set('date_of_birth', '1991-03-04')
            ->set('address', 'Gulberg')->set('city', 'Lahore')->set('monthly_income', '90000')
            ->set('product_id', (string) $product->id)->set('vendor_item_id', (string) $vendor->items()->value('id'))->set('tenure', '12')
            ->set('cnic_front', UploadedFile::fake()->image('cnic.jpg'))
            ->set('consents.terms_of_use', true)->set('consents.nadra_verification', true)->set('consents.credit_bureau', true)
            ->call('submit')->assertHasNoErrors()->assertSet('result.submitted', false);

        $customer = Customer::where('cnic', '35202-7654321-3')->firstOrFail();
        $this->assertSame($vendor->id, $customer->origin_vendor_id);
        $this->assertSame('vendor', $customer->onboarding_channel);
        $this->assertSame('vendor_referred', $customer->client_type);
        $app = LoanApplication::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('draft', $app->status->value);
        $this->assertSame('vendor', $app->channel);
        $this->assertSame($vendor->id, $app->vendor_id);
        $this->assertSame($vu->id, (int) $app->created_by);
        $this->assertStringContainsString('Captured at vendor premises', Consent::where('customer_id', $customer->id)->first()->text_snapshot);
        $this->assertTrue($customer->documents()->exists());
    }

    public function test_vendor_can_submit_for_kyc_passed_customer(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $product = $this->product($t);
        $cu = $this->customer($t, true);
        $vu = $this->vendorUser($t, $vendor);
        TenantContext::set($t);
        $c = $cu->customer;
        auth()->setUser($vu);

        $result = app(VendorOriginationService::class)->originate($vendor, $vu, $this->originationData($vendor, $product->id, $c->cnic, $c->mobile));
        $this->assertTrue($result['submitted'], $result['message']);
        $this->assertSame('submitted', $result['application']->status->value);
    }

    public function test_vendor_sees_only_own_orders(): void
    {
        $t = $this->makeTenant('alpha');
        $v1 = $this->vendor($t);
        $v2 = $this->vendor($t);
        $product = $this->product($t);
        $cu = $this->customer($t, true);
        TenantContext::set($t);
        auth()->setUser($cu);
        $other = app(ApplicationService::class)->createDraft($cu->customer, $product, ['vendor_item_id' => $v2->items()->value('id'), 'tenure' => 12], $cu);
        $vu = $this->vendorUser($t, $v1);

        Livewire::actingAs($vu)->test(Vendor\Orders\Index::class)->assertDontSee($other->application_no);
        $this->actingAs($vu)->get('/vendor/orders/'.$other->id)->assertForbidden();
        $this->actingAs($this->vendorUser($t, $v2))->get('/vendor/orders/'.$other->id)->assertOk()->assertSee($other->application_no)->assertDontSee($cu->customer->cnic);
    }

    public function test_items_are_scoped_to_own_vendor(): void
    {
        $t = $this->makeTenant('alpha');
        $v1 = $this->vendor($t);
        $v2 = $this->vendor($t);
        $vu = $this->vendorUser($t, $v1);
        TenantContext::set($t);

        Livewire::actingAs($vu)->test(Vendor\Items::class)->call('create')
            ->set('form.name', 'Solar 5kW kit')->set('form.price', '450000')->set('form.product_category', 'solar')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('vendor_items', ['vendor_id' => $v1->id, 'name' => 'Solar 5kW kit']);

        $foreign = $v2->items()->first();
        try {
            Livewire::actingAs($vu)->test(Vendor\Items::class)->call('edit', $foreign->id);
            $this->fail('Editing another vendor\'s item must be refused.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // 404 for foreign records
        }
        $this->assertSame('Honda CD-70', VendorItem::find($foreign->id)->name);
    }

    public function test_bank_change_is_a_request_not_a_direct_update(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $vu = $this->vendorUser($t, $vendor);
        TenantContext::set($t);
        $old = $vendor->iban;

        Livewire::actingAs($vu)->test(Vendor\Profile::class)
            ->set('bank.bank_name', 'Other Bank')->set('bank.iban', 'PK12ABCD0000001234567890')->set('bank.account_title', 'Test Motors')->set('bank.reason', 'Moved to new bank account')
            ->call('requestBankChange')->assertHasNoErrors()->assertSet('bankRequested', true);
        $this->assertSame($old, $vendor->fresh()->iban);
        $this->assertDatabaseHas('audit_logs', ['action' => 'vendor.bank_change_requested', 'auditable_id' => $vendor->id]);
        $this->assertTrue($this->admin($t)->notifications()->exists());

        Livewire::actingAs($vu)->test(Vendor\Profile::class)->set('contact.phone', '0421234567')->call('saveContact')->assertHasNoErrors();
        $this->assertSame('0421234567', $vendor->fresh()->phone);
    }

    public function test_receipt_and_portal_access_controls(): void
    {
        $t = $this->makeTenant('alpha');
        $v1 = $this->vendor($t);
        $v2 = $this->vendor($t);
        TenantContext::set($t);
        $payment = new VendorPayment(['payment_no' => 'VP-1', 'vendor_id' => $v2->id, 'amount' => '1000', 'net_amount' => '1000', 'method' => 'raast',
            'idempotency_key' => 'x1', 'purpose' => 'Test', 'paid_at' => now()]);
        $payment->forceFill(['status' => 'paid'])->save();

        $this->actingAs($this->vendorUser($t, $v1))->get('/vendor/payments/'.$payment->id.'/receipt')->assertForbidden();
        $this->actingAs($this->vendorUser($t, $v2))->get('/vendor/payments/'.$payment->id.'/receipt')->assertOk()->assertSee('VP-1');
        $this->actingAs($this->admin($t))->get('/vendor')->assertForbidden();

        foreach (['/vendor', '/vendor/orders', '/vendor/originate', '/vendor/payments', '/vendor/items', '/vendor/merchant', '/vendor/documents', '/vendor/profile'] as $url) {
            $this->actingAs($this->vendorUser($t, $v1))->get($url)->assertOk();
        }
    }

    public function test_vendor_portal_blocked_when_feature_disabled(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $vu = $this->vendorUser($t, $vendor);
        $t->update(['features' => ['lms']]);
        $this->actingAs($vu)->get('/vendor')->assertForbidden();
    }

    public function test_public_vendor_request_creates_pending_vendor(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::clear();

        Livewire::test(VendorRequest::class, ['tenant' => $t])
            ->set('form.business_name', 'Karachi Solar Traders')->set('form.owner_name', 'Asif')->set('form.cnic', '42101-1234567-1')
            ->set('form.category', 'solar')->set('form.city', 'Karachi')->set('form.address', 'Saddar')->set('form.phone', '03211234567')
            ->set('form.email', 'asif@solar.test')->set('form.bank_name', 'Sim Bank')->set('form.iban', 'PK36SIML0000001123456702')->set('form.account_title', 'KST')
            ->set('registration_doc', UploadedFile::fake()->create('reg.pdf', 50, 'application/pdf'))
            ->set('accept', true)->call('submit')->assertHasNoErrors()->assertNotSet('reference', null);

        $v = TenantContext::run($t, fn () => VendorModel::where('business_name', 'Karachi Solar Traders')->firstOrFail());
        $this->assertSame('pending', $v->status->value);
        $this->assertSame('web_request', $v->source);
        $this->assertSame($t->id, (int) $v->tenant_id);
        $this->assertSame(1, TenantContext::run($t, fn () => $v->documents()->count()));

        $this->get('/t/alpha/vendor-request')->assertOk()->assertSee('Become a vendor');
    }
}
