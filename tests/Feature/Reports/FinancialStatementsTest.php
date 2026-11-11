<?php

namespace Tests\Feature\Reports;

use App\Livewire\Ops\Finance\Journals\Show as JournalShow;
use App\Livewire\Ops\Finance\Assets;
use App\Livewire\Ops\Finance\Budgets;
use App\Livewire\Ops\Finance\Expenses;
use App\Livewire\Ops\Finance\Funds;
use App\Livewire\Ops\Finance\Islamic;
use App\Livewire\Ops\Finance\Provisioning;
use App\Livewire\Ops\Finance\Receivables;
use App\Livewire\Ops\Finance\Tax;
use App\Livewire\Ops\Finance\Treasury;
use App\Livewire\Ops\Reports\Financial;
use App\Livewire\Ops\Reports\Lending;
use App\Livewire\Ops\Reports\Regulatory;
use App\Livewire\Ops\Reports\Wallet as WalletReport;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ExpenseClaim;
use App\Models\Fund;
use App\Models\JournalVoucher;
use App\Models\LoanAccount;
use App\Services\Accounting\FinanceOperations;
use App\Services\Accounting\FinancialReports;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Lending\RepaymentService;
use App\Services\Reports\LendingReports;
use App\Services\Reports\WalletReports;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\TenantContext;
use Livewire\Livewire;
use Tests\Feature\Finance\FinanceTestCase;

class FinancialStatementsTest extends FinanceTestCase
{
    /** Funding, disbursement, repayments, wallet, expenses and assets → TB and BS balance; reports agree with sub-ledgers. */
    public function test_statements_balance_after_mixed_activity_and_reports_agree_with_records(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $fund = Fund::create(['code' => 'F1', 'name' => 'Mudarabah Pool', 'type' => 'investor', 'committed_amount' => '5000000', 'investor_profit_share_pct' => 60]);
        app(FinanceOperations::class)->recordFundReceipt($fund, '2000000.00', $admin);

        $loan = $this->disbursedLoan($t);
        TenantContext::set($t);
        $first = $loan->instalments()->first();
        app(RepaymentService::class)->recordManual($loan, (string) $first->total_due, 'cash', 'RCPT-1', $this->staff($t, 'accountant'));

        $cu = $this->customer($t);
        $w = $cu->customer->wallet()->firstOrFail();
        app(WalletService::class)->topUp($w, '15000', 'raast', 'wk1', 'success');

        $maker = $this->accountant($t);
        Livewire::actingAs($maker)->test(Expenses::class)->set('form.expense_account_id', (string) Account::where('system_role', 'it_expense')->value('id'))
            ->set('form.amount', '12000')->set('form.description', 'Internet for branch')->call('submit')->assertHasNoErrors();
        $claim = ExpenseClaim::firstOrFail();
        app(ApprovalEngine::class)->approve($claim->approvalRequest, $this->financeOfficer($t), 'ok');
        $this->assertSame('paid', $claim->fresh()->status);

        Livewire::actingAs($admin)->test(Assets::class)->set('form.name', 'Branch laptop')->set('form.cost', '180000')->call('acquire')->assertHasNoErrors();
        Livewire::actingAs($admin)->test(Assets::class)->call('depreciate')->assertHasNoErrors();

        $r = app(FinancialReports::class);
        $tb = $r->trialBalance(null, today()->toDateString());
        $this->assertTrue($tb['balanced'], 'Trial balance must balance');
        $bs = $r->balanceSheet(today()->toDateString());
        $this->assertTrue($bs['balanced'], 'Balance sheet must balance');

        // GL financing receivable equals LMS principal outstanding (sub-ledger).
        $this->assertSame(Money::of(LoanAccount::sum('principal_outstanding')), $r->netFinancingReceivable());
        // gross receivable carries the contract value (principal + profit + financed charges outstanding)
        $this->assertSame(Money::of(LoanAccount::selectRaw('SUM(principal_outstanding + profit_outstanding + charges_outstanding) v')->value('v')), $r->roleBalance('financing_receivable'));
        // Wallet three-way check.
        $this->assertTrue((new WalletReports)->threeWay()['ok']);
        // Lending portfolio report equals sub-ledger.
        $portfolio = (new LendingReports)->portfolio('product');
        $this->assertSame((string) $loan->fresh()->principal_outstanding, $portfolio[0]['principal']);

        // Every screen and report tab renders with real data.
        $u = $admin;
        foreach (['tb', 'gl', 'pl', 'bs', 'cf', 'bva', 'targets', 'branches'] as $tab) {
            Livewire::actingAs($u)->test(Financial::class)->set('tab', $tab)->assertOk();
        }
        foreach (['pipeline', 'disbursements', 'portfolio', 'quality', 'collections', 'field'] as $tab) {
            Livewire::actingAs($u)->test(Lending::class)->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($u)->test(Lending::class)->set('tab', 'portfolio')->assertSee($loan->product->name);
        foreach (['quality', 'products', 'outreach', 'raast', 'complaints'] as $tab) {
            Livewire::actingAs($u)->test(Regulatory::class)->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($u)->test(Regulatory::class)->assertSee('Simulated format');
        Livewire::actingAs($u)->test(WalletReport::class)->assertSee('All three agree');
        foreach (['accounts', 'transfers', 'settlements', 'liquidity'] as $tab) {
            Livewire::actingAs($u)->test(Treasury::class)->set('tab', $tab)->assertOk();
        }
        foreach (['portfolio', 'aging', 'other'] as $tab) {
            Livewire::actingAs($u)->test(Receivables::class)->set('tab', $tab)->assertOk();
        }
        foreach (['sources', 'report', 'distribution'] as $tab) {
            Livewire::actingAs($u)->test(Funds::class)->set('tab', $tab)->set('reportFund', (string) $fund->id)->assertOk();
        }
        foreach (['charity', 'profit', 'audit'] as $tab) {
            Livewire::actingAs($u)->test(Islamic::class)->set('tab', $tab)->assertOk();
        }
        Livewire::actingAs($u)->test(Budgets::class)->set('tab', 'forecast')->assertOk();
        Livewire::actingAs($u)->test(Tax::class)->assertOk();
        Livewire::actingAs($u)->test(Provisioning::class)->call('run')->assertHasNoErrors();

        // Drill-down from a system voucher to its source.
        $jv = JournalVoucher::where('event_type', 'loan_disbursement')->firstOrFail();
        Livewire::actingAs($u)->test(JournalShow::class, ['journal' => $jv])->assertSee('Disbursement');
    }

    public function test_reports_are_isolated_per_tenant(): void
    {
        $a = $this->makeTenant('alpha');
        $fundA = Fund::create(['code' => 'FA', 'name' => 'Alpha Equity', 'type' => 'own_equity', 'committed_amount' => '9000000']);
        app(FinanceOperations::class)->recordFundReceipt($fundA, '7777777.00', $this->admin($a));
        $jvA = JournalVoucher::firstOrFail();

        $b = $this->makeTenant('bravo');
        TenantContext::set($b);
        $tb = app(FinancialReports::class)->trialBalance(null, today()->toDateString());
        $this->assertSame([], $tb['rows']);
        $this->assertSame('0.00', app(FinancialReports::class)->roleBalance('operating_bank'));

        $adminB = $this->admin($b);
        Livewire::actingAs($adminB)->test(Financial::class)->assertDontSee('7,777,777.00');
        $this->actingAs($adminB)->get('/ops/finance/journals/'.$jvA->id)->assertNotFound();
        $this->actingAs($adminB)->get('/ops/finance/accounts/'.Account::withoutGlobalScopes()->where('tenant_id', $a->id)->value('id').'/ledger')->assertNotFound();
    }

    public function test_csv_export_requires_permission_and_is_audited(): void
    {
        $t = $this->makeTenant('alpha');
        Livewire::actingAs($this->admin($t))->test(Financial::class)->call('export')->assertFileDownloaded();
        $this->assertTrue(AuditLog::where('action', 'report.exported')->exists());

        // Accountants can view finance screens but hold no reports.export permission.
        $acct = $this->staff($t, ['accountant', 'finance_officer']);
        $acct->roles()->detach(\App\Models\Role::where('tenant_id', $t->id)->where('key', 'finance_officer')->value('id'));
        $acct->flushPermissionCache();
        Livewire::actingAs($acct->fresh())->test(\App\Livewire\Ops\Finance\Journals\Index::class)->call('export')->assertForbidden();
    }
}
