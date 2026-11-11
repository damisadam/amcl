<?php

namespace Tests\Feature\Ops\Admin;

use App\Livewire\Ops\Admin\Audit;
use App\Livewire\Ops\Admin\Configuration;
use App\Livewire\Ops\Admin\Integrations;
use App\Livewire\Ops\Admin\Organization;
use App\Livewire\Ops\Admin\Roles;
use App\Livewire\Ops\Admin\Users;
use App\Livewire\Ops\Admin\Workflows;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\BlacklistEntry;
use App\Models\Branch;
use App\Models\IntegrationSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_admin_creates_user_with_multiple_roles_and_temp_password(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $roles = Role::where('tenant_id', $t->id)->whereIn('key', ['branch_manager', 'cro'])->pluck('id')->map(fn ($i) => (string) $i)->all();

        $c = Livewire::actingAs($admin)->test(Users::class)->call('create')
            ->set('form.name', 'Sara Ahmed')->set('form.email', 'sara@alpha.test')->set('roleIds', $roles)->call('save')->assertHasNoErrors();
        $u = User::where('email', 'sara@alpha.test')->firstOrFail();
        $this->assertSame($t->id, $u->tenant_id);
        $this->assertEqualsCanonicalizing(['branch_manager', 'cro'], $u->roles()->pluck('key')->all());
        $this->assertTrue($u->must_change_password);
        $this->assertNotNull($c->get('tempPassword'));
        $this->assertTrue(AuditLog::where('action', 'users.created')->exists());
    }

    public function test_cannot_change_own_roles_or_remove_last_tenant_admin(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $cro = Role::where('tenant_id', $t->id)->where('key', 'cro')->value('id');
        Livewire::actingAs($admin)->test(Users::class)->call('edit', $admin->id)->set('roleIds', [(string) $cro])->call('save')->assertHasErrors('action');
        $this->assertTrue($admin->fresh()->hasRole('tenant_admin'));

        // With two admins, one may deactivate the other.
        $second = $this->staff($t, 'tenant_admin');
        Livewire::actingAs($second)->test(Users::class)->call('openStatus', $admin->id, 'inactive')->set('statusReason', 'Left the company')->call('changeStatus')->assertHasNoErrors();
        TenantContext::set($t);
        $this->assertSame('inactive', $admin->fresh()->status->value);
    }

    public function test_last_active_tenant_admin_is_protected(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        $manager = $this->staff($t, 'branch_manager');
        $role = Role::forceCreate(['tenant_id' => $t->id, 'key' => 'user_admin', 'name' => 'User admin', 'scope' => 'tenant']);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', ['users.view', 'users.manage'])->pluck('id'));
        $manager->roles()->attach($role->id);
        Livewire::actingAs($manager->fresh())->test(Users::class)->call('openStatus', $admin->id, 'suspended')->set('statusReason', 'Testing protection')->call('changeStatus')
            ->assertHasErrors('action');
        $this->assertSame('active', $admin->fresh()->status->value);
    }

    public function test_role_permission_change_takes_effect(): void
    {
        $t = $this->makeTenant('alpha');
        $officer = $this->staff($t, 'loan_officer');
        $this->assertFalse($officer->hasPermission('wallet.view'));
        $role = Role::where('tenant_id', $t->id)->where('key', 'loan_officer')->first();
        $keys = $role->permissions()->pluck('key')->push('wallet.view')->all();

        Livewire::actingAs($this->admin($t))->test(Roles::class)->call('select', $role->id)->set('selected', $keys)->call('save');
        $this->assertTrue(User::find($officer->id)->hasPermission('wallet.view'));
        $this->assertTrue(AuditLog::where('action', 'roles.permissions_changed')->where('auditable_id', $role->id)->exists());

        // tenant_admin role is editable, but user & role administration can never be removed (lock-out protection)
        $adminRole = Role::where('tenant_id', $t->id)->where('key', 'tenant_admin')->first();
        Livewire::actingAs($this->admin($t))->test(Roles::class)->call('select', $adminRole->id)->set('selected', [])->call('save');
        $this->assertEqualsCanonicalizing(Roles::ADMIN_ESSENTIAL, $adminRole->permissions()->pluck('key')->all());
    }

    public function test_roles_screen_denied_without_permission(): void
    {
        $t = $this->makeTenant('alpha');
        Livewire::actingAs($this->staff($t, 'loan_officer'))->test(Roles::class)->assertForbidden();
    }

    public function test_integration_secrets_are_never_rendered_and_updates_are_encrypted(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $setting = IntegrationSetting::where('provider', 'raast')->first();
        $secret = $setting->credentials['client_secret'];

        $c = Livewire::actingAs($this->admin($t))->test(Integrations::class)->set('provider', 'raast');
        $c->assertSet('credentials.client_secret', '••••••••'.substr($secret, -4));
        // Neither the rendered HTML nor the Livewire snapshot sent to the browser contains the secret.
        $this->assertStringNotContainsString($secret, $c->html(false));

        $c->set('credentials.client_secret', 'NEW-SECRET-VALUE-123456')->call('save')->assertHasNoErrors();
        $fresh = IntegrationSetting::where('provider', 'raast')->first();
        $this->assertSame('NEW-SECRET-VALUE-123456', $fresh->credentials['client_secret']);
        $raw = \DB::table('integration_settings')->where('id', $fresh->id)->value('credentials');
        $this->assertStringNotContainsString('NEW-SECRET-VALUE', $raw, 'Credentials must be encrypted at rest.');
        $this->assertStringNotContainsString('NEW-SECRET-VALUE', AuditLog::where('action', 'integration.settings_updated')->latest('id')->first()->toJson());
        $this->assertStringNotContainsString('NEW-SECRET-VALUE-123456', Livewire::actingAs($this->admin($t))->test(Integrations::class)->set('provider', 'raast')->html(false));
    }

    public function test_go_live_requires_permission(): void
    {
        $t = $this->makeTenant('alpha');
        $u = $this->staff($t, 'branch_manager');
        $role = Role::forceCreate(['tenant_id' => $t->id, 'key' => 'integ', 'name' => 'Integrations', 'scope' => 'tenant']);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', ['integrations.view', 'integrations.manage'])->pluck('id'));
        $u->roles()->attach($role->id);
        Livewire::actingAs($u->fresh())->test(Integrations::class)->set('provider', 'nadra_verisys')->set('settings.mode', 'live')->call('save')->assertHasErrors('settings.mode');
        TenantContext::set($t);
        $this->assertSame('demo', IntegrationSetting::where('provider', 'nadra_verisys')->value('mode'));
    }

    public function test_workflow_stage_editor(): void
    {
        $t = $this->makeTenant('alpha');
        TenantContext::set($t);
        $wf = ApprovalWorkflow::where('type', 'loan')->first();
        $risk = Role::where('tenant_id', $t->id)->where('key', 'risk_officer')->value('id');
        $c = Livewire::actingAs($this->admin($t))->test(Workflows::class)->set('workflowId', $wf->id)
            ->call('editStage')->set('stage.name', 'Extra risk review')->set('stage.role_id', (string) $risk)->set('stage.min_amount', '500000')->call('saveStage')->assertHasNoErrors();
        $stages = $wf->stages()->get();
        // New stages are inserted before Disbursement Approval, which always stays last (QA H8)
        $this->assertSame('Disbursement Approval', $stages->last()->name);
        $added = $stages->firstWhere('name', 'Extra risk review');
        $this->assertSame($stages->count() - 1, $added->sequence);
        $c->call('move', $added->id, -1);
        $this->assertSame($stages->count() - 2, $added->fresh()->sequence);
        $c->call('removeStage', $added->id);
        $this->assertNull($added->fresh());
        $this->assertTrue(AuditLog::where('action', 'approvals.stage_added')->exists());
    }

    public function test_configuration_settings_and_blacklist(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(Configuration::class)->set('group', 'lending')->set('values.reapplication_wait_days', '45')->call('saveGroup')->assertHasNoErrors();
        TenantContext::set($t);
        $this->assertSame(45, TenantSettings::get('lending', 'reapplication_wait_days'));

        $cu = $this->customer($t, false);
        $cnic = $cu->customer->cnic;
        Livewire::actingAs($admin)->test(Configuration::class)->set('tab', 'blacklist')
            ->set('blacklist.name', 'Bad Actor')->set('blacklist.cnic', $cnic)->set('blacklist.reason', 'fraud')->set('blacklist.notes', 'Confirmed fraud case')->call('addBlacklist')->assertHasNoErrors();
        TenantContext::set($t);
        $this->assertTrue(BlacklistEntry::where('cnic', $cnic)->exists());
        $this->assertTrue($cu->customer->fresh()->is_blacklisted);

        // cross-tenant transfers can never be enabled
        Livewire::actingAs($admin)->test(Configuration::class)->set('group', 'wallet')->set('values.allow_cross_tenant_transfers', true)->call('saveGroup')
            ->assertHasErrors('values.allow_cross_tenant_transfers');
    }

    public function test_organization_hierarchy(): void
    {
        $t = $this->makeTenant('alpha');
        $c = Livewire::actingAs($this->admin($t))->test(Organization::class)
            ->call('newNode', 'region')->set('node.code', 'PJ')->set('node.name', 'Punjab')->call('saveNode')->assertHasNoErrors();
        TenantContext::set($t);
        $region = \App\Models\Region::where('code', 'PJ')->firstOrFail();
        $c->call('newNode', 'area', $region->id)->set('node.code', 'LHR')->set('node.name', 'Lahore')->call('saveNode')->assertHasNoErrors();
        $area = \App\Models\Area::where('code', 'LHR')->firstOrFail();
        $c->call('newNode', 'branch', $area->id)->set('node.code', 'LHR-01')->set('node.name', 'Gulberg')->set('node.city', 'Lahore')->call('saveNode')->assertHasNoErrors();
        $this->assertTrue(Branch::where('code', 'LHR-01')->where('area_id', $area->id)->exists());
    }

    public function test_audit_export_is_permissioned_and_audited(): void
    {
        $t = $this->makeTenant('alpha');
        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(Audit::class)->assertOk();
        $this->actingAs($admin)->get(route('ops.admin.audit.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        TenantContext::set($t);
        $this->assertTrue(AuditLog::where('action', 'audit.exported')->exists());

        $cro = $this->staff($t, 'compliance_officer'); // audit.view without audit.export
        $this->actingAs($cro)->get(route('ops.admin.audit.export'))->assertForbidden();
    }

    public function test_audit_log_is_tenant_scoped(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        TenantContext::set($b);
        $foreign = AuditLog::where('tenant_id', $b->id)->firstOrFail();
        TenantContext::set($a);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->admin($a))->test(Audit::class)->call('view', $foreign->id);
    }
}
