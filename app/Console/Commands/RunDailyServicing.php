<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Models\LoanApplication;
use App\Models\Tenant;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ServicingService;
use App\Support\Correlation;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Daily per-tenant servicing: cooling-off completion, delinquency/late amounts, reminders, PTP evaluation. */
class RunDailyServicing extends Command
{
    protected $signature = 'amcl:daily-servicing {--tenant= : Limit to one tenant code} {--no-reminders}';

    protected $description = 'Run daily loan servicing for every active tenant (each in its own tenant context).';

    public function handle(ServicingService $servicing, AgreementService $agreements): int
    {
        $tenants = Tenant::where('status', 'active')->when($this->option('tenant'), fn ($q, $c) => $q->where('code', $c))->get();
        foreach ($tenants as $tenant) {
            Correlation::fresh();
            TenantContext::run($tenant, function () use ($tenant, $servicing, $agreements) {
                $released = 0;
                LoanApplication::where('status', ApplicationStatus::CoolingOff->value)->where('cooling_off_ends_at', '<=', now())->get()
                    ->each(function ($app) use ($agreements, &$released) {
                        $agreements->completeCoolingOff($app);
                        $released++;
                    });
                $stats = $servicing->runDelinquency();
                $servicing->evaluatePromises();
                $sent = $this->option('no-reminders') ? 0 : $servicing->sendReminders();
                $this->info("{$tenant->code}: cooling-off released {$released}, loans {$stats['loans']}, overdue {$stats['overdue']}, reminders {$sent}");
            });
        }

        return self::SUCCESS;
    }
}
