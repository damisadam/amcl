<?php

namespace Tests\Feature\Ops\Wallet;

use App\Enums\WalletStatus;
use App\Livewire\Ops\Payments\Show as PaymentShow;
use App\Livewire\Ops\WalletTransactions\Index as TxIndex;
use App\Livewire\Ops\Wallets\Index as WalletIndex;
use App\Livewire\Ops\Wallets\Show as WalletShow;
use App\Models\AuditLog;
use App\Models\PaymentOrder;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WalletOpsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function fundedWallet($tenant, string $amount = '10000'): Wallet
    {
        $cu = $this->customer($tenant);
        TenantContext::set($tenant);
        $w = $cu->customer->wallet()->first();
        app(WalletService::class)->setPin($w, '4826');
        app(WalletService::class)->topUp($w, $amount, 'raast', uniqid('k'), 'success', $cu);

        return $w->fresh();
    }

    public function test_wallet_screens_require_permission(): void
    {
        $t = $this->makeTenant('alpha');
        $officer = $this->staff($t, 'loan_officer');
        Livewire::actingAs($officer)->test(WalletIndex::class)->assertForbidden();
        Livewire::actingAs($this->admin($t))->test(WalletIndex::class)->assertOk();
    }

    public function test_status_change_and_limits_are_audited(): void
    {
        $t = $this->makeTenant('alpha');
        $w = $this->fundedWallet($t);
        $admin = $this->admin($t);

        Livewire::actingAs($admin)->test(WalletShow::class, ['wallet' => $w])
            ->call('openStatus', 'restricted')->set('statusReason', 'Suspicious activity review')->call('changeStatus')->assertHasNoErrors();
        $this->assertSame(WalletStatus::Restricted, $w->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'wallet.status_changed')->where('auditable_id', $w->id)->exists());

        Livewire::actingAs($admin)->test(WalletShow::class, ['wallet' => $w->fresh()])
            ->set('limits.per_transaction_limit', '5000')->set('limits.daily_limit', '10000')->set('limits.monthly_limit', '50000')->set('limits.max_balance', '100000')
            ->set('limitReason', 'Risk based limit')->call('saveLimits')->assertHasNoErrors();
        $this->assertEquals('5000.00', $w->fresh()->per_transaction_limit);
        $this->assertTrue(AuditLog::where('action', 'wallet.limits_changed')->exists());

        // integrity check renders OK
        Livewire::actingAs($admin)->test(WalletShow::class, ['wallet' => $w->fresh()])->assertSee('OK');
    }

    public function test_internal_transfer_reversal_with_segregation_of_duties(): void
    {
        $t = $this->makeTenant('alpha');
        $payer = $this->fundedWallet($t, '8000');
        $payee = $this->fundedWallet($t, '1000');
        $admin = $this->staff($t, 'finance_officer'); // wallet.reverse is a finance permission
        $svc = app(WalletService::class);
        $out = $svc->transfer($payer, $payee->mobile, '3000', 'tr-1', '4826', $admin);
        $this->assertEquals('5000.00', $payer->fresh()->balance);

        // the initiator cannot reverse their own transaction
        Livewire::actingAs($admin)->test(TxIndex::class)->call('askReverse', $out->id)->set('reverseReason', 'Customer dispute')->call('reverse')
            ->assertHasErrors('action');
        $this->assertEquals('5000.00', $payer->fresh()->balance);

        $other = $this->staff($t, 'finance_officer');
        Livewire::actingAs($other)->test(TxIndex::class)->call('askReverse', $out->id)->set('reverseReason', 'Customer dispute')->call('reverse')->assertHasNoErrors();
        $this->assertEquals('8000.00', $payer->fresh()->balance);
        $this->assertEquals('1000.00', $payee->fresh()->balance);
        $this->assertSame('reversed', $out->fresh()->status->value);
    }

    public function test_reverse_requires_permission(): void
    {
        $t = $this->makeTenant('alpha');
        $support = $this->staff($t, 'customer_support');
        $payer = $this->fundedWallet($t, '8000');
        $payee = $this->fundedWallet($t, '1000');
        $out = app(WalletService::class)->transfer($payer, $payee->mobile, '1000', 'tr-2', '4826');
        Livewire::actingAs($support)->test(TxIndex::class)->call('askReverse', $out->id)->assertForbidden();
    }

    public function test_simulated_callback_credits_once_and_duplicates_are_ignored(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        TenantContext::set($t);
        $w = $cu->customer->wallet()->first();
        $txn = app(WalletService::class)->topUp($w, '2500', 'easypaisa', 'pending-1', 'pending', $cu);
        $order = PaymentOrder::where('payable_type', $txn->getMorphClass())->where('payable_id', $txn->id)->firstOrFail();
        $this->assertSame('pending', $order->status->value);

        $admin = $this->admin($t);
        Livewire::actingAs($admin)->test(PaymentShow::class, ['order' => $order])->call('simulate', 'succeeded');
        $this->assertEquals('2500.00', $w->fresh()->balance);
        Livewire::actingAs($admin)->test(PaymentShow::class, ['order' => $order->fresh()])->call('duplicate');
        $this->assertEquals('2500.00', $w->fresh()->balance, 'Duplicate callback must not credit twice.');
        $this->assertTrue($order->callbacks()->where('result', 'duplicate')->exists());
    }

    public function test_simulation_blocked_without_permission_or_outside_demo_mode(): void
    {
        $t = $this->makeTenant('alpha');
        $cu = $this->customer($t);
        TenantContext::set($t);
        $txn = app(WalletService::class)->topUp($cu->customer->wallet()->first(), '1000', 'raast', 'p-2', 'pending', $cu);
        $order = PaymentOrder::where('payable_type', $txn->getMorphClass())->where('payable_id', $txn->id)->firstOrFail();

        Livewire::actingAs($this->staff($t, 'customer_support'))->test(PaymentShow::class, ['order' => $order])->call('simulate', 'succeeded')->assertForbidden();
        config(['integrations.demo_mode' => false]);
        Livewire::actingAs($this->admin($t))->test(PaymentShow::class, ['order' => $order])->call('simulate', 'succeeded')->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status->value);
    }

    public function test_cross_tenant_wallet_is_not_reachable(): void
    {
        $a = $this->makeTenant('alpha');
        $b = $this->makeTenant('bravo');
        $wb = $this->fundedWallet($b);
        $this->actingAs($this->admin($a))->get(route('ops.wallets.show', $wb->id))->assertNotFound();
        $this->actingAs($this->admin($b))->get(route('ops.wallets.show', $wb->id))->assertOk();
    }
}
