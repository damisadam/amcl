<?php

namespace Tests\Feature\Core;

use App\Enums\WalletTransactionStatus;
use App\Exceptions\DomainException;
use App\Models\JournalVoucher;
use App\Models\PaymentCallback;
use App\Models\PaymentOrder;
use App\Models\WalletLedgerEntry;
use App\Services\Accounting\FinancialReports;
use App\Services\Payments\CallbackProcessor;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function wallet($t)
    {
        $cu = $this->customer($t);
        TenantContext::set($t);
        $w = $cu->customer->wallet()->first();
        app(WalletService::class)->setPin($w, '4826');

        return [$cu, $w->fresh()];
    }

    public function test_topup_credits_once_and_duplicate_callback_is_ignored(): void
    {
        $t = $this->makeTenant('alpha');
        [$cu, $w] = $this->wallet($t);
        $ws = app(WalletService::class);

        $txn = $ws->topUp($w, '10000', 'raast', 'k1', 'duplicate_callback', $cu);
        $this->assertSame(WalletTransactionStatus::Succeeded, $txn->fresh()->status);
        $this->assertTrue(Money::eq('10000', $w->fresh()->balance));
        $this->assertSame(1, PaymentCallback::where('result', 'processed')->count());
        $this->assertSame(1, PaymentCallback::where('result', 'duplicate')->count());

        // replaying the provider callback manually (e.g. webhook retry) is still a no-op
        $order = PaymentOrder::first();
        $payload = app(PaymentOrchestrator::class)->buildCallbackPayload($order, 'succeeded');
        $this->assertSame('duplicate', app(CallbackProcessor::class)->process('raast', $payload));
        $this->assertTrue(Money::eq('10000', $w->fresh()->balance));
        // same idempotency key returns the original transaction
        $this->assertSame($txn->id, $ws->topUp($w->fresh(), '10000', 'raast', 'k1', null, $cu)->id);
        $this->assertSame(1, JournalVoucher::where('event_type', 'wallet_topup')->count());
        $this->assertTrue(Money::eq(app(WalletLedger::class)->ledgerBalance($w), $w->fresh()->balance));
    }

    public function test_failed_pending_and_invalid_callbacks_do_not_credit(): void
    {
        $t = $this->makeTenant('alpha');
        [$cu, $w] = $this->wallet($t);
        $ws = app(WalletService::class);
        $this->assertSame(WalletTransactionStatus::Failed, $ws->topUp($w, '5000', 'jazzcash', 'f', 'failure', $cu)->fresh()->status);
        $pending = $ws->topUp($w->fresh(), '5000', 'raast', 'p', 'pending', $cu);
        $this->assertSame(WalletTransactionStatus::Pending, $pending->fresh()->status);
        $ws->topUp($w->fresh(), '5000', 'raast', 'i', 'invalid_reference', $cu);
        $ws->topUp($w->fresh(), '5000', 'raast', 'm', 'amount_mismatch', $cu);
        $this->assertTrue(Money::isZero($w->fresh()->balance));
        $this->assertSame(2, PaymentCallback::where('result', 'rejected')->count());

        // forged signature is rejected
        $order = PaymentOrder::where('scenario', 'pending')->first();
        $payload = app(PaymentOrchestrator::class)->buildCallbackPayload($order, 'succeeded');
        $payload['signature'] = str_repeat('0', 64);
        $this->assertSame('rejected', app(CallbackProcessor::class)->process('raast', $payload));
        $this->assertTrue(Money::isZero($w->fresh()->balance));

        // provider confirms later → credited once
        app(PaymentOrchestrator::class)->simulate($order, 'succeeded');
        $this->assertTrue(Money::eq('5000', $w->fresh()->balance));
    }

    public function test_insufficient_balance_limits_pin_and_cross_tenant_transfer(): void
    {
        $t = $this->makeTenant('alpha');
        [$cu, $w] = $this->wallet($t);
        [$cu2, $w2] = $this->wallet($t);
        $ws = app(WalletService::class);
        $ws->topUp($w, '3000', 'raast', 'a', 'success', $cu);

        try {
            $ws->transfer($w->fresh(), $w2->mobile, '5000', 'x1', '4826', $cu);
            $this->fail('insufficient');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }
        try {
            $ws->transfer($w->fresh(), $w2->mobile, '100', 'x2', '0000', $cu);
            $this->fail('pin');
        } catch (DomainException $e) {
            $this->assertStringContainsString('PIN', $e->getMessage());
        }
        $out = $ws->transfer($w->fresh(), $w2->mobile, '1000', 'x3', '4826', $cu);
        $this->assertTrue(Money::eq('2000', $w->fresh()->balance));
        $this->assertTrue(Money::eq('1000', $w2->fresh()->balance));
        $this->assertSame(0, JournalVoucher::where('source_type', $out->getMorphClass())->where('source_id', $out->id)->count(), 'internal transfer has no GL impact');

        // A wallet in another tenant is not addressable
        $b = $this->makeTenant('bravo');
        $otherMobile = $this->customer($b)->customer->mobile; // read while in Bravo's context
        TenantContext::set($t);
        $this->expectException(DomainException::class);
        $ws->transfer($w->fresh(), $otherMobile, '10', 'x4', '4826', $cu);
    }

    public function test_withdrawal_hold_release_and_provider_reversal_compensation(): void
    {
        $t = $this->makeTenant('alpha');
        [$cu, $w] = $this->wallet($t);
        $ws = app(WalletService::class);
        $ws->topUp($w, '9000', 'raast', 'a', 'success', $cu);
        $bank = $cu->customer->linkedBankAccounts()->create(['bank_name' => 'HBL', 'iban' => 'PK00TEST0000000000000001', 'account_title' => 'T']);
        $ws->withdraw($w->fresh(), '2000', $bank, 'w1', '4826', 'failure', $cu);
        $this->assertTrue(Money::eq('9000', $w->fresh()->balance));
        $this->assertTrue(Money::isZero($w->fresh()->held_balance));
        $pending = $ws->withdraw($w->fresh(), '2000', $bank, 'w2', '4826', 'pending', $cu);
        $this->assertTrue(Money::eq('2000', $w->fresh()->held_balance));
        $this->assertTrue(Money::eq('7000', $w->fresh()->availableBalance()));
        app(PaymentOrchestrator::class)->simulate($pending->paymentOrder, 'succeeded');
        $this->assertTrue(Money::eq('7000', $w->fresh()->balance));

        $rev = $ws->topUp($w->fresh(), '1000', 'easypaisa', 'r1', 'reversal', $cu);
        $this->assertSame(WalletTransactionStatus::Reversed, $rev->fresh()->status);
        $this->assertTrue(Money::eq('7000', $w->fresh()->balance));
        $this->assertSame(2, WalletLedgerEntry::where('wallet_id', $w->id)->whereIn('wallet_transaction_id', [$rev->id, $rev->fresh()->related_transaction_id])->count());

        $wl = app(WalletLedger::class);
        $this->assertTrue(Money::eq($wl->ledgerBalance($w), $w->fresh()->balance));
        // GL: wallet liability equals sum of wallet balances; safeguarded account matches
        $reports = app(FinancialReports::class);
        $this->assertTrue(Money::eq($reports->roleBalance('wallet_liability'), $w->fresh()->balance));
        $this->assertTrue(Money::eq($reports->roleBalance('safeguarded_bank'), $w->fresh()->balance));
    }

    public function test_competing_debits_never_overdraw(): void
    {
        $t = $this->makeTenant('alpha');
        [$cu, $w] = $this->wallet($t);
        [$cu2, $w2] = $this->wallet($t);
        app(WalletService::class)->topUp($w, '1000', 'raast', 'a', 'success', $cu);
        // Two debits competing for the same funds: the second must fail. True parallel locking is exercised by scripts/concurrency_check.php.
        $ws = app(WalletService::class);
        $ok = 0;
        foreach (['c1', 'c2'] as $k) {
            try {
                $ws->transfer($w->fresh(), $w2->mobile, '700', $k, '4826', $cu);
                $ok++;
            } catch (DomainException) {
            }
        }
        $this->assertSame(1, $ok);
        $this->assertTrue(Money::eq('300', $w->fresh()->balance));
        $this->assertFalse(Money::lt($w->fresh()->balance, 0));
    }
}
