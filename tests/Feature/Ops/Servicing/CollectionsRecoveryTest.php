<?php

namespace Tests\Feature\Ops\Servicing;

use App\Livewire\Ops\Collections\Index as Collections;
use App\Livewire\Ops\Recovery\Index as Recovery;
use App\Livewire\Ops\Recovery\Show as RecoveryCase;
use App\Models\Instalment;
use App\Models\RecoveryContact;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

class CollectionsRecoveryTest extends ServicingTestCase
{
    private function makeOverdue($t, $loan): void
    {
        TenantContext::run($t, function () use ($loan) {
            Instalment::where('loan_account_id', $loan->id)->where('number', 1)->update(['due_date' => now()->subDays(40)->toDateString()]);
            app(\App\Services\Lending\ServicingService::class)->runDelinquency();
        });
    }

    public function test_collections_queue_shows_overdue_and_delinquency_run(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $this->makeOverdue($t, $loan);
        $this->assertGreaterThan(0, $loan->fresh()->dpd);
        $this->assertTrue(\App\Support\Money::isPositive(Instalment::where('loan_account_id', $loan->id)->where('number', 1)->value('late_charge_due')));

        Livewire::actingAs($this->staff($t, 'branch_manager'))->test(Collections::class)
            ->assertSee($loan->account_no)->call('runDelinquency')->assertHasNoErrors();
        Livewire::actingAs($this->staff($t, 'visit_officer'))->test(Collections::class)->assertForbidden();
    }

    public function test_assignment_and_contact_logging_with_conduct_rules(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        $this->makeOverdue($t, $loan);
        $agent = $this->staff($t, 'recovery_agent');
        $other = $this->staff($t, 'recovery_agent');
        $manager = $this->staff($t, 'branch_manager');

        Livewire::actingAs($manager)->test(Recovery::class)->set('scope', 'all')
            ->set('selected', [(string) $loan->id])->set('assignTo', (string) $agent->id)->call('assign')->assertHasNoErrors();
        $this->assertSame($agent->id, $loan->fresh()->recovery_agent_id);
        $loan = $loan->fresh();

        Livewire::actingAs($other)->test(RecoveryCase::class, ['loan' => $loan])->assertForbidden();
        Livewire::actingAs($agent)->test(Recovery::class)->assertSee($loan->account_no);

        Carbon::setTestNow(today()->setTime(11, 0));
        Livewire::actingAs($agent)->test(RecoveryCase::class, ['loan' => $loan])
            ->set('outcome', 'promise_to_pay')->set('ptp_date', today()->addDays(2)->toDateString())->set('ptp_amount', '5000')->call('logContact')->assertHasNoErrors();
        $this->assertSame(1, RecoveryContact::count());

        Carbon::setTestNow(today()->setTime(23, 15));
        Livewire::actingAs($agent)->test(RecoveryCase::class, ['loan' => $loan])->set('outcome', 'unreachable')->call('logContact')->assertHasErrors('action');
        $this->assertSame(1, RecoveryContact::count());
        Carbon::setTestNow();
    }
}
