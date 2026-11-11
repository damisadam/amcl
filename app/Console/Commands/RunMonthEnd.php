<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Tenant;
use App\Services\Accounting\FinanceOperations;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Month-end finance jobs per tenant: provisioning and depreciation (idempotent). */
class RunMonthEnd extends Command
{
    protected $signature = 'amcl:month-end {--tenant=}';

    protected $description = 'Run provisioning and depreciation for the current period of each tenant.';

    public function handle(FinanceOperations $ops): int
    {
        foreach (Tenant::where('status', 'active')->when($this->option('tenant'), fn ($q, $c) => $q->where('code', $c))->get() as $tenant) {
            TenantContext::run($tenant, function () use ($ops, $tenant) {
                $prov = $ops->runProvisioning();
                $period = AccountingPeriod::whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->where('status', 'open')->first();
                $dep = $period ? $ops->runDepreciation($period) : '0.00';
                $this->info("{$tenant->code}: provision movement {$prov['net_movement']}, depreciation {$dep}");
            });
        }

        return self::SUCCESS;
    }
}
