<?php

namespace Tests\Feature\Ops\Servicing;

use App\Livewire\Ops;
use App\Models\CorporateClient;
use App\Support\TenantContext;
use Livewire\Livewire;

/** Every screen and tab renders for an authorized user (catches view errors in rarely used tabs). */
class ScreensRenderTest extends ServicingTestCase
{
    public function test_all_servicing_screens_and_tabs_render(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $admin = $this->admin($t);
        [$vendor, $product, $corp] = TenantContext::run($t, fn () => [
            \App\Models\Vendor::first(), \App\Models\Product::first(), CorporateClient::create(['code' => 'C1', 'company_name' => 'Acme']),
        ]);

        foreach ([Ops\Disbursements\Index::class => ['ready', 'progress', 'history']] as $c => $tabs) {
            foreach ($tabs as $tab) {
                Livewire::actingAs($admin)->test($c)->set('tab', $tab)->assertOk();
            }
        }
        Livewire::actingAs($admin)->test(Ops\Loans\Index::class)->assertSee($loan->account_no);
        Livewire::actingAs($admin)->test(Ops\Loans\Transfer::class)->assertSee($loan->account_no);
        foreach (['summary', 'schedule', 'payments', 'recovery', 'documents', 'audit'] as $tab) {
            Livewire::actingAs($admin)->test(Ops\Loans\Show::class, ['loan' => $loan])->set('tab', $tab)->assertOk();
        }
        foreach (['overdue', 'today', 'week'] as $tab) {
            Livewire::actingAs($admin)->test(Ops\Collections\Index::class)->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($admin)->test(Ops\Recovery\Index::class)->set('scope', 'all')->assertOk();
        Livewire::actingAs($admin)->test(Ops\Recovery\Show::class, ['loan' => $loan])->assertOk();
        Livewire::actingAs($admin)->test(Ops\Products\Index::class)->assertSee($product->name);
        Livewire::actingAs($admin)->test(Ops\Products\Create::class)->assertOk();
        foreach (['terms', 'versions', 'new', 'details'] as $tab) {
            Livewire::actingAs($admin)->test(Ops\Products\Show::class, ['product' => $product])->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($admin)->test(Ops\Vendors\Index::class)->assertSee($vendor->business_name);
        Livewire::actingAs($admin)->test(Ops\Vendors\Create::class)->assertOk();
        foreach (['overview', 'items', 'documents', 'users', 'cases', 'payments'] as $tab) {
            Livewire::actingAs($admin)->test(Ops\Vendors\Show::class, ['vendor' => $vendor])->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($admin)->test(Ops\StaffLending\Index::class)->assertSee('Acme');
        foreach (['staff', 'salaries', 'reconciliation', 'details'] as $tab) {
            Livewire::actingAs($admin)->test(Ops\StaffLending\Show::class, ['corporate' => $corp])->set('tab', $tab)->assertOk();
        }

        // Full-page HTTP render through the ops layout.
        foreach (['ops.disbursements.index', 'ops.loans.index', 'ops.collections.index', 'ops.recovery.index', 'ops.products.index', 'ops.vendors.index', 'ops.staff-lending.index'] as $r) {
            $this->actingAs($admin)->get(route($r))->assertOk();
        }
        $this->actingAs($admin)->get(route('ops.loans.show', $loan))->assertOk();
    }
}
