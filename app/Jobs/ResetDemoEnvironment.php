<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Restores the five demo tenants. Runs the guarded `amcl:demo-reset` command, which itself refuses
 * to run in production. Never retried automatically (a reset is destructive).
 */
class ResetDemoEnvironment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ?int $requestedBy = null) {}

    public function handle(): void
    {
        if (app()->isProduction() || ! config('integrations.demo_mode')) {
            Log::warning('Demo reset refused: production environment or demo mode disabled.');

            return;
        }
        Artisan::call('amcl:demo-reset', ['--force' => true]);
    }
}
