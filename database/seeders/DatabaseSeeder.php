<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
        if (app()->isProduction()) {
            $this->command?->warn('Production environment: demo tenants are NOT seeded.');

            return;
        }
        $this->call(DemoSeeder::class);
    }
}
