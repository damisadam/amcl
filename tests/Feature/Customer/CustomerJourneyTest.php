<?php

namespace Tests\Feature\Customer;

use App\Livewire\Customer\Applications\Apply;
use App\Livewire\Customer\Applications\Show;
use App\Livewire\Customer\Payments\Pay;
use App\Livewire\Customer\Wallet\Settings;
use App\Livewire\Customer\Wallet\TopUp;
use App\Models\LoanApplication;
use App\Services\Lending\DisbursementService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CustomerJourneyTest extends TestCase
{
    use BuildsTenants, ProcessesApplications, RefreshDatabase;

    public function test_apply_sign_disburse_top_up_and_repay_from_wallet(): void
    {
        $tenant = $this->makeTenant('alpha');
        $vendor = $this->vendor($tenant);
        $product = $this->product($tenant);
        $user = $this->customer($tenant);
        TenantContext::set($tenant);
        $this->actingAs($user);

        // Apply through the wizard
        $item = $vendor->items()->first();
        $wizard = Livewire::test(Apply::class, ['product' => $product])
            ->set('vendor_id', $vendor->id)->set('vendor_item_id', $item->id)
            ->assertSet('asset_price', '165000')
            ->set('tenure', 12)->set('purpose', 'Daily commute to work')
            ->call('saveTerms')->assertHasNoErrors()->assertSet('step', 'documents')
            ->call('goTo', 'review')->call('submit')->assertHasNoErrors();
        $app = LoanApplication::where('customer_id', $user->customer_id)->firstOrFail();
        $this->assertSame('submitted', $app->status->value);
        $this->assertEquals('148500.00', $app->requested_amount);

        // Staff side
        $app = $this->approveThroughOps($tenant, $app);
        $this->assertSame('approved', $app->status->value);
        TenantContext::set($tenant);
        $this->actingAs($user);

        // Acknowledge KFS in Urdu and sign with OTP
        $this->confirmAssetOwnership($app);
        $show = Livewire::test(Show::class, ['application' => $app])
            ->set('kfsLocale', 'ur')->set('kfsConfirm', true)->call('acknowledgeKfs')->assertHasNoErrors();
        // The customer opens each document before signing (QA C4)
        foreach (\App\Models\SignatureRequest::where('loan_application_id', $app->id)->where('signer_type', 'applicant')->where('signing_method', 'digital')->get() as $doc) {
            $this->get(route('app.applications.document', [$app, $doc]))->assertOk();
        }
        $show->call('sendSignOtp');
        $otp = $show->get('demoOtp');
        $show->set('otp', $otp)->call('signAll')->assertHasNoErrors();
        $app->refresh();
        $this->assertSame('ur', $app->kfs->acknowledged_locale);
        $this->assertSame(0, $app->signatureRequests()->where('signer_type', 'applicant')->where('signing_method', 'digital')->where('status', '!=', 'signed')->count());

        // Wet-ink DPN/affidavit confirmed by staff; cooling-off elapses; disbursement
        $da = $this->staff($tenant, 'disbursement_approver');
        foreach ($app->signatureRequests()->where('status', 'wet_ink_pending')->get() as $sr) {
            app(\App\Services\Lending\AgreementService::class)->confirmWetInk($sr, $da, 'Original received at branch');
        }
        $app->refresh();
        $this->assertSame('cooling_off', $app->status->value);
        $app->forceFill(['cooling_off_ends_at' => now()->subMinute()])->save();
        $d = app(DisbursementService::class)->initiate($app->fresh(), $da, null, $this->staff($tenant, 'disbursement_approver'));
        $loan = $d->fresh()->loanAccount;
        $this->assertNotNull($loan);

        // Customer sets PIN, tops up and pays the first instalment from the wallet
        $this->actingAs($user);
        Livewire::test(Settings::class)->set('newPin', '4826')->set('newPin_confirmation', '4826')->call('savePin')->assertHasNoErrors();
        Livewire::test(TopUp::class)->set('amount', '20000')->set('source', 'raast')->set('scenario', 'success')->call('submit')->assertHasNoErrors();
        $wallet = $user->customer->wallet()->first();
        $this->assertEquals('20000.00', $wallet->balance);

        $due = $loan->instalments()->first()->total_due;
        Livewire::test(Pay::class, ['loan' => $loan->id])->assertSet('amount', (string) (float) $due)
            ->set('channel', 'wallet')->set('pin', '4826')->call('pay')->assertHasNoErrors();
        $this->assertSame('paid', $loan->instalments()->first()->status->value);
        $this->assertEquals(\App\Support\Money::sub('20000', $due), $wallet->fresh()->balance);
        $this->assertDatabaseHas('loan_payments', ['loan_account_id' => $loan->id, 'channel' => 'wallet', 'status' => 'succeeded']);

        $tb = app(\App\Services\Accounting\FinancialReports::class)->trialBalance(null, today()->toDateString());
        $this->assertTrue($tb['balanced']);
    }

    public function test_external_raast_instalment_stays_pending_until_simulated_callback(): void
    {
        $tenant = $this->makeTenant('alpha');
        [$user, $loan] = $this->disbursedLoan($tenant);
        $this->actingAs($user);
        $c = Livewire::test(Pay::class, ['loan' => $loan->id])->set('channel', 'raast')->set('scenario', 'pending')->set('amount', '1000')->call('pay')->assertHasNoErrors();
        $payment = $loan->payments()->first();
        $this->assertSame('pending', $payment->status->value);
        $c->call('simulate', 'succeeded');
        $this->assertSame('succeeded', $payment->fresh()->status->value);
        $this->assertEquals('1000.00', $loan->fresh()->total_paid);
    }
}
