<?php

namespace Tests\Feature\Ops\Servicing;

use App\Livewire\Ops\Products\Create as ProductCreate;
use App\Livewire\Ops\Products\Show as ProductShow;
use App\Livewire\Ops\StaffLending\Index as StaffIndex;
use App\Livewire\Ops\StaffLending\Show as StaffShow;
use App\Livewire\Ops\Vendors\Create as VendorCreate;
use App\Livewire\Ops\Vendors\Show as VendorShow;
use App\Models\CorporateClient;
use App\Models\LoanPayment;
use App\Models\Product;
use App\Models\StaffSalary;
use App\Models\User;
use App\Models\Vendor;
use App\Support\TenantContext;
use Livewire\Livewire;

class ProductVendorStaffTest extends ServicingTestCase
{
    public function test_create_product_and_publish_new_version_keeps_old_terms(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(ProductCreate::class)
            ->set('product.code', 'LAP')->set('product.name', 'Mera Laptop')->set('product.category', 'laptop')
            ->call('save')->assertHasNoErrors();
        $p = TenantContext::run($t, fn () => Product::where('code', 'LAP')->first());
        $this->assertNotNull($p);
        $v1 = $p->current_version_id;

        Livewire::actingAs($admin)->test(ProductShow::class, ['product' => $p])
            ->set('terms.profit_rate', '18')->set('charges', [])->call('createVersion')->assertHasNoErrors();
        $p->refresh();
        $this->assertNotSame($v1, $p->current_version_id);
        TenantContext::run($t, function () use ($p, $v1) {
            $this->assertSame('18.000', (string) $p->currentVersion->profit_rate);
            $this->assertSame(0, $p->currentVersion->charges()->count());
            $this->assertSame('retired', \App\Models\ProductVersion::find($v1)->status);
        });

        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(ProductCreate::class)->assertForbidden();
    }

    public function test_vendor_lifecycle_approval_portal_user_and_merchant_wallet(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(VendorCreate::class)
            ->set('form.business_name', 'Karachi Solar')->set('form.category', 'solar')->set('form.iban', 'PK36SCBL0000001123456702')
            ->set('form.is_merchant', true)->set('form.merchant_code', 'KSOL1')->call('save')->assertHasNoErrors();
        $v = TenantContext::run($t, fn () => Vendor::where('business_name', 'Karachi Solar')->first());
        $this->assertSame('pending', $v->status->value);

        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(VendorShow::class, ['vendor' => $v])->call('approve')->assertForbidden();

        $c = Livewire::actingAs($admin)->test(VendorShow::class, ['vendor' => $v])->call('approve')
            ->call('enableMerchant')->set('userName', 'Vendor User')->set('userEmail', 'vendor@ksol.test')->call('createUser');
        $this->assertNotNull($c->get('tempPassword'));
        $this->assertSame('active', $v->fresh()->status->value);
        TenantContext::run($t, fn () => $this->assertSame('active', $v->fresh()->wallet()->first()->status->value));
        $vu = TenantContext::run($t, fn () => User::where('email', 'vendor@ksol.test')->first());
        $this->assertTrue($vu->must_change_password);

        Livewire::actingAs($admin)->test(VendorShow::class, ['vendor' => $v])->call('openStatus', 'inactive')->set('statusReason', 'Licence expired')->call('changeStatus');
        $this->assertSame('inactive', $v->fresh()->status->value);
        $this->assertSame('suspended', $vu->fresh()->status->value);
    }

    public function test_staff_lending_salary_and_deduction_posting(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        // posting a salary deduction records a repayment: an operational permission held by finance roles, not Tenant Admin
        $admin->roles()->attach(\App\Models\Role::where('tenant_id', $t->id)->where('key', 'accountant')->value('id'));
        $admin = $admin->fresh('roles');
        $loan = $this->disbursedLoan($t);
        Livewire::actingAs($admin)->test(StaffIndex::class)->set('form.company_name', 'Acme Textiles')->call('save')->assertHasNoErrors();
        $corp = TenantContext::run($t, fn () => CorporateClient::first());
        $period = $loan->first_due_date->format('Y-m');
        $cif = TenantContext::run($t, fn () => $loan->customer->cif_no);

        $c = Livewire::actingAs($admin)->test(StaffShow::class, ['corporate' => $corp])
            ->set('staffForm.employee_no', 'E001')->set('staffForm.name', 'Test Customer')->set('staffForm.monthly_salary', '90000')->call('saveStaff')->assertHasNoErrors();
        $staff = TenantContext::run($t, fn () => $corp->staff()->first());
        $c->set('linkStaffId', (string) $staff->id)->set('linkRef', $cif)->call('link')
            ->set('tab', 'salaries')->set('period', $period)->set('csv', "employee_no,period,gross\nE001,{$period},90000\nE999,{$period},1")->call('import');
        $this->assertSame(1, StaffSalary::count());
        $this->assertCount(1, $c->get('importErrors'));
        $this->assertTrue(\App\Support\Money::isPositive(StaffSalary::first()->loan_deduction));

        $c->set('tab', 'reconciliation')->call('postDeduction', $staff->id)->assertHasNoErrors();
        $p = LoanPayment::where('channel', 'salary_deduction')->first();
        $this->assertNotNull($p);
        $this->assertSame('succeeded', $p->status->value);
        // Idempotent: second post finds nothing pending.
        $c->call('postDeduction', $staff->id);
        $this->assertSame(1, LoanPayment::where('channel', 'salary_deduction')->count());
    }
}
