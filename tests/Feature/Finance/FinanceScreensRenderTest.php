<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\ReconciliationBatch;
use App\Services\Accounting\ReconciliationService;
use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Every finance & report screen renders for an authorized user and is refused for an unauthorized one. */
class FinanceScreensRenderTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_all_finance_and_report_pages_render_for_tenant_admin(): void
    {
        $tenant = $this->makeTenant('alpha');
        // Finance screens are for finance roles; Tenant Admin no longer holds operational finance permissions (QA H7).
        $admin = $this->staff($tenant, ['finance_officer', 'accountant']);
        $admin->roles()->attach(\App\Models\Role::where('tenant_id', $tenant->id)->where('key', 'tenant_admin')->value('id'));
        $admin = $admin->fresh('roles');
        $account = Account::where('system_role', 'operating_bank')->first();
        $batch = app(ReconciliationService::class)->run(BankAccount::first(), now()->subMonth()->toDateString(), today()->toDateString(), $admin);

        $urls = ['/ops/finance', '/ops/finance/journals', '/ops/finance/journals/create', '/ops/finance/accounts', "/ops/finance/accounts/{$account->id}/ledger",
            '/ops/finance/posting-rules', '/ops/finance/periods', '/ops/finance/treasury', '/ops/finance/reconciliation', "/ops/finance/reconciliation/{$batch->id}",
            '/ops/finance/payables', '/ops/finance/receivables', '/ops/finance/funds', '/ops/finance/provisioning', '/ops/finance/assets', '/ops/finance/payroll',
            '/ops/finance/expenses', '/ops/finance/budgets', '/ops/finance/islamic', '/ops/finance/tax',
            '/ops/reports/lending', '/ops/reports/wallet', '/ops/reports/financial', '/ops/reports/regulatory'];

        foreach ($urls as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_loan_officer_cannot_open_finance_screens(): void
    {
        $tenant = $this->makeTenant('alpha');
        $officer = $this->staff($tenant, 'loan_officer');
        foreach (['/ops/finance', '/ops/finance/journals', '/ops/finance/payables', '/ops/reports/financial'] as $url) {
            $this->actingAs($officer)->get($url)->assertForbidden();
        }
    }
}
