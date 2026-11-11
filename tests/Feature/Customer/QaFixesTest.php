<?php

namespace Tests\Feature\Customer;

use App\Enums\WalletStatus;
use App\Livewire\Customer\Applications\Apply;
use App\Livewire\Customer\Applications\Show;
use App\Livewire\Customer\Documents;
use App\Livewire\Customer\Home;
use App\Livewire\Customer\Profile;
use App\Livewire\Customer\Wallet\Overview;
use App\Livewire\Customer\Wallet\Settings;
use App\Livewire\Customer\Wallet\TopUp;
use App\Livewire\Customer\Wallet\Transfer;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\LoanApplication;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Lending\AgreementService;
use App\Services\Lending\ApplicationService;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Regression tests for the customer-portal findings of the QA & Product Review (C1, C3, C4, H1, H2, M1, M5, M6, M16, S7, F3/F4). */
class QaFixesTest extends TestCase
{
    use BuildsTenants, ProcessesApplications, RefreshDatabase;

    private function signedIn($tenant, bool $kyc = true): User
    {
        $u = $this->customer($tenant, $kyc);
        TenantContext::set($tenant);
        $this->actingAs($u);

        return $u;
    }

    private function approvedApp($tenant, User $u): LoanApplication
    {
        $vendor = $this->vendor($tenant);
        $product = $this->product($tenant, ['cooling_off_hours' => 0]);
        TenantContext::set($tenant);
        $app = app(ApplicationService::class)->createDraft($u->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12, 'purpose' => 'qa'], $u);
        app(ApplicationService::class)->submit($app, $u);
        $app = $this->approveThroughOps($tenant, $app);
        TenantContext::set($tenant);
        $this->actingAs($u);

        return $app;
    }

    // C1 ----------------------------------------------------------------------------------------------------

    public function test_c1_document_uploads_store_files_on_every_customer_form(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $before = Document::count();
        Livewire::test(Documents::class)
            ->set('typeId', DocumentType::where('code', 'SALARY_SLIP')->value('id'))
            ->set('file', UploadedFile::fake()->create('slip.pdf', 50, 'application/pdf'))
            ->call('saveDocument')->assertHasNoErrors();
        $this->assertSame($before + 1, Document::count());

        // Apply wizard documents step (was wire:click="upload(...)", colliding with $wire.upload)
        $vendor = $this->vendor($tenant);
        $product = $this->product($tenant, [], [['CNIC_FRONT'], ['SALARY_SLIP']]);
        TenantContext::set($tenant);
        $app = app(ApplicationService::class)->createDraft($u->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12, 'purpose' => 'qa'], $u);
        $type = DocumentType::where('code', 'SALARY_SLIP')->value('id');
        Livewire::withQueryParams(['application' => $app->id])->test(Apply::class, ['product' => $product])
            ->set("uploads.$type", UploadedFile::fake()->create('s.pdf', 40, 'application/pdf'))
            ->call('saveDocument', $type)->assertHasNoErrors();
        $this->assertTrue($app->documents()->where('document_type_id', $type)->exists());
        $this->assertStringNotContainsString('upload(', file_get_contents(resource_path('views/livewire/customer/applications/apply.blade.php')));
    }

    // C3 ----------------------------------------------------------------------------------------------------

    public function test_c3_email_must_be_unique_and_verified_by_otp_to_the_new_address(): void
    {
        $tenant = $this->makeTenant('alpha');
        $other = $this->customer($tenant);
        TenantContext::set($tenant);
        User::whereKey($other->id)->update(['email' => 'taken@alpha.test']);
        Customer::whereKey($other->customer_id)->update(['email' => 'taken@alpha.test']);
        $u = $this->signedIn($tenant);

        Livewire::test(Profile::class)->set('contactField', 'email')->set('newContact', 'taken@alpha.test')->call('startContactChange')
            ->assertHasErrors('newContact')->assertSet('contactPending', false);

        $c = Livewire::test(Profile::class)->set('contactField', 'email')->set('newContact', 'new.me@alpha.test')->call('startContactChange')
            ->assertSet('contactPending', true);
        $this->assertNotSame('new.me@alpha.test', $u->fresh()->email, 'nothing changes before the code is confirmed');
        $c->set('contactCode', '000000')->call('confirmContactChange')->assertHasErrors('contactCode');
        $c->set('contactCode', $c->get('demoOtp'))->call('confirmContactChange')->assertHasNoErrors();
        $this->assertSame('new.me@alpha.test', $u->fresh()->email);
        $this->assertSame('new.me@alpha.test', Customer::find($u->customer_id)->email);
        $this->assertTrue(AuditLog::where('action', 'customer.contact_changed')->exists());
    }

    public function test_c3_mobile_change_updates_login_and_wallet_lookup(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $c = Livewire::test(Profile::class)->set('contactField', 'mobile')->set('newContact', '03451234567')->call('startContactChange');
        $c->set('contactCode', $c->get('demoOtp'))->call('confirmContactChange')->assertHasNoErrors();
        $this->assertSame('03451234567', $u->fresh()->mobile);
        $this->assertSame('03451234567', $u->customer->wallet()->first()->mobile);
    }

    // C4 ----------------------------------------------------------------------------------------------------

    public function test_c4_customer_must_open_each_document_and_signs_the_viewed_version(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $app = $this->approvedApp($tenant, $u);
        $this->confirmAssetOwnership($app);
        app(AgreementService::class)->acknowledgeKfs($app, $u, 'en');
        $docs = SignatureRequest::where('loan_application_id', $app->id)->where('signer_type', 'applicant')->where('signing_method', 'digital')->get();
        $this->assertNotEmpty($docs);

        // Not read yet: sending the signing code is refused
        Livewire::test(Show::class, ['application' => $app])->call('sendSignOtp')->assertHasErrors('otp')->assertSet('otpSent', false);

        foreach ($docs as $d) {
            $this->get(route('app.applications.document', [$app, $d]))->assertOk()->assertSee($app->application_no);
            $this->assertNotNull($d->fresh()->viewed_hash);
        }
        // Another customer cannot open it
        $intruder = $this->customer($tenant);
        $this->actingAs($intruder)->get(route('app.applications.document', [$app, $docs->first()]))->assertNotFound();
        $this->actingAs($u);

        $c = Livewire::test(Show::class, ['application' => $app])->call('sendSignOtp')->assertSet('otpSent', true);
        $c->set('otp', $c->get('demoOtp'))->call('signAll')->assertHasNoErrors();
        foreach ($docs as $d) {
            $d->refresh();
            $this->assertSame('signed', $d->status);
            $this->assertSame($d->viewed_hash, $d->document_hash, 'the signed version is exactly the version opened');
        }
    }

    public function test_c4_signing_a_different_version_than_viewed_is_refused(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $app = $this->approvedApp($tenant, $u);
        $this->confirmAssetOwnership($app);
        app(AgreementService::class)->acknowledgeKfs($app, $u, 'en');
        $req = SignatureRequest::where('loan_application_id', $app->id)->where('signer_type', 'applicant')->where('signing_method', 'digital')->first();
        $req->update(['viewed_at' => now(), 'viewed_hash' => str_repeat('a', 64)]);
        $this->expectException(\App\Exceptions\DomainException::class);
        app(AgreementService::class)->sign($req->fresh(), $u);
    }

    // H1 ----------------------------------------------------------------------------------------------------

    public function test_h1_resubmission_requires_responses_and_keeps_first_submission_date(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $vendor = $this->vendor($tenant);
        $product = $this->product($tenant);
        TenantContext::set($tenant);
        $this->travel(-3)->days();
        $app = app(ApplicationService::class)->createDraft($u->customer, $product, ['vendor_item_id' => $vendor->items()->first()->id, 'tenure' => 12, 'purpose' => 'qa'], $u);
        app(ApplicationService::class)->submit($app, $u);
        $this->travelBack();
        $firstSubmitted = $app->fresh()->first_submitted_at;
        $this->assertNotNull($firstSubmitted);
        app(ApplicationService::class)->returnForCorrection($app->fresh(), $this->staff($tenant, 'branch_manager'), 'Upload a clearer CNIC copy', true);

        Livewire::test(Show::class, ['application' => $app->fresh()])->call('resubmit')->assertHasErrors('action');
        $this->assertSame('returned', $app->fresh()->status->value);
        $this->expectsDomainError(fn () => app(ApplicationService::class)->submit($app->fresh(), $u));

        $o = $app->objections()->first();
        Livewire::test(Show::class, ['application' => $app->fresh()])->set("responses.{$o->id}", 'New copy uploaded today')->call('respond', $o->id)->assertHasNoErrors();
        Livewire::test(Show::class, ['application' => $app->fresh()])->call('resubmit')->assertHasNoErrors();
        $app->refresh();
        $this->assertSame('submitted', $app->status->value);
        $this->assertEquals($firstSubmitted->toDateTimeString(), $app->first_submitted_at->toDateTimeString());
        $visible = $app->histories()->where('visible_to_customer', true)->pluck('to_status')->map->value->all();
        $this->assertContains('returned', $visible);
        $this->assertSame('Resubmitted after correction', $app->histories()->reorder()->latest('id')->first()->comment);
    }

    // H2 ----------------------------------------------------------------------------------------------------

    public function test_h2_apply_query_parameters_are_clamped_to_product_limits(): void
    {
        $tenant = $this->makeTenant('alpha');
        $this->signedIn($tenant);
        $product = $this->product($tenant);
        $v = $product->currentVersion;
        Livewire::withQueryParams(['amount' => '-100', 'tenure' => '999'])->test(Apply::class, ['product' => $product])
            ->assertSet('requested_amount', (string) (int) $v->min_amount)->assertSet('tenure', (int) $v->max_tenure);
        Livewire::withQueryParams(['amount' => '99999999'])->test(Apply::class, ['product' => $product])
            ->assertSet('requested_amount', (string) (int) $v->max_amount);
        Livewire::withQueryParams(['amount' => '<script>'])->test(Apply::class, ['product' => $product])
            ->assertSet('requested_amount', (string) (int) $v->min_amount);
    }

    // M1 / M16 ----------------------------------------------------------------------------------------------

    public function test_m1_locale_switch_is_post_only_and_m16_error_pages_are_branded(): void
    {
        $tenant = $this->makeTenant('alpha');
        $this->signedIn($tenant);
        $this->get('/locale/ur')->assertStatus(405);
        $this->post('/locale/ur')->assertRedirect();
        $this->assertSame('ur', auth()->user()->fresh()->locale);
        $this->get('/app/financing/999999')->assertNotFound()->assertSee(__('Page not found'), false)->assertSee(__('Go to home'), false);
        $this->get(route('app.wallet'))->assertOk()->assertSee('<title>'.e(__('My Wallet')), false);
    }

    // M5 / M6 -----------------------------------------------------------------------------------------------

    public function test_m5_restricted_wallet_disables_money_actions_with_reason(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $w = $u->customer->wallet()->first();
        app(WalletService::class)->changeStatus($w, WalletStatus::Restricted, 'Unusual activity under review');
        Livewire::test(Overview::class)->assertSee('Unusual activity under review')->assertSee(__('Contact support'));
        Livewire::test(Transfer::class)->assertSee('Unusual activity under review')
            ->set('mobile', '03001234567')->set('amount', '100')->call('review')->assertHasErrors('action')->assertSet('confirming', false);
        Livewire::test(TopUp::class)->set('amount', '500')->call('submit')->assertHasErrors('action');
        $this->assertSame(0, WalletTransaction::where('wallet_id', $w->id)->count());
    }

    public function test_m6_wrong_pin_is_shown_inline_with_attempts_left(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $other = $this->customer($tenant);
        TenantContext::set($tenant);
        $this->actingAs($u);
        Livewire::test(Settings::class)->set('newPin', '4826')->set('newPin_confirmation', '4826')->call('savePin');
        Livewire::test(TopUp::class)->set('amount', '5000')->set('scenario', 'success')->call('submit');
        Livewire::test(Transfer::class)->set('mobile', $other->customer->mobile)->set('amount', '100')->call('review')
            ->set('pin', '9999')->call('send')->assertHasErrors('pin')->assertSee('4 attempt(s) left');
    }

    // S7 ----------------------------------------------------------------------------------------------------

    public function test_s7_sender_sees_masked_recipient_and_lookups_are_rate_limited(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $other = $this->customer($tenant, true, 3, ['full_name' => 'Umair Rana Khan']);
        TenantContext::set($tenant);
        $other->customer->wallet()->update(['title' => 'Umair Rana Khan']);
        $this->actingAs($u);
        Livewire::test(Settings::class)->set('newPin', '4826')->set('newPin_confirmation', '4826')->call('savePin');
        Livewire::test(TopUp::class)->set('amount', '5000')->set('scenario', 'success')->call('submit');
        $c = Livewire::test(Transfer::class)->set('mobile', $other->customer->mobile)->set('amount', '100')->call('review')
            ->assertSet('recipientName', 'Umair R. K.');
        $c->set('pin', '4826')->call('send')->assertHasNoErrors();
        $txn = WalletTransaction::where('type', 'transfer_out')->latest('id')->first();
        $this->assertStringNotContainsString('Rana Khan', $txn->description);
        $this->get(route('app.wallet.receipt', $txn))->assertOk()->assertDontSee('Umair Rana Khan');

        $t = Livewire::test(Transfer::class);
        for ($i = 0; $i < 10; $i++) {
            $t->set('mobile', '03009999999')->set('amount', '10')->call('review');
        }
        $t->call('review')->assertHasErrors('mobile')->assertSee('Too many recipient lookups');
    }

    // F3 / F4 / UX ------------------------------------------------------------------------------------------

    public function test_f3_f4_catalogue_shows_apr_and_one_late_payment_wording(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        $product = $this->product($tenant);
        $v = $product->currentVersion;
        $rep = $v->representativeQuote();
        $this->get(route('app.products'))->assertOk()->assertSee($rep['apr'].'%')->assertSee(__('From'));
        $this->get(route('app.products.show', $product))->assertOk()->assertSee($v->latePaymentDisclosure(), false);
        $app = $this->approvedApp($tenant, $u);
        $this->assertSame($v->latePaymentDisclosure(), $app->kfs->terms['late_payment']);
    }

    public function test_home_shows_a_single_next_step_card(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->signedIn($tenant);
        Livewire::test(Home::class)->assertSee(__('Your next step'))->assertSee(__('Browse products'));
        $app = $this->approvedApp($tenant, $u);
        Livewire::test(Home::class)->assertSee(__('Your financing is approved'))->assertSee(__('Review KFS'))->assertSee(__('Application progress'));
    }

    private function expectsDomainError(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a DomainException');
        } catch (\App\Exceptions\DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
