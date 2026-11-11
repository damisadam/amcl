<?php

namespace Tests\Feature\Customer;

use App\Livewire\Customer\Wallet\Bills;
use App\Livewire\Customer\Wallet\Merchant;
use App\Livewire\Customer\Wallet\Settings;
use App\Livewire\Customer\Wallet\TopUp;
use App\Livewire\Customer\Wallet\Transfer;
use App\Livewire\Customer\Wallet\Withdraw;
use App\Models\WalletTransaction;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WalletScreensTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function funded($tenant, string $amount = '20000')
    {
        $u = $this->customer($tenant);
        TenantContext::set($tenant);
        $this->actingAs($u);
        Livewire::test(Settings::class)->set('newPin', '4826')->set('newPin_confirmation', '4826')->call('savePin');
        Livewire::test(TopUp::class)->set('amount', $amount)->set('scenario', 'success')->call('submit');

        return $u;
    }

    public function test_top_up_scenarios_and_resubmission_is_idempotent(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->funded($tenant);
        $w = $u->customer->wallet()->first();
        $this->assertEquals('20000.00', $w->balance);

        $c = Livewire::test(TopUp::class)->set('amount', '5000')->set('scenario', 'failure')->call('submit');
        $this->assertEquals('20000.00', $w->fresh()->balance);
        $this->assertSame('failed', WalletTransaction::find($c->get('lastTxn'))->status->value);

        // Same idempotency key replayed (double click) never creates a second credit
        $c = Livewire::test(TopUp::class)->set('amount', '3000')->set('scenario', 'pending');
        $idem = $c->get('idem');
        $c->call('submit');
        $c->set('idem', $idem)->set('amount', '3000')->call('submit');
        $this->assertSame(1, WalletTransaction::where('idempotency_key', 'topup:'.$idem)->count());
        $txn = WalletTransaction::where('idempotency_key', 'topup:'.$idem)->first();
        $this->assertSame('pending', $txn->status->value, 'txn '.json_encode($txn->paymentOrder?->only('status', 'scenario', 'failure_message')));
        $c->call('simulateCallback', $txn->id, 'succeeded');
        $this->assertEquals('23000.00', $w->fresh()->balance, json_encode($c->errors()->all()).json_encode($txn->fresh()->paymentOrder->only('status', 'callback_count')));
    }

    public function test_transfer_requires_confirmation_and_pin(): void
    {
        $tenant = $this->makeTenant('alpha');
        $receiver = $this->customer($tenant);
        $sender = $this->funded($tenant);
        $c = Livewire::test(Transfer::class)->set('mobile', $receiver->mobile)->set('amount', '2500')->call('review')->assertSet('confirming', true);
        $this->assertStringStartsWith('Test', $c->get('recipientName'));
        $c->set('pin', '1111')->call('send')->assertHasErrors('pin'); // shown inline with attempts left (QA M6)
        $c->set('pin', '4826')->call('send')->assertHasNoErrors();
        $this->assertEquals('17500.00', $sender->customer->wallet()->first()->balance);
        $this->assertEquals('2500.00', $receiver->customer->wallet()->first()->balance);
    }

    public function test_withdraw_links_account_and_holds_then_settles(): void
    {
        $tenant = $this->makeTenant('alpha');
        $u = $this->funded($tenant);
        Livewire::test(Withdraw::class)->set('newIban', 'PK00MEZN0000000000000000')->call('link')->assertHasErrors('action');
        $c = Livewire::test(Withdraw::class)->set('newBank', 'HBL')->set('newIban', 'PK36HABB0001234567890123')->call('link')->assertHasNoErrors()
            ->set('amount', '5000')->set('pin', '4826')->set('scenario', 'pending')->call('submit')->assertHasNoErrors();
        $w = $u->customer->wallet()->first();
        $this->assertEquals('5000.00', $w->held_balance);
        $c->call('simulateCallback', $c->get('lastTxn'), 'succeeded');
        $w->refresh();
        $this->assertEquals('15000.00', $w->balance);
        $this->assertEquals('0.00', $w->held_balance);
    }

    public function test_bill_inquiry_and_payment_and_merchant_payment(): void
    {
        $tenant = $this->makeTenant('alpha');
        $vendor = $this->vendor($tenant);
        $u = $this->funded($tenant);
        $bills = Livewire::test(Bills::class)->set('biller', 'LESCO')->set('consumerRef', '0412345678')->call('inquire')->assertHasNoErrors();
        $due = $bills->get('bill')['amount_due'];
        $bills->set('pin', '4826')->set('scenario', 'success')->call('pay')->assertHasNoErrors();
        $this->assertDatabaseHas('bill_payments', ['consumer_ref' => '0412345678', 'status' => 'succeeded']);
        $w = $u->customer->wallet()->first();
        $this->assertEquals(\App\Support\Money::sub('20000', $due), $w->balance);

        // Merchant without a wallet → external Raast payout (still succeeds in demo)
        Livewire::test(Merchant::class)->set('code', $vendor->merchant_code)->call('lookup')->assertSet('merchantName', $vendor->business_name)
            ->set('amount', '500')->set('pin', '4826')->set('scenario', 'success')->call('pay')->assertHasNoErrors();
        $this->assertEquals(\App\Support\Money::sub(\App\Support\Money::sub('20000', $due), '500'), $w->fresh()->balance);
    }

    public function test_cannot_simulate_callback_for_someone_elses_transaction(): void
    {
        $tenant = $this->makeTenant('alpha');
        $a = $this->funded($tenant);
        $txn = WalletTransaction::where('wallet_id', $a->customer->wallet()->first()->id)->first();
        $b = $this->customer($tenant);
        $this->actingAs($b);
        try {
            Livewire::test(TopUp::class)->call('simulateCallback', $txn->id, 'succeeded');
            $this->fail('Expected 404');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            $this->assertTrue(true);
        }
    }
}
