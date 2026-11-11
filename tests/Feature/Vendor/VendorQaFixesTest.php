<?php

namespace Tests\Feature\Vendor;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Livewire\Ops\VendorPriceApprovals;
use App\Livewire\Vendor;
use App\Models\LoanApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor as VendorModel;
use App\Models\VendorItem;
use App\Services\Lending\ApplicationService;
use App\Services\Products\ProductService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Regression tests for QA findings M4, M19, M20 (vendor portal). */
class VendorQaFixesTest extends TestCase
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

    public function test_m4_vendor_and_items_follow_product_category(): void
    {
        $t = $this->makeTenant('alpha');
        $bikeDealer = $this->vendor($t); // category motorbike
        $motorbike = $this->product($t);
        $laptop = TenantContext::run($t, fn () => app(ProductService::class)->create(
            ['code' => 'LAP', 'name' => 'Mera Laptop', 'category' => 'laptop', 'shariah_structure' => 'murabaha'],
            ['min_amount' => 50000, 'max_amount' => 300000, 'min_tenure' => 3, 'max_tenure' => 12, 'profit_rate' => 20, 'requires_vendor' => true, 'disbursement_mode' => 'vendor'], [], [['CNIC_FRONT']]));
        TenantContext::set($t);
        $grocer = VendorModel::create(['code' => 'G1', 'business_name' => 'Grocer', 'category' => 'retail']);
        $grocer->forceFill(['status' => 'active'])->save();
        $scooty = $bikeDealer->items()->create(['name' => 'Scooty 70', 'price' => '120000', 'product_category' => 'scooty']);

        $forBike = VendorModel::forProduct($motorbike)->pluck('id')->all();
        $this->assertContains($bikeDealer->id, $forBike);
        $this->assertNotContains($grocer->id, $forBike);
        $this->assertSame([], VendorModel::forProduct($laptop)->pluck('id')->all());
        $this->assertFalse(VendorItem::whereKey($scooty->id)->forProduct($motorbike)->exists(), 'A scooty item is not financed as a motorbike');

        // vendor portal originate offers only products the dealer can supply
        $vu = $this->vendorUser($t, $bikeDealer);
        Livewire::actingAs($vu)->test(Vendor\Originate::class)->assertSee($motorbike->name)->assertDontSee('Mera Laptop');
    }

    public function test_m19_vendor_does_not_see_rejected_outcomes(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $product = $this->product($t);
        $cu = $this->customer($t, true);
        TenantContext::set($t);
        auth()->setUser($cu);
        $svc = app(ApplicationService::class);
        $open = $svc->createDraft($cu->customer, $product, ['vendor_item_id' => $vendor->items()->value('id'), 'tenure' => 12], $cu);
        $withdrawn = $svc->createDraft($this->customer($t, true)->customer, $product, ['vendor_item_id' => $vendor->items()->value('id'), 'tenure' => 12], $cu);
        TenantContext::set($t);
        $svc->withdraw($withdrawn, $cu, 'changed mind');
        $vu = $this->vendorUser($t, $vendor);

        Livewire::actingAs($vu)->test(Vendor\Orders\Index::class)->assertSee($open->application_no)->assertDontSee($withdrawn->application_no)->assertSee('In progress');
        $this->actingAs($vu)->get('/vendor/orders/'.$withdrawn->id)->assertNotFound();
        $this->actingAs($vu)->get('/vendor')->assertOk()->assertDontSee('· status')->assertSee($vendor->code);
    }

    public function test_m20_price_change_needs_staff_approval_and_never_changes_submitted_applications(): void
    {
        $t = $this->makeTenant('alpha');
        $vendor = $this->vendor($t);
        $product = $this->product($t);
        $cu = $this->customer($t, true);
        TenantContext::set($t);
        $item = $vendor->items()->first();
        auth()->setUser($cu);
        $app = app(ApplicationService::class)->createDraft($cu->customer, $product, ['vendor_item_id' => $item->id, 'tenure' => 12], $cu);
        $agreed = (string) $app->asset_price;
        $vu = $this->vendorUser($t, $vendor);

        Livewire::actingAs($vu)->test(Vendor\Items::class)->call('edit', $item->id)->set('form.price', '199000')->call('save')->assertHasNoErrors();
        $item->refresh();
        $this->assertSame('165000.00', (string) $item->price, 'price unchanged until approved');
        $this->assertSame('199000.00', (string) $item->pending_price);

        // New item starts inactive until its price is approved
        Livewire::actingAs($vu)->test(Vendor\Items::class)->call('create')->set('form.name', 'CG-125')->set('form.price', '250000')->call('save')->assertHasNoErrors();
        $new = VendorItem::where('name', 'CG-125')->first();
        $this->assertFalse($new->is_active);

        $officer = $this->staff($t, 'loan_officer'); // no vendors.manage
        Livewire::actingAs($officer)->test(VendorPriceApprovals::class, ['vendorId' => $vendor->id])->call('approve', $item->id);
        $this->assertSame('165000.00', (string) $item->fresh()->price);

        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(VendorPriceApprovals::class, ['vendorId' => $vendor->id])->call('approve', $item->id)->call('approve', $new->id);
        $this->assertSame('199000.00', (string) $item->fresh()->price);
        $this->assertNull($item->fresh()->pending_price);
        $this->assertTrue($new->fresh()->is_active);
        $this->assertSame($agreed, (string) LoanApplication::find($app->id)->asset_price, 'existing applications keep the agreed price');
        $this->assertDatabaseHas('audit_logs', ['action' => 'vendor.item_price_approved']);
    }
}
