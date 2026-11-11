<?php

namespace Tests\Feature\Ops\Servicing;

use App\Enums\DisbursementStatus;
use App\Enums\JournalStatus;
use App\Exceptions\DomainException;
use App\Livewire\Ops\Admin\Roles;
use App\Livewire\Ops\Admin\Workflows;
use App\Livewire\Ops\Disbursements\Index as Disbursements;
use App\Livewire\Ops\Finance\Journals\Show as JournalShow;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\ApplicationStatusHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\Disbursement;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use App\Models\Role;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\JournalWorkflow;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Lending\DisbursementService;
use App\Services\Lending\MurabahaService;
use App\Services\Lending\RepaymentService;
use App\Services\Lending\ServicingService;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Payments\Simulation;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/** Regression tests for the QA findings H7, S6, H8, M7, M10, S5, F5, F7 and tenant-admin trimming. */
class QaControlsTest extends ServicingTestCase
{
    public function test_h7_payout_requires_a_different_releaser_and_can_be_declined(): void
    {
        $t = $this->makeTenant('alpha');
        $app = $this->readyApplication($t);
        TenantContext::set($t);
        $maker = $this->staff($t, 'disbursement_approver');
        $checker = $this->staff($t, 'disbursement_approver');
        $svc = app(DisbursementService::class);

        $d = $svc->initiate($app, $maker);
        $this->assertTrue($d->awaitingRelease());
        $this->assertNull($d->payment_order_id);
        $this->assertSame(0, LoanAccount::count(), 'A requested payout moves no money and creates no loan.');

        try {
            $svc->release($d, $maker);
            $this->fail('Requester released their own payout');
        } catch (DomainException $e) {
            $this->assertStringContainsString('different user', $e->getMessage());
        }
        // A second request while one is pending is refused (never two live payouts)
        try {
            $svc->initiate($app->fresh(), $checker);
            $this->fail('second request accepted');
        } catch (DomainException) {
        }
        $this->assertSame(1, Disbursement::count());

        // Checker declines → case back in the queue, no loan
        $svc->declineRequest($d, $checker, 'IBAN on file does not match the vendor invoice');
        $this->assertSame(DisbursementStatus::Failed, $d->fresh()->status);
        $this->assertSame('ready_for_disbursement', $app->fresh()->status->value);

        // New request, released by the checker through the screen
        $d2 = $svc->initiate($app->fresh(), $maker);
        Livewire::actingAs($maker)->test(Disbursements::class)->call('openRelease', $d2->id)->call('release')->assertHasErrors('action');
        Livewire::actingAs($checker)->test(Disbursements::class)->call('openRelease', $d2->id)->call('release');
        $d2->refresh();
        $this->assertSame(DisbursementStatus::Succeeded, $d2->status);
        $this->assertSame($checker->id, $d2->released_by);
        $this->assertSame(1, LoanAccount::count());
        $this->assertTrue(AuditLog::where('action', 'disbursement.released')->exists());
    }

    public function test_s6_journal_reversal_is_requested_then_approved_by_a_second_user(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $maker = $this->staff($t, 'accountant');
        $fo = $this->staff($t, 'finance_officer');
        $fo2 = $this->staff($t, 'finance_officer');
        $gl = app(GeneralLedger::class);
        $lines = [['account_id' => Account::where('system_role', 'general_expense')->value('id'), 'debit' => '500', 'credit' => 0],
            ['account_id' => Account::where('system_role', 'operating_bank')->value('id'), 'debit' => 0, 'credit' => '500']];
        $jv = $gl->createManual(['voucher_date' => today()->toDateString(), 'narration' => 'Test'], $lines, $maker);
        $gl->post($jv, $fo);

        // The screen now raises a request instead of reversing immediately
        Livewire::actingAs($fo)->test(JournalShow::class, ['journal' => $jv])->set('reason', 'Posted to the wrong account')->call('reverse');
        $jv->refresh();
        $this->assertSame(JournalStatus::Posted, $jv->status, 'Nothing is reversed until a second user approves.');
        $this->assertSame($fo->id, $jv->reversal_requested_by);
        $req = ApprovalRequest::where('type', 'journal_reversal')->firstOrFail();

        $engine = app(ApprovalEngine::class);
        $this->assertFalse($engine->canAct($req, $fo), 'The requester cannot approve their own reversal.');
        $engine->approve($req, $fo2, 'Checked');
        $this->assertSame(JournalStatus::Reversed, $jv->fresh()->status);
        $this->assertSame(1, JournalVoucher::where('reversal_of_id', $jv->id)->count());

        // Tenant Admin no longer holds the reversal / posting rights by default
        $admin = $this->admin($t);
        $this->assertFalse($admin->hasPermission('fms.journals.reverse'));
        $this->assertFalse($admin->hasPermission('approvals.act'));
    }

    public function test_s6_reversal_refused_when_original_period_is_closed(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $fo = $this->staff($t, 'finance_officer');
        $last = AccountingPeriod::where('end_date', '<', today()->startOfMonth())->orderByDesc('start_date')->first();
        $gl = app(GeneralLedger::class);
        $jv = $gl->createManual(['voucher_date' => $last->end_date->toDateString(), 'narration' => 'Old'], [
            ['account_id' => Account::where('system_role', 'general_expense')->value('id'), 'debit' => '100', 'credit' => 0],
            ['account_id' => Account::where('system_role', 'operating_bank')->value('id'), 'debit' => 0, 'credit' => '100']], $this->staff($t, 'accountant'));
        $gl->post($jv, $fo);
        AccountingPeriod::where('end_date', '<=', $last->end_date)->update(['status' => 'closed']);

        $this->expectException(DomainException::class);
        app(JournalWorkflow::class)->requestReversal($jv->fresh(), $fo, 'Wrong period');
    }

    public function test_h8_m10_committee_precedes_disbursement_and_builder_keeps_disbursement_last(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $wf = ApprovalWorkflow::where('type', 'loan')->with('stages.role')->firstOrFail();
        $this->assertSame('Standard financing approval', $wf->name);
        $roles = $wf->stages->pluck('role.key')->all();
        $this->assertSame('disbursement_approver', end($roles));
        $this->assertLessThan(array_search('disbursement_approver', $roles), array_search('approval_committee', $roles));

        $admin = $this->admin($t);
        $last = $wf->stages->last();
        // Moving disbursement approval up is refused
        Livewire::actingAs($admin)->test(Workflows::class)->set('workflowId', $wf->id)->call('move', $last->id, -1);
        $this->assertSame($last->id, $wf->fresh()->stages()->get()->last()->id);
        // A new stage is inserted before disbursement approval
        Livewire::actingAs($admin)->test(Workflows::class)->set('workflowId', $wf->id)->call('editStage')
            ->set('stage.name', 'Shariah review')->set('stage.role_id', (string) Role::where('tenant_id', $t->id)->where('key', 'compliance_officer')->value('id'))
            ->call('saveStage')->assertHasNoErrors();
        $names = $wf->fresh()->stages()->pluck('name')->all();
        $this->assertSame('Disbursement Approval', end($names));
        $this->assertContains('Shariah review', $names);
    }

    public function test_tenant_admin_role_is_editable_but_keeps_admin_essentials(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $admin = $this->admin($t);
        $role = Role::where('tenant_id', $t->id)->where('key', 'tenant_admin')->first();
        Livewire::actingAs($admin)->test(Roles::class)->set('roleId', $role->id)->set('selected', ['dashboard.view'])->call('save');
        $keys = $role->permissions()->pluck('key')->all();
        foreach (Roles::ADMIN_ESSENTIAL as $k) {
            $this->assertContains($k, $keys);
        }
        $this->assertNotContains('customers.view', $keys);
    }

    public function test_m7_loan_lifecycle_appears_on_application_timeline(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);
        app(RepaymentService::class)->recordManual($loan, $loan->totalOutstanding(), 'cash', 'R-1', $this->staff($t, 'accountant'));
        $nocUser = $this->staff($t, 'branch_manager');
        $nocUser->roles()->attach(Role::where('tenant_id', $t->id)->where('key', 'tenant_admin')->value('id'));
        app(ServicingService::class)->issueNoc($loan->fresh(), $nocUser->fresh('roles'));
        $comments = ApplicationStatusHistory::where('loan_application_id', $loan->loan_application_id)->pluck('comment')->implode(' | ');
        $this->assertStringContainsString('loan closed', $comments);
        $this->assertStringContainsString('No Objection Certificate', $comments);
    }

    public function test_f5_murabaha_accounting_defers_profit_and_recognises_it_on_collection(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);
        $r = app(FinancialReports::class);
        $jv = JournalVoucher::where('event_type', 'loan_disbursement')->with('lines.account')->firstOrFail();
        $this->assertTrue($jv->isBalanced());
        $roles = $jv->lines->pluck('account.system_role')->unique()->values()->all();
        foreach (['murabaha_inventory', 'financing_receivable', 'deferred_profit', 'disbursement_bank'] as $role) {
            $this->assertContains($role, $roles);
        }
        $this->assertSame(Money::add($loan->principal, $loan->profit_total, $loan->charges_total), $r->roleBalance('financing_receivable'));
        $this->assertSame(Money::of($loan->principal_outstanding), $r->netFinancingReceivable());
        $this->assertTrue(Money::isZero($r->roleBalance('murabaha_inventory')), 'Asset bought and sold in the same sequence');
        $this->assertTrue(Money::isZero($r->roleBalance('profit_income')));

        $first = $loan->instalments()->first();
        app(RepaymentService::class)->recordManual($loan, $first->total_due, 'cash', 'R-1', $this->staff($t, 'accountant'));
        $loan->refresh();
        $this->assertSame(Money::of($first->profit_due), $r->roleBalance('profit_income'));
        $this->assertSame(Money::of($loan->principal_outstanding), $r->netFinancingReceivable());
        $this->assertTrue($r->trialBalance(null, today()->toDateString())['balanced']);
        $this->assertTrue($r->balanceSheet(today()->toDateString())['balanced']);
    }

    public function test_s5_simulation_controls_only_for_demo_tenants(): void
    {
        $t = $this->makeTenant('alpha', ['is_demo' => false]);
        TenantContext::set($t);
        $this->assertFalse(Simulation::enabled());
        $this->assertNull(Simulation::scenario('failure', 'test'));

        $cu = $this->customer($t);
        TenantContext::set($t);
        $w = $cu->customer->wallet()->first();
        // A forced "failure" scenario is ignored outside demo tenants: the default (success) applies.
        $txn = app(WalletService::class)->topUp($w, '1000', 'raast', 'k-nd', 'failure', $cu);
        $this->assertSame('succeeded', $txn->fresh()->status->value);
        $this->expectException(DomainException::class);
        app(PaymentOrchestrator::class)->simulate($txn->fresh()->paymentOrder, 'reversed');
    }

    public function test_s5_forced_outcomes_are_audited_for_demo_tenants(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        TenantContext::set($t);
        app(WalletService::class)->topUp($cu->customer->wallet()->first(), '1000', 'raast', 'k-d', 'failure', $cu);
        $this->assertTrue(AuditLog::where('action', 'simulation.used')->exists());
    }

    public function test_f7_murabaha_asset_ownership_requires_invoice_confirmed_by_second_user(): void
    {
        $t = $this->makeTenant('alpha');
        $app = $this->readyApplication($t);
        TenantContext::set($t);
        $svc = app(MurabahaService::class);
        $this->assertTrue($svc->requiresAssetOwnership($app));
        $this->assertTrue($svc->assetOwnershipConfirmed($app), 'the shared fixture confirms ownership before KFS');
        $this->assertContains('MURABAHA_OFFER', $svc->pack($app));
        $this->assertSame('Amount paid to vendor', $svc->payoutLabel($app));

        $maker = $this->staff($t, 'loan_officer');
        $svc->recordVendorInvoice($app, UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'), $maker, 'INV-77');
        $this->assertFalse($svc->assetOwnershipConfirmed($app->fresh()), 'a replaced invoice needs a fresh confirmation');
        try {
            $svc->confirmOwnership($app, $maker);
            $this->fail('uploader confirmed own evidence');
        } catch (DomainException) {
        }
        $svc->confirmOwnership($app, $this->staff($t, 'disbursement_approver'));
        $this->assertTrue($svc->assetOwnershipConfirmed($app->fresh()));
    }

    public function test_m9_m15_rendering_fixes(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $admin = $this->admin($t);
        $this->actingAs($admin)->get(route('ops.products.index'))->assertOk()->assertDontSee('&amp;amp;', false)->assertSee('Active &amp; inactive', false);
        $locked = $this->staff($t, 'loan_officer');
        $locked->forceFill(['status' => 'locked', 'locked_until' => now()->addHour()])->save();
        $html = $this->actingAs($admin)->get(route('ops.admin.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('locked until', $html);
        // nav: on Company configuration only one admin item is highlighted
        $nav = $this->actingAs($admin)->get(route('ops.admin.config'))->getContent();
        $this->assertSame(1, substr_count($nav, 'aria-current="page"'));
    }
}
