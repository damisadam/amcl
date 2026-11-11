<?php

namespace App\Console\Commands;

use App\Services\Audit\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuilds the demo environment: fresh schema + platform + five demo tenants.
 * Refuses to run in production or when demo mode is off. Deletes ONLY the demo document folder.
 */
class DemoReset extends Command
{
    protected $signature = 'amcl:demo-reset {--force : Skip the confirmation prompt} {--tenants= : Comma-separated tenant codes to build (default all five)}';

    protected $description = 'Reset the database and rebuild the deterministic five-tenant demo data set (non-production only).';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to reset: APP_ENV is production.');

            return self::FAILURE;
        }
        if (! config('integrations.demo_mode')) {
            $this->error('Refusing to reset: AMCL_DEMO_MODE is not enabled.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('This ERASES all data in database ['.config('database.connections.mysql.database').']. Continue?')) {
            return self::FAILURE;
        }
        Auditor::record('platform.demo_reset_started', null, [], ['database' => config('database.connections.mysql.database')]);
        Storage::disk(config('filesystems.documents_disk', 'private'))->deleteDirectory('tenants');
        if ($this->option('tenants')) {
            putenv('DEMO_TENANTS='.$this->option('tenants'));
            $_ENV['DEMO_TENANTS'] = $this->option('tenants');
        }
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);
        $this->info('Demo environment rebuilt. Credentials: docs/DEMO_CREDENTIALS.md');

        return self::SUCCESS;
    }
}
