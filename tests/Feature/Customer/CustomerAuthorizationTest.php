<?php

namespace Tests\Feature\Customer;

use App\Livewire\Customer\Applications\Show as ApplicationShow;
use App\Livewire\Customer\Financing\Show as FinancingShow;
use App\Livewire\Customer\Support\Show as SupportShow;
use App\Models\Complaint;
use App\Enums\ComplaintCategory;
use App\Services\Lending\ApplicationService;
use App\Services\Support\ComplaintService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CustomerAuthorizationTest extends TestCase
{
    use BuildsTenants, ProcessesApplications, RefreshDatabase;

    public function test_customer_cannot_open_another_customers_records(): void
    {
        $tenant = $this->makeTenant('alpha');
        $product = $this->product($tenant, ['requires_vendor' => false, 'disbursement_mode' => 'customer_bank', 'down_payment_pct' => 0]);
        $a = $this->customer($tenant);
        $b = $this->customer($tenant);
        TenantContext::set($tenant);
        $appB = app(ApplicationService::class)->createDraft($b->customer, $product, ['requested_amount' => '100000', 'tenure' => 12, 'purpose' => 'x'], $b);
        $appB->forceFill(['status' => 'submitted'])->save();
        $ticketB = app(ComplaintService::class)->open($b->customer, ComplaintCategory::General, 'Hello there', 'Something about my account', null, 'web', $b);
        $docB = $b->customer->documents()->first();

        $this->actingAs($a);
        Livewire::test(ApplicationShow::class, ['application' => $appB])->assertStatus(404);
        Livewire::test(SupportShow::class, ['complaint' => $ticketB])->assertStatus(404);
        $this->get('/documents/'.$docB->id)->assertForbidden();
        $this->get('/app/applications/'.$appB->id)->assertNotFound();
        $this->get('/app/support/'.$ticketB->id)->assertNotFound();

        $this->actingAs($b);
        $this->get('/documents/'.$docB->id)->assertOk();
    }

    public function test_customer_of_tenant_a_cannot_reach_tenant_b_records_and_staff_cannot_use_customer_portal(): void
    {
        $ta = $this->makeTenant('alpha');
        $tb = $this->makeTenant('bravo');
        $productB = $this->product($tb, ['requires_vendor' => false, 'disbursement_mode' => 'customer_bank', 'down_payment_pct' => 0]);
        $a = $this->customer($ta);
        $b = $this->customer($tb);
        $appB = TenantContext::run($tb, fn () => app(ApplicationService::class)->createDraft($b->customer, $productB, ['requested_amount' => '100000', 'tenure' => 12, 'purpose' => 'x'], $b));

        $this->actingAs($a)->get('/app/applications/'.$appB->id)->assertNotFound();
        $this->actingAs($a)->get('/app/products/'.$productB->id)->assertNotFound();
        $this->actingAs($this->admin($ta))->get('/app')->assertForbidden();
        $this->actingAs($a)->get('/ops')->assertForbidden();
    }

    public function test_receipts_are_owner_only(): void
    {
        $tenant = $this->makeTenant('alpha');
        [$owner, $loan] = $this->disbursedLoan($tenant);
        TenantContext::set($tenant);
        $payment = app(\App\Services\Lending\RepaymentService::class)->recordManual($loan, '1000', 'cash', 'R-1', $this->staff($tenant, 'accountant'));
        $other = $this->customer($tenant);

        $this->actingAs($owner)->get('/app/receipts/'.$payment->id)->assertOk()->assertSee($payment->receipt_no);
        $this->actingAs($other)->get('/app/receipts/'.$payment->id)->assertNotFound();
    }

    private function expectNotFoundFor(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected 404');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
