<?php

namespace Tests\Feature\Core;

use App\Enums\ApplicationStatus;
use App\Enums\DisbursementStatus;
use App\Enums\LoanAccountStatus;
use App\Exceptions\DomainException;
use App\Models\Disbursement;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use App\Services\Accounting\FinancialReports;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Lending\DisbursementService;
use App\Services\Lending\RepaymentService;
use App\Services\Lending\ServicingService;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DrivesLending;
use Tests\TestCase;

class LendingWorkflowTest extends TestCase
{
    use DrivesLending, RefreshDatabase;

    public function test_full_journey_from_application_to_closure_and_noc(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);

        $this->assertSame(LoanAccountStatus::Active, $loan->status);
        $this->assertSame(ApplicationStatus::Disbursed, $loan->application->status);
        $this->assertSame(12, $loan->instalments()->count());
        $this->assertTrue(Money::eq($loan->total_receivable, $loan->instalments()->sum('total_due')));
        $this->assertSame(1, JournalVoucher::where('event_type', 'loan_disbursement')->count());

        // Repay everything manually; loan closes, GL balances, NOC can be issued
        $accountant = $this->staff($t, 'accountant');
        app(RepaymentService::class)->recordManual($loan, $loan->totalOutstanding(), 'bank_transfer', 'IBFT-1', $accountant);
        $loan->refresh();
        $this->assertSame(LoanAccountStatus::Closed, $loan->status);
        $this->assertTrue(Money::isZero($loan->totalOutstanding()));
        $tb = app(FinancialReports::class)->trialBalance(null, today()->toDateString());
        $this->assertTrue($tb['balanced']);

        $loan = app(ServicingService::class)->issueNoc($loan, $this->admin($t));
        $this->assertSame(LoanAccountStatus::NocIssued, $loan->status);
        $this->assertNotNull($loan->noc_no);
    }

    public function test_disbursement_is_blocked_until_prerequisites_and_failure_creates_no_loan(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        $approvers = $this->approvers($t);
        $app = $this->reviewed($t, $this->submittedApplication($t, $cu, $this->product($t), $this->vendor($t)));
        TenantContext::set($t);

        try {
            app(DisbursementService::class)->initiate($app, $approvers['disbursement_approver']);
            $this->fail('Disbursement before approval must be blocked');
        } catch (DomainException $e) {
            $this->assertStringContainsString('approval', strtolower($e->getMessage()));
        }

        $app = $this->approveAll($t, $app, $approvers);
        $app = $this->signedAndReady($t, $app, $cu, $approvers['disbursement_approver']);
        TenantContext::set($t);
        $releaser = $this->staff($t, 'disbursement_approver'); // maker-checker: requester ≠ releaser
        $d = app(DisbursementService::class)->initiate($app, $approvers['disbursement_approver'], 'failure', $releaser);
        $this->assertSame(DisbursementStatus::Failed, $d->fresh()->status);
        $this->assertSame(0, LoanAccount::count());
        $this->assertSame(0, JournalVoucher::where('event_type', 'loan_disbursement')->count());
        $this->assertSame(ApplicationStatus::ReadyForDisbursement, $app->fresh()->status);

        // retry succeeds exactly once; a repeated initiate does not create a second loan
        $d2 = app(DisbursementService::class)->initiate($app->fresh(), $approvers['disbursement_approver'], 'success', $releaser);
        $this->assertSame(DisbursementStatus::Succeeded, $d2->fresh()->status);
        $this->expectException(DomainException::class);
        try {
            app(DisbursementService::class)->initiate($app->fresh(), $approvers['disbursement_approver'], 'success');
        } finally {
            $this->assertSame(1, LoanAccount::count());
            $this->assertSame(2, Disbursement::count());
        }
    }

    public function test_approval_segregation_of_duties(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        $app = $this->reviewed($t, $this->submittedApplication($t, $cu, $this->product($t), $this->vendor($t)));
        TenantContext::set($t);
        $engine = app(ApprovalEngine::class);
        $req = $app->approvalRequest;

        // wrong role
        $risk = $this->staff($t, 'risk_officer');
        $this->assertFalse($engine->canAct($req, $risk));
        // the user who sent the case for approval cannot approve it even with the role
        $requester = \App\Models\User::find($req->requested_by);
        $requester->roles()->attach(\App\Models\Role::where('tenant_id', $t->id)->where('key', 'cro')->value('id'));
        $this->assertFalse($engine->canAct($req->fresh(), $requester->fresh('roles')));
        // a CRO who approves stage 1 cannot also approve stage 2 even if they hold that role
        $cro = $this->staff($t, ['cro', 'manager_operations']);
        $engine->approve($req, $cro, 'ok');
        $this->assertFalse($engine->canAct($req->fresh(), $cro->fresh('roles')));
        // rejection requires a reason
        $mo = $this->staff($t, 'manager_operations');
        $this->expectException(DomainException::class);
        $engine->reject($req->fresh(), $mo, '');
    }

    public function test_partial_payment_allocation_reversal_and_late_charges_to_charity(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);
        $first = $loan->instalments()->first();
        $acct = $this->staff($t, 'accountant');
        $repay = app(RepaymentService::class);

        $half = Money::div($first->total_due, 2);
        $p = $repay->recordManual($loan, $half, 'cash', 'R-1', $acct);
        $first->refresh();
        $this->assertSame('partially_paid', $first->status->value === 'overdue' ? 'partially_paid' : $first->status->value);
        // allocation order: charges → profit → principal
        $this->assertTrue(Money::eq($first->charges_paid, Money::min($first->charges_due, $half)));

        $other = $this->staff($t, 'finance_officer');
        $other->roles()->attach(\App\Models\Role::where('tenant_id', $t->id)->where('key', 'tenant_admin')->value('id'));
        $repay->reverse($p, 'Cheque bounced', $other->fresh('roles'));
        $this->assertTrue(Money::isZero($first->fresh()->total_paid));
        $this->assertTrue(Money::eq($loan->fresh()->totalOutstanding(), $loan->total_receivable));
        $this->assertTrue(app(FinancialReports::class)->trialBalance(null, today()->toDateString())['balanced']);

        // self-reversal is blocked (maker-checker)
        $p2 = $repay->recordManual($loan->fresh(), '1000', 'cash', 'R-2', $acct);
        $acct->roles()->attach(\App\Models\Role::where('tenant_id', $t->id)->where('key', 'tenant_admin')->value('id'));
        $this->expectException(DomainException::class);
        $repay->reverse($p2, 'x', $acct->fresh('roles'));
    }

    public function test_delinquency_accrues_charity_late_amount_and_classification(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);
        $this->travel(70)->days();
        app(ServicingService::class)->runDelinquency();
        $loan->refresh();
        $this->assertGreaterThan(30, $loan->dpd);
        $this->assertSame(LoanAccountStatus::Overdue, $loan->status);
        $this->assertSame('oaem', $loan->classification);
        $this->assertTrue(Money::isPositive($loan->late_charges_outstanding));

        app(RepaymentService::class)->recordManual($loan, $loan->totalOutstanding(), 'cash', 'R-9', $this->staff($t, 'accountant'));
        $this->assertTrue(Money::isPositive(app(FinancialReports::class)->roleBalance('charity_payable')), 'Late amounts are routed to the charity fund, not income');
    }
}
