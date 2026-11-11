<?php

namespace Tests\Feature\Finance;

use App\Livewire\Ops\Finance\Accounts;
use App\Livewire\Ops\Finance\Budgets;
use App\Livewire\Ops\Finance\Funds;
use App\Livewire\Ops\Finance\Islamic;
use App\Livewire\Ops\Finance\PostingRules;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\CharityDisbursement;
use App\Models\Fund;
use App\Models\PostingRule;
use App\Models\ProfitDistribution;
use App\Services\Accounting\GeneralLedger;
use Livewire\Livewire;

class FinanceConfigurationTest extends FinanceTestCase
{
    public function test_posting_rules_can_be_remapped_but_not_structurally_broken(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $other = Account::where('system_role', 'other_income')->value('id');

        $c = Livewire::actingAs($admin)->test(PostingRules::class)->call('select', 'wallet_topup');
        $lines = $c->get('lines');
        $lines[1]['account_id'] = (string) $other; // remap credit side: allowed
        $c->set('lines', $lines)->call('save')->assertHasNoErrors();
        $this->assertSame($other, PostingRule::where('event_type', 'wallet_topup')->where('side', 'credit')->value('account_id'));

        $lines[1]['side'] = 'debit'; // breaks balancing: refused
        $c->set('lines', $lines)->call('save')->assertHasErrors('action');
        $this->assertSame(1, PostingRule::where('event_type', 'wallet_topup')->where('side', 'credit')->count());

        Livewire::actingAs($this->staff($t, 'accountant'))->test(PostingRules::class)->call('save')->assertForbidden();
    }

    public function test_chart_of_accounts_validation(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $expenseHead = Account::where('code', '5000')->value('id');
        Livewire::actingAs($admin)->test(Accounts::class)->call('newAccount', $expenseHead)
            ->set('form.code', '5960')->set('form.name', 'Training')->call('saveAccount')->assertHasNoErrors();
        $this->assertDatabaseHas('accounts', ['code' => '5960', 'parent_id' => $expenseHead, 'type' => 'expense']);
        Livewire::actingAs($admin)->test(Accounts::class)->call('newAccount', $expenseHead)
            ->set('form.code', '5960')->set('form.name', 'Dup')->call('saveAccount')->assertHasErrors('action');
        Livewire::actingAs($admin)->test(Accounts::class)->call('newAccount', $expenseHead)
            ->set('form.code', '5970')->set('form.name', 'Wrong type')->set('form.type', 'income')->call('saveAccount')->assertHasErrors('action');
    }

    public function test_budget_requires_a_different_approver(): void
    {
        $t = $this->makeTenant('alpha');
        $preparer = $this->accountant($t);
        Livewire::actingAs($preparer)->test(Budgets::class)->call('create')
            ->set('lines', [['account_id' => (string) Account::where('system_role', 'rent_expense')->value('id'), 'accounting_period_id' => '', 'cost_center_id' => '', 'amount' => '1200000']])
            ->call('save')->assertHasNoErrors();
        $budget = Budget::firstOrFail();
        $this->assertSame(12, $budget->lines()->count()); // spread across 12 periods
        $this->assertSame('1200000.00', (string) $budget->lines()->sum('amount'));

        Livewire::actingAs($preparer)->test(Budgets::class)->call('approve', $budget->id)->assertHasErrors('action');
        Livewire::actingAs($this->admin($t))->test(Budgets::class)->call('approve', $budget->id)->assertHasNoErrors();
        $this->assertSame('approved', $budget->fresh()->status);
    }

    public function test_profit_distribution_and_charity_request(): void
    {
        $t = $this->makeTenant('alpha');
        $loan = $this->disbursedLoan($t);
        \App\Support\TenantContext::set($t);
        $fund = Fund::create(['code' => 'MP', 'name' => 'Mudarabah Pool', 'type' => 'investor', 'committed_amount' => '9000000', 'investor_profit_share_pct' => 60]);
        $loan->update(['fund_id' => $fund->id]);
        $first = $loan->instalments()->first();
        app(\App\Services\Lending\RepaymentService::class)->recordManual($loan, (string) $first->total_due, 'cash', 'R1', $this->accountant($t));
        // Profit income lines carry fund_id dimension from the loan.
        $period = app(GeneralLedger::class)->openPeriodFor(today());

        $fo = $this->financeOfficer($t);
        Livewire::actingAs($fo)->test(Funds::class)->set('distFund', (string) $fund->id)->set('distPeriod', (string) $period->id)->call('calculate')->assertHasNoErrors();
        $d = ProfitDistribution::firstOrFail();
        $this->assertTrue(\App\Support\Money::isPositive($d->gross_profit));
        $this->assertSame(\App\Support\Money::pct($d->gross_profit, '60'), (string) $d->investor_share);
        Livewire::actingAs($fo)->test(Funds::class)->call('post', $d->id)->assertHasNoErrors();
        $this->assertSame('posted', $d->fresh()->status);

        // Charity: cannot request more than the fund holds (no late payments collected yet).
        Livewire::actingAs($fo)->test(Islamic::class)->set('form', ['beneficiary' => 'Edhi Foundation', 'amount' => '100', 'purpose' => 'Flood relief'])->call('requestCharity')->assertHasErrors('action');
        $this->assertSame(0, CharityDisbursement::count());
    }
}
