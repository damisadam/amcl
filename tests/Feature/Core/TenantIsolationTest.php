<?php

namespace Tests\Feature\Core;

use App\Exceptions\TenantIsolationViolation;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\LoanApplication;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_queries_are_scoped_and_fail_closed_without_tenant(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        $ca = $this->customer($a, false);
        $cb = $this->customer($b, false);

        TenantContext::set($a);
        $this->assertSame([$ca->customer_id], Customer::pluck('id')->all());
        $this->assertNull(Customer::find($cb->customer_id));

        TenantContext::clear();
        $this->assertSame(0, Customer::count(), 'No tenant context must return nothing.');
    }

    public function test_cross_tenant_write_is_blocked(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        $cb = $this->customer($b, false);
        $record = TenantContext::bypass(fn () => Customer::find($cb->customer_id));

        TenantContext::set($a);
        $this->expectException(TenantIsolationViolation::class);
        $record->update(['city' => 'Hacked']);
    }

    public function test_staff_cannot_open_other_tenants_records_by_url(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        $cb = $this->customer($b, false);
        $doc = TenantContext::run($b, fn () => app(\App\Services\Documents\DocumentStore::class)
            ->storeGenerated(Customer::find($cb->customer_id), null, 'x', '<p>x</p>'));

        $this->actingAs($this->admin($a))->get('/documents/'.$doc->id)->assertNotFound();
        $this->actingAs($this->admin($b))->get('/documents/'.$doc->id)->assertOk();
    }
}
