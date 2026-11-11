<?php

namespace Tests\Feature\Customer;

use App\Livewire\Customer\Auth\Register;
use App\Livewire\Customer\Onboarding;
use App\Models\Customer;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function registerAndVerify($tenant): User
    {
        $c = Livewire::test(Register::class, ['tenant' => $tenant])
            ->set('name', 'Sana Iqbal')->set('mobile', '03211234567')->set('password', 'Secret#Pass123')->set('password_confirmation', 'Secret#Pass123')
            ->set('accept', true)->call('create')->assertHasNoErrors()->assertSet('step', 2);
        $otp = $c->get('demoOtp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        $c->set('otp', $otp)->call('verify')->assertRedirect(route('app.onboarding'));

        return User::where('mobile', '03211234567')->firstOrFail();
    }

    private function completeProfile(User $user, string $cnic, string $scenario = 'auto')
    {
        $this->actingAs($user);

        return Livewire::test(Onboarding::class)
            ->assertSet('step', 'personal')
            ->set('personal.full_name', 'Sana Iqbal')->set('personal.cnic', $cnic)->set('personal.date_of_birth', '1992-03-04')
            ->set('personal.gender', 'female')->set('personal.father_husband_name', 'Iqbal Ahmed')->call('savePersonal')->assertHasNoErrors()->assertSet('step', 'address')
            ->set('address.current_address', 'House 5, Gulberg, Lahore')->set('address.permanent_address', 'House 5, Gulberg, Lahore')->set('address.city', 'Lahore')
            ->set('address.employment_type', 'salaried')->set('address.employer_name', 'Acme Textiles')->set('address.monthly_income', '75000')
            ->call('saveAddress')->assertHasNoErrors()->assertSet('step', 'kin')
            ->set('kin.name', 'Iqbal Ahmed')->set('kin.relation', 'father')->set('kin.mobile', '03001112233')->call('saveKin')->assertSet('step', 'documents')
            ->set('cnicFront', UploadedFile::fake()->image('f.jpg'))->set('cnicBack', UploadedFile::fake()->image('b.jpg'))->set('selfie', UploadedFile::fake()->image('s.jpg'))
            ->call('uploadDocuments')->assertHasNoErrors()->assertSet('step', 'consents')
            ->set('consents.nadra_verification', true)->set('consents.credit_bureau', true)->call('saveConsents')->assertSet('step', 'kyc')
            ->set('scenario', $scenario)->call('runKyc');
    }

    public function test_full_onboarding_passes_kyc_and_activates_wallet(): void
    {
        $tenant = $this->makeTenant('alpha');
        $user = $this->registerAndVerify($tenant);
        $this->assertNotNull($user->mobile_verified_at);
        $this->completeProfile($user, '35202-1234567-3');

        $c = Customer::find($user->customer_id);
        $this->assertSame('passed', $c->kyc_status->value);
        $this->assertSame('clear', $c->aml_status->value);
        $this->assertSame('active', $c->wallet->status->value);
        $this->assertSame(3, $c->documents()->count());
        $this->assertSame(1, $c->nextOfKins()->count());
        $this->assertDatabaseHas('kyc_verifications', ['customer_id' => $c->id, 'step' => 'nadra_verisys', 'status' => 'passed']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $c->id, 'action' => 'kyc.completed']);
    }

    public function test_cnic_digit_seven_routes_to_manual_review_and_eight_fails(): void
    {
        $tenant = $this->makeTenant('alpha');
        $user = $this->registerAndVerify($tenant);
        $this->completeProfile($user, '35202-1234567-7');
        $this->assertSame('pending_review', Customer::find($user->customer_id)->kyc_status->value);

        TenantContext::set($tenant);
        $u2 = $this->customer($tenant, false, 8);
        $this->actingAs($u2);
        app(\App\Services\Documents\DocumentStore::class)->store(UploadedFile::fake()->image('c.jpg'), $u2->customer, \App\Models\DocumentType::where('code', 'CNIC_FRONT')->first(), $u2);
        Livewire::test(Onboarding::class)->call('runKyc');
        $this->assertSame('failed', $u2->customer->fresh()->kyc_status->value);
    }

    public function test_validation_rejects_bad_cnic_and_underage(): void
    {
        $tenant = $this->makeTenant('alpha');
        $user = $this->registerAndVerify($tenant);
        $this->actingAs($user);
        Livewire::test(Onboarding::class)->set('personal.full_name', 'Sana')->set('personal.cnic', '123')->set('personal.date_of_birth', now()->subYears(15)->toDateString())
            ->set('personal.gender', 'female')->set('personal.father_husband_name', 'X')->call('savePersonal')
            ->assertHasErrors(['personal.cnic', 'personal.date_of_birth']);
    }
}
