<?php

namespace Tests\Feature\Platform;

use App\Jobs\ResetDemoEnvironment;
use App\Livewire\Platform;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class PlatformPortalTest extends PlatformTestCase
{
    public function test_dashboard_shows_counts_but_no_customer_identities(): void
    {
        $t = $this->makeTenant('alpha');
        $this->customer($t, false, 3, ['full_name' => 'Zarghoon Secretname']);
        TenantContext::clear();
        $admin = $this->platformUser();

        $this->actingAs($admin)->get('/platform')->assertOk()->assertSee('Alpha Finance')->assertDontSee('Zarghoon Secretname');
        Livewire::actingAs($admin)->test(Platform\Dashboard::class)->assertSee('Tenant health')->assertDontSee('Zarghoon');
    }

    public function test_non_platform_users_are_denied(): void
    {
        $t = $this->makeTenant('alpha');
        $this->actingAs($this->admin($t))->get('/platform')->assertForbidden();
        $this->actingAs($this->admin($t))->get('/platform/tenants')->assertForbidden();
    }

    public function test_support_role_cannot_provision_or_reset_demo(): void
    {
        $support = $this->platformUser('platform_support', 'Support');
        $this->actingAs($support)->get('/platform/tenants/create')->assertForbidden();
        $this->actingAs($support)->get('/platform/demo')->assertForbidden();
        $this->actingAs($support)->get('/platform/tenants')->assertOk();
    }

    public function test_provisioning_creates_isolated_tenant_with_forced_password_change(): void
    {
        $admin = $this->platformUser();
        Livewire::actingAs($admin)->test(Platform\Tenants\Create::class)
            ->set('form.code', 'gamma')->set('form.name', 'Gamma Demo Finance')->set('form.city', 'Karachi')
            ->set('features', ['lms', 'dfs', 'fms'])
            ->set('admin_name', 'Gamma Admin')->set('admin_email', 'admin@gamma.test')->set('admin_password', 'Init#123456aZ')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $tenant = Tenant::where('code', 'gamma')->firstOrFail();
        $this->assertSame(['lms', 'dfs', 'fms'], $tenant->features);
        $user = TenantContext::bypass(fn () => User::where('tenant_id', $tenant->id)->where('email', 'admin@gamma.test')->first());
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(TenantContext::run($tenant, fn () => \App\Models\Account::count() > 40));
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'platform.tenant_provisioned']);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        $this->makeTenant('alpha');
        TenantContext::clear();
        Livewire::actingAs($this->platformUser())->test(Platform\Tenants\Create::class)
            ->set('form.code', 'alpha')->set('form.name', 'X')->set('form.city', 'Lahore')
            ->set('admin_name', 'A')->set('admin_email', 'a@x.test')->call('save')->assertHasErrors('form.code');
    }

    public function test_suspension_requires_reason_and_locks_out_tenant_users(): void
    {
        $t = $this->makeTenant('alpha');
        $staff = $this->admin($t);
        TenantContext::clear();
        $admin = $this->platformUser();

        $c = Livewire::actingAs($admin)->test(Platform\Tenants\Show::class, ['tenant' => $t])->call('askStatus', 'suspended')->set('statusReason', '')->call('changeStatus');
        $this->assertSame('active', $t->fresh()->status->value);
        $c->set('statusReason', 'Licence under review by regulator')->call('changeStatus');
        $this->assertSame('suspended', $t->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $t->id, 'action' => 'platform.tenant_status_changed']);

        auth()->logout();
        $this->actingAs($staff)->get('/ops/dashboard')->assertRedirect('/');
    }

    public function test_feature_entitlements_and_admin_password_reset(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::clear();
        $admin = $this->platformUser();
        Livewire::actingAs($admin)->test(Platform\Tenants\Show::class, ['tenant' => $t])
            ->set('features', ['lms', 'fms'])->call('saveFeatures')
            ->call('resetAdminPassword')->assertSet('newCredentials.email', 'admin@alpha.test');
        $this->assertSame(['lms', 'fms'], $t->fresh()->features);
        $this->assertTrue($this->admin($t)->must_change_password);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.tenant_admin_password_reset']);
    }

    public function test_support_access_requires_second_approver_and_is_audited(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::clear();
        $requester = $this->platformUser('platform_admin', 'Requester');
        $approver = $this->platformUser('platform_admin', 'Approver');

        Livewire::actingAs($requester)->test(Platform\SupportAccess::class)
            ->set('tenantId', (string) $t->id)->set('reason', 'Investigating failed Raast callbacks for ticket')->set('ticket', 'SUP-1')->set('hours', 4)
            ->call('requestAccess')->assertHasNoErrors();
        $grant = \App\Models\SupportAccessGrant::firstOrFail();

        Livewire::actingAs($requester)->test(Platform\SupportAccess::class)->call('grant', $grant->id);
        $this->assertNull($grant->fresh()->granted_at, 'Self-grant must be refused.');
        Livewire::actingAs($requester)->test(Platform\SupportAccess::class)->call('openView', $grant->id)->assertSet('summary', null);

        Livewire::actingAs($approver)->test(Platform\SupportAccess::class)->call('grant', $grant->id);
        $this->assertNotNull($grant->fresh()->granted_at);

        Livewire::actingAs($requester)->test(Platform\SupportAccess::class)->call('openView', $grant->id)
            ->assertSet('viewGrant', $grant->id)->assertSee('Support view');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.support_view_accessed', 'tenant_id' => $t->id]);

        Livewire::actingAs($approver)->test(Platform\SupportAccess::class)->call('revoke', $grant->id);
        Livewire::actingAs($requester)->test(Platform\SupportAccess::class)->call('openView', $grant->id)->assertSet('summary', null);
    }

    public function test_demo_reset_needs_phrase_and_a_second_admin_and_is_refused_in_production(): void
    {
        Bus::fake();
        $admin = $this->platformUser();
        $second = $this->platformUser('platform_admin', 'Second Admin');
        Livewire::actingAs($admin)->test(Platform\Demo::class)->set('confirmation', 'reset')->set('reason', 'Restore before client demo')
            ->call('requestReset')->assertHasErrors('action');
        $this->assertSame(0, \App\Models\DemoResetRequest::count());

        Livewire::actingAs($admin)->test(Platform\Demo::class)->set('confirmation', 'RESET DEMO')->set('reason', 'Restore before client demo')
            ->call('requestReset')->assertHasNoErrors();
        $req = \App\Models\DemoResetRequest::firstOrFail();
        Bus::assertNotDispatched(ResetDemoEnvironment::class);

        // the requester cannot approve their own request
        Livewire::actingAs($admin)->test(Platform\Demo::class)->call('approve', $req->id)->assertHasErrors('action');
        Bus::assertNotDispatched(ResetDemoEnvironment::class);

        Livewire::actingAs($second)->test(Platform\Demo::class)->call('approve', $req->id)->assertHasNoErrors();
        Bus::assertDispatched(ResetDemoEnvironment::class);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.demo_reset_approved']);

        app()['env'] = 'production';
        Bus::fake();
        Livewire::actingAs($admin)->test(Platform\Demo::class)->set('confirmation', 'RESET DEMO')->set('reason', 'Restore before client demo')
            ->call('requestReset')->assertHasErrors('action')->assertDontSee('Demo credentials');
        Bus::assertNotDispatched(ResetDemoEnvironment::class);
        app()['env'] = 'testing';
    }

    public function test_platform_audit_excludes_tenant_business_events(): void
    {
        $t = $this->makeTenant('alpha');
        Auditor::record('application.status_changed', null, [], ['secret' => 'x'], tenantId: $t->id);
        TenantContext::clear();
        $admin = $this->platformUser();
        Auditor::record('platform.settings_updated', null, [], []);

        Livewire::actingAs($admin)->test(Platform\Audit::class)->assertSee('platform.tenant_provisioned')->assertDontSee('application.status_changed');
    }

    public function test_failed_jobs_can_be_forgotten_and_settings_saved(): void
    {
        $admin = $this->platformUser();
        DB::table('failed_jobs')->insert(['uuid' => 'abc-123', 'connection' => 'redis', 'queue' => 'callbacks', 'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverSimulatedCallback']),
            'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);
        Livewire::actingAs($admin)->test(Platform\Queues::class)->assertSee('DeliverSimulatedCallback')->call('forget', 'abc-123');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'abc-123']);
        Livewire::actingAs($admin)->test(Platform\Queues::class)->call('retry', 'missing-uuid')->assertHasErrors('action');

        Livewire::actingAs($admin)->test(Platform\Settings::class)->set('platform_name', 'AMCL Cloud')->set('default_features', ['lms', 'wallet'])->call('save');
        $this->assertSame('AMCL Cloud', \App\Services\Platform\PlatformSettings::get('platform_name'));
        $this->assertSame(['lms', 'wallet'], \App\Services\Platform\PlatformSettings::get('default_features'));
    }

    public function test_health_page_never_renders_credentials(): void
    {
        $t = $this->makeTenant('alpha');
        $secret = TenantContext::run($t, fn () => \App\Models\IntegrationSetting::where('provider', 'raast')->first()->credentials['client_secret']);
        TenantContext::clear();
        $this->actingAs($this->platformUser())->get('/platform/health')->assertOk()->assertDontSee($secret);
        $this->actingAs($this->platformUser())->get('/platform/tenants/alpha')->assertOk()->assertDontSee($secret);
    }

    public function test_every_platform_page_renders(): void
    {
        $this->makeTenant('alpha');
        TenantContext::clear();
        $admin = $this->platformUser();
        foreach (['/platform', '/platform/tenants', '/platform/tenants/create', '/platform/tenants/alpha', '/platform/health', '/platform/queues',
            '/platform/audit', '/platform/support-access', '/platform/settings', '/platform/demo'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }
}
