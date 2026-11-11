<?php

namespace Tests\Feature\Core;

use App\Enums\JournalStatus;
use App\Exceptions\DomainException;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Services\Accounting\GeneralLedger;
use App\Services\Accounting\PeriodService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function lines(string $dr = '100', string $cr = '100'): array
    {
        return [
            ['account_id' => Account::where('system_role', 'general_expense')->value('id'), 'debit' => $dr, 'credit' => 0],
            ['account_id' => Account::where('system_role', 'operating_bank')->value('id'), 'debit' => 0, 'credit' => $cr],
        ];
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $t = $this->makeTenant('alpha');
        $this->expectException(DomainException::class);
        app(GeneralLedger::class)->createManual(['voucher_date' => today()->toDateString(), 'narration' => 'x'], $this->lines('100', '90'), $this->admin($t));
    }

    public function test_maker_cannot_post_own_voucher_and_posted_voucher_is_immutable(): void
    {
        $t = $this->makeTenant('alpha');
        $gl = app(GeneralLedger::class);
        $maker = $this->staff($t, 'accountant');
        $jv = $gl->createManual(['voucher_date' => today()->toDateString(), 'narration' => 'Test'], $this->lines(), $maker);
        try {
            $gl->post($jv, $maker);
            $this->fail('self post');
        } catch (DomainException) {
        }
        $gl->post($jv, $this->staff($t, 'finance_officer'));
        $this->assertSame(JournalStatus::Posted, $jv->fresh()->status);

        $this->expectException(\LogicException::class);
        $jv->fresh()->update(['narration' => 'tampered']);
    }

    public function test_reversal_and_closed_period_controls(): void
    {
        $t = $this->makeTenant('alpha');
        $gl = app(GeneralLedger::class);
        $maker = $this->staff($t, 'accountant');
        $fo = $this->staff($t, 'finance_officer');
        $jv = $gl->createManual(['voucher_date' => today()->toDateString(), 'narration' => 'Test'], $this->lines(), $maker);
        $gl->post($jv, $fo);
        $rev = $gl->reverse($jv->fresh(), 'error', $fo);
        $this->assertSame(JournalStatus::Reversed, $jv->fresh()->status);
        $this->assertSame($jv->id, $rev->reversal_of_id);
        $this->assertSame($rev->id, $gl->reverse($jv->fresh(), 'again', $fo)->id, 'reversal is idempotent');

        // close last month's period, then posting dated in it must fail
        $periods = app(PeriodService::class);
        foreach (AccountingPeriod::where('end_date', '<', today()->startOfMonth())->orderBy('start_date')->get() as $p) {
            $periods->close($p, $fo);
        }
        $last = AccountingPeriod::where('end_date', '<', today()->startOfMonth())->orderByDesc('start_date')->first();
        $draft = $gl->createManual(['voucher_date' => $last->end_date->toDateString(), 'narration' => 'backdated'], $this->lines(), $maker);
        try {
            $gl->post($draft, $fo);
            $this->fail('closed period');
        } catch (DomainException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }
        // reopening needs the restricted permission
        try {
            $periods->reopen($last, $fo, 'Need to post an adjustment for audit');
            $this->fail('reopen without permission');
        } catch (DomainException) {
        }
        $periods->reopen($last, $this->admin($t), 'Need to post an adjustment for audit');
        $gl->post($draft->fresh(), $fo);
        $this->assertSame(JournalStatus::Posted, $draft->fresh()->status);
    }

    public function test_system_postings_are_idempotent_and_tenant_scoped(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        TenantContext::set($a);
        $gl = app(GeneralLedger::class);
        $fund = \App\Models\Fund::create(['code' => 'F', 'name' => 'F', 'type' => 'investor', 'committed_amount' => '1000']);
        $j1 = $gl->postEvent('fund_receipt', $fund, ['amount' => '500'], 'Receipt', idempotencyKey: 'same');
        $j2 = $gl->postEvent('fund_receipt', $fund, ['amount' => '500'], 'Receipt', idempotencyKey: 'same');
        $this->assertSame($j1->id, $j2->id);
        TenantContext::set($b);
        $this->assertSame(0, \App\Models\JournalVoucher::count());
        $this->assertSame([], app(\App\Services\Accounting\FinancialReports::class)->trialBalance(null, today()->toDateString())['rows']);
    }
}
