<?php

namespace Tests\Feature\Ops\Servicing;

use App\Livewire\Ops\Disbursements\Index;
use App\Models\Disbursement;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use Livewire\Livewire;

class DisbursementScreenTest extends ServicingTestCase
{
    public function test_authorized_user_disburses_and_loan_is_created(): void
    {
        $t = $this->makeTenant('alpha');
        $app = $this->readyApplication($t);
        $da = $this->staff($t, 'disbursement_approver');

        Livewire::actingAs($da)->test(Index::class)
            ->assertSee($app->application_no)
            ->call('confirm', $app->id)->set('scenario', 'success')->call('initiate')->assertHasNoErrors();
        $this->assertSame(0, LoanAccount::count(), 'Requested only – awaiting release by a second user');
        $checker = $this->staff($t, 'disbursement_approver');
        Livewire::actingAs($checker)->test(Index::class)->set('tab', 'progress')->call('openRelease', Disbursement::first()->id)->call('release')->assertHasNoErrors();

        $this->assertSame('succeeded', Disbursement::first()->status->value);
        $this->assertSame(1, LoanAccount::count());
        $this->assertSame('disbursed', $app->fresh()->status->value);
        $this->assertSame(1, JournalVoucher::where('event_type', 'loan_disbursement')->count());
    }

    public function test_failed_payout_creates_no_loan_and_allows_retry(): void
    {
        $t = $this->makeTenant('alpha');
        $app = $this->readyApplication($t);
        $da = $this->staff($t, 'disbursement_approver');

        $checker = $this->staff($t, 'disbursement_approver');
        Livewire::actingAs($da)->test(Index::class)->call('confirm', $app->id)->set('scenario', 'failure')->call('initiate');
        Livewire::actingAs($checker)->test(Index::class)->call('openRelease', Disbursement::first()->id)->call('release');
        $this->assertSame('failed', Disbursement::first()->status->value);
        $this->assertSame(0, LoanAccount::count());
        $this->assertSame(0, JournalVoucher::where('event_type', 'loan_disbursement')->count());
        $this->assertSame('ready_for_disbursement', $app->fresh()->status->value);

        Livewire::actingAs($da)->test(Index::class)->call('confirm', $app->id)->set('scenario', 'success')->call('initiate');
        Livewire::actingAs($checker)->test(Index::class)->call('openRelease', Disbursement::latest('id')->first()->id)->call('release');
        $this->assertSame(1, LoanAccount::count());
        $this->assertSame(2, Disbursement::count());
    }

    public function test_loan_officer_cannot_initiate(): void
    {
        $t = $this->makeTenant('alpha');
        $app = $this->readyApplication($t);
        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(Index::class)->assertForbidden();
        Livewire::actingAs($this->staff($t, 'branch_manager'))->test(Index::class)->call('confirm', $app->id)->assertForbidden();
        $this->assertSame(0, Disbursement::count());
    }
}
