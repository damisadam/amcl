<?php

namespace Database\Seeders;

use App\Support\TenantContext;
use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\Demo\DemoCredentials;
use Database\Seeders\Demo\TenantDemoBuilder;
use Illuminate\Database\Seeder;

/**
 * Five fictional demo tenants with complete, relationally consistent data produced by the real
 * workflows (see docs/DEMO_DATA.md). Never runs in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_if(app()->isProduction(), 403, 'Demo data cannot be seeded in production.');
        // Seeding drives provider simulators synchronously and must not depend on Reverb/Redis workers.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null', 'integrations.demo_mode' => true,
            // Seeding is a system process: interactive MFA step-up does not apply (demo users are still enrolled for sign-in).
            'security.mfa.enforce' => false]);

        $only = env('DEMO_TENANTS') ? explode(',', env('DEMO_TENANTS')) : null;
        foreach (DemoCatalog::TENANTS as $def) {
            if ($only && ! in_array($def['code'], $only, true)) {
                continue;
            }
            $started = microtime(true);
            $this->command?->info("Building demo tenant {$def['name']} ({$def['code']})…");
            (new TenantDemoBuilder($def, fn ($m) => $this->command?->line($m)))->build();
            TenantContext::clear();
            auth()->forgetUser();
            $this->command?->info(sprintf('  done in %.1fs', microtime(true) - $started));
        }
        DemoCredentials::write(base_path('docs/DEMO_CREDENTIALS.md'));
    }
}
