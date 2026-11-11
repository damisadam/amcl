<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\Demo\DemoCatalog;
use Illuminate\Database\Seeder;

/** Permission catalogue, platform roles and (outside production) demo platform users. */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        TenantProvisioner::syncPermissions();
        if (app()->isProduction()) {
            return;
        }
        config(['integrations.demo_mode' => true]);
        foreach ([['Platform Administrator', 'admin@platform.demo', 'platform_admin'], ['Platform Support Engineer', 'support@platform.demo', 'platform_support'],
            ['Platform Security Officer', 'security@platform.demo', 'platform_admin']] as [$name, $email, $role]) {
            $u = User::firstOrNew(['tenant_id' => null, 'email' => $email]);
            $u->name = $name;
            $u->forceFill(['type' => UserType::Platform, 'status' => UserStatus::Active, 'password' => DemoCatalog::PASSWORD, 'password_changed_at' => now()])->save();
            $u->roles()->syncWithoutDetaching([Role::whereNull('tenant_id')->where('key', $role)->value('id')]);
            \App\Services\Identity\Mfa::enrolDemo($u);
        }
    }
}
