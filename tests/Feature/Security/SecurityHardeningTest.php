<?php

namespace Tests\Feature\Security;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\Mfa;
use App\Services\Identity\Totp;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Regression tests for QA findings S1 (MFA), S2 (no tenant list on login), S3 (CSP / headers). */
class SecurityHardeningTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.mfa.enforce' => true]);
    }

    private function platformAdmin(): User
    {
        TenantProvisioner::syncPermissions();
        $u = new User(['name' => 'Plat Admin', 'email' => uniqid('p').'@platform.test']);
        $u->forceFill(['tenant_id' => null, 'type' => UserType::Platform, 'status' => UserStatus::Active, 'password' => 'Password@123', 'password_changed_at' => now()])->save();
        $u->roles()->attach(Role::whereNull('tenant_id')->where('key', 'platform_admin')->value('id'));

        return $u->fresh();
    }

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('287082', Totp::code($secret, Totp::timestep(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::timestep(1111111109)));
        $this->assertSame('005924', Totp::code($secret, Totp::timestep(1234567890)));
        $this->assertNotNull(Totp::verify($secret, '287082', 1, 59));
        $this->assertNull(Totp::verify($secret, '000000', 1, 59));
    }

    public function test_platform_user_must_enrol_then_pass_challenge_and_livewire_is_blocked_meanwhile(): void
    {
        $u = $this->platformAdmin();
        $this->actingAs($u)->get('/platform')->assertRedirect(route('mfa.setup'));
        $this->actingAs($u)->post('/livewire/update', [])->assertForbidden();

        $this->actingAs($u)->get('/account/mfa/setup')->assertOk()->assertSee('Set up two-step verification');
        $secret = Mfa::secret($u->fresh());
        $this->actingAs($u)->post('/account/mfa/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($u)->post('/account/mfa/setup', ['code' => Totp::code($secret)])->assertOk()->assertSee('recovery codes');
        $this->assertTrue(Mfa::enrolled($u->fresh()));
        $this->actingAs($u)->get('/platform')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_enrolled']);

        // New session: challenge required; a used code cannot be replayed; a fresh step works
        $this->flushSession();
        $this->actingAs($u)->get('/platform')->assertRedirect(route('mfa.challenge'));
        $this->travel(31)->seconds();
        $code = Totp::code($secret);
        $this->actingAs($u)->post('/account/mfa', ['code' => $code])->assertRedirect();
        $this->actingAs($u)->get('/platform')->assertOk();
        $this->flushSession();
        $this->actingAs($u)->post('/account/mfa', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_recovery_code_works_once_and_step_up_expires(): void
    {
        $u = $this->platformAdmin();
        $this->actingAs($u);
        Mfa::beginEnrolment($u);
        $codes = Mfa::confirmEnrolment($u->fresh(), Totp::code(Mfa::secret($u->fresh())));
        $this->assertCount(8, $codes);
        $this->assertTrue(Mfa::recentlyConfirmed($u->fresh()));
        $this->travel(11)->minutes();
        $this->assertFalse(Mfa::recentlyConfirmed($u->fresh()), 'step-up confirmation expires after 10 minutes');

        session()->forget(Mfa::SESSION_KEY);
        Mfa::verify($u->fresh(), $codes[0]);
        $this->assertTrue(Mfa::recentlyConfirmed($u->fresh()));
        $this->expectException(\App\Exceptions\DomainException::class);
        Mfa::verify($u->fresh(), $codes[0]);
    }

    public function test_mfa_applies_to_privileged_staff_only(): void
    {
        $t = $this->makeTenant('alpha');
        $officer = $this->staff($t, 'loan_officer');
        $approver = $this->staff($t, 'cro');
        TenantContext::clear();
        $this->assertFalse(Mfa::requiredFor($officer));
        $this->assertTrue(Mfa::requiredFor($approver));
        $this->assertTrue(Mfa::requiredFor($this->admin($t)));
        $this->actingAs($officer)->get('/ops/dashboard')->assertOk(); // no MFA for non-privileged staff
        $this->actingAs($approver)->get('/ops/dashboard')->assertRedirect(route('mfa.setup'));

        config(['security.mfa.enforce' => false]);
        $this->assertFalse(Mfa::requiredFor($approver));
        $this->assertTrue(Mfa::recentlyConfirmed($approver));
    }

    public function test_demo_users_get_known_secret_and_challenge_shows_simulated_code(): void
    {
        $u = $this->platformAdmin();
        Mfa::enrolDemo($u);
        $this->assertTrue(Mfa::enrolled($u->fresh()));
        $this->actingAs($u)->get('/account/mfa')->assertOk()->assertSee('Simulated authenticator')->assertSee(Totp::code(config('security.mfa.demo_secret')));
    }

    public function test_staff_login_does_not_list_institutions_and_errors_are_generic(): void
    {
        $t = $this->makeTenant('alpha', ['name' => 'Very Secret Bank']);
        TenantContext::clear();
        $this->get('/login')->assertOk()->assertDontSee('Very Secret Bank')->assertSee('Organisation code');
        \Livewire\Livewire::test(\App\Livewire\Auth\StaffLogin::class)->set('org', 'nope')->set('email', 'a@b.test')->set('password', 'x')
            ->call('login')->assertHasErrors('email')->assertSee('These credentials do not match our records.');
    }

    public function test_security_headers_and_nonce_based_csp(): void
    {
        $this->makeTenant('alpha');
        TenantContext::clear();
        $r = $this->get('/');
        $r->assertOk();
        $csp = $r->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $r->getContent(), 'Vite/Livewire scripts carry the nonce');
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertFalse($r->headers->has('X-Powered-By'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }
}
