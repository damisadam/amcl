<?php

namespace Tests\Feature\Finance;

use App\Enums\JournalStatus;
use App\Livewire\Ops\Finance\Journals\Editor;
use App\Livewire\Ops\Finance\Journals\Show;
use App\Livewire\Ops\Finance\Periods;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalVoucher;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\PeriodService;
use App\Exceptions\DomainException;
use Livewire\Livewire;

class JournalVoucherTest extends FinanceTestCase
{
    private function lines(string $dr, string $cr): array
    {
        return [
            ['account_id' => (string) Account::where('system_role', 'rent_expense')->value('id'), 'debit' => $dr, 'credit' => '', 'description' => 'Rent', 'cost_center_id' => '', 'branch_id' => ''],
            ['account_id' => (string) Account::where('system_role', 'operating_bank')->value('id'), 'debit' => '', 'credit' => $cr, 'description' => 'Paid', 'cost_center_id' => '', 'branch_id' => ''],
        ];
    }

    public function test_manual_voucher_maker_checker_posts_and_trial_balance_balances(): void
    {
        $t = $this->makeTenant('alpha');
        $maker = $this->accountant($t);
        $checker = $this->financeOfficer($t);

        Livewire::actingAs($maker)->test(Editor::class)
            ->set('narration', 'Branch rent for October')->set('lines', $this->lines('85000', '85000'))
            ->call('save', true)->assertHasNoErrors();

        $jv = JournalVoucher::where('origin', 'manual')->firstOrFail();
        $this->assertSame(JournalStatus::PendingApproval, $jv->status);
        $this->assertDatabaseHas('approval_requests', ['approvable_id' => $jv->id, 'type' => 'journal_voucher', 'status' => 'pending']);

        Livewire::actingAs($checker)->test(Show::class, ['journal' => $jv])->set('comment', 'Verified lease')->call('decide', 'approve')->assertHasNoErrors();

        $jv->refresh();
        $this->assertSame(JournalStatus::Posted, $jv->status);
        $this->assertSame($checker->id, $jv->posted_by);
        $tb = app(FinancialReports::class)->trialBalance(null, today()->toDateString());
        $this->assertTrue($tb['balanced']);
        $this->assertSame('85000.00', app(FinancialReports::class)->roleBalance('rent_expense'));
    }

    public function test_unbalanced_voucher_is_rejected(): void
    {
        $t = $this->makeTenant('alpha');
        Livewire::actingAs($this->accountant($t))->test(Editor::class)
            ->set('narration', 'Unbalanced entry')->set('lines', $this->lines('1000', '900'))
            ->call('save', false)->assertHasErrors('action');
        $this->assertSame(0, JournalVoucher::count());
    }

    public function test_maker_cannot_approve_own_voucher_even_with_checker_role(): void
    {
        $t = $this->makeTenant('alpha');
        $both = $this->staff($t, ['accountant', 'finance_officer']);
        Livewire::actingAs($both)->test(Editor::class)->set('narration', 'Self approval attempt')->set('lines', $this->lines('500', '500'))->call('save', true);
        $jv = JournalVoucher::firstOrFail();

        Livewire::actingAs($both)->test(Show::class, ['journal' => $jv])->set('comment', 'x')->call('decide', 'approve')->assertHasErrors('action');
        $this->assertSame(JournalStatus::PendingApproval, $jv->fresh()->status);
        $this->expectException(DomainException::class);
        app(GeneralLedger::class)->post($jv, $both);
    }

    public function test_posting_into_closed_period_is_blocked(): void
    {
        $t = $this->makeTenant('alpha');
        $first = AccountingPeriod::orderBy('start_date')->firstOrFail();
        app(PeriodService::class)->close($first, $this->admin($t));
        $maker = $this->accountant($t);

        Livewire::actingAs($maker)->test(Editor::class)->set('voucher_date', $first->start_date->addDays(3)->toDateString())
            ->set('narration', 'Back-dated adjustment')->set('lines', $this->lines('100', '100'))->call('save', true)->assertHasErrors('action');
        $this->assertSame(0, JournalVoucher::where('status', 'posted')->count());

        $this->expectException(DomainException::class);
        app(GeneralLedger::class)->postEvent('wallet_topup', $first, ['amount' => '10'], 'test', $first->start_date->copy()->addDay());
    }

    public function test_reopen_requires_permission_and_reason_and_is_audited(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $first = AccountingPeriod::orderBy('start_date')->firstOrFail();
        app(PeriodService::class)->close($first, $admin);

        Livewire::actingAs($this->financeOfficer($t))->test(Periods::class)->call('askReopen', $first->id)->assertForbidden();

        Livewire::actingAs($admin)->test(Periods::class)->call('askReopen', $first->id)->set('reason', 'short')->call('reopen')->assertHasErrors('action');
        $this->assertSame('closed', $first->fresh()->status);

        Livewire::actingAs($admin)->test(Periods::class)->call('askReopen', $first->id)->set('reason', 'Auditor adjustment for prior month accrual')->call('reopen')->assertHasNoErrors();
        $this->assertSame('open', $first->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'fms.period_reopened')->where('reason', 'Auditor adjustment for prior month accrual')->exists());
    }

    public function test_posted_voucher_is_reversed_by_linked_voucher(): void
    {
        $t = $this->makeTenant('alpha');
        $maker = $this->accountant($t);
        Livewire::actingAs($maker)->test(Editor::class)->set('narration', 'To be reversed')->set('lines', $this->lines('700', '700'))->call('save', true);
        $jv = JournalVoucher::firstOrFail();
        Livewire::actingAs($this->financeOfficer($t))->test(Show::class, ['journal' => $jv])->call('decide', 'approve');

        $requester = $this->financeOfficer($t);
        Livewire::actingAs($requester)->test(Show::class, ['journal' => $jv->fresh()])->set('reason', 'Posted in error')->call('reverse')->assertHasNoErrors();
        $this->assertSame(JournalStatus::Posted, $jv->fresh()->status, 'Reversal waits for a second user (QA S6)');
        Livewire::actingAs($requester)->test(Show::class, ['journal' => $jv->fresh()])->call('decide', 'approve')->assertHasErrors('action');
        Livewire::actingAs($this->financeOfficer($t))->test(Show::class, ['journal' => $jv->fresh()])->call('decide', 'approve');
        $this->assertSame(JournalStatus::Reversed, $jv->fresh()->status);
        $this->assertDatabaseHas('journal_vouchers', ['reversal_of_id' => $jv->id, 'status' => 'posted']);
        $this->assertSame('0.00', app(FinancialReports::class)->roleBalance('rent_expense'));
    }
}
