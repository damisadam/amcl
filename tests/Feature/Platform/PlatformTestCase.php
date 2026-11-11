<?php

namespace Tests\Feature\Platform;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

abstract class PlatformTestCase extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function platformUser(string $role = 'platform_admin', string $name = 'Platform Admin'): User
    {
        TenantProvisioner::syncPermissions();
        $u = new User(['name' => $name, 'email' => uniqid('p').'@platform.test']);
        $u->forceFill(['tenant_id' => null, 'type' => UserType::Platform, 'status' => UserStatus::Active, 'password' => 'Password@123', 'password_changed_at' => now()])->save();
        $u->roles()->attach(Role::whereNull('tenant_id')->where('key', $role)->value('id'));

        return $u->fresh();
    }
}
