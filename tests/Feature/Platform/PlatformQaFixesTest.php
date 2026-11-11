<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform;
use App\Services\Identity\Mfa;
use App\Services\Platform\PlatformMetrics;
use App\Support\TenantContext;
use App\Support\TenantLogo;
use Livewire\Livewire;

/** Regression tests for platform overview QA fixes (users split, MFA status) and generated tenant logos (M21). */
class PlatformQaFixesTest extends PlatformTestCase
{
    public function test_overview_counts_staff_separately_from_customers_and_shows_admin_mfa(): void
    {
        $t = $this->makeTenant('alpha');
        $this->customer($t, false);
        $this->customer($t, false);
        TenantContext::clear();
        $admin = $this->platformUser();
        $other = $this->platformUser('platform_admin', 'Second Admin');
        Mfa::enrolDemo($admin);

        $h = app(PlatformMetrics::class)->tenantHealth()[$t->id];
        $this->assertSame(1, $h['users'], 'only the tenant admin is a staff user');
        $this->assertSame(2, $h['customers']);

        Livewire::actingAs($admin)->test(Platform\Dashboard::class)
            ->assertSee('Staff users')->assertSee('Platform administrators')->assertSee('Enabled')->assertSee('Not set up')->assertSee($other->name);
    }

    public function test_generated_tenant_logos_are_distinct_svg_badges(): void
    {
        $a = TenantLogo::dataUri('AM', '#15803d', 'circle');
        $b = TenantLogo::dataUri('AN', '#0f766e', 'hexagon');
        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $a);
        $this->assertStringContainsString('<polygon', base64_decode(substr($b, 26)));
        $this->assertStringContainsString('AN', base64_decode(substr($b, 26)));
        $this->assertSame('<svg', substr(TenantLogo::svg('<x>', 'red'), 0, 4));
        $this->assertStringNotContainsString('<x>', TenantLogo::svg('<x>', 'red'), 'initials are escaped');
    }
}
