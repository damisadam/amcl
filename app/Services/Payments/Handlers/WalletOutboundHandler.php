<?php

namespace App\Services\Payments\Handlers;

use App\Models\PaymentOrder;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentPurposeHandler;
use App\Services\Wallet\WalletService;

/** Withdrawals, wallet-funded bill payments and external merchant payments (hold → capture/release). */
class WalletOutboundHandler implements PaymentPurposeHandler
{
    public function __construct(private WalletService $wallets) {}

    public function succeeded(PaymentOrder $order): void
    {
        $this->wallets->captureOutbound($this->txn($order));
    }

    public function failed(PaymentOrder $order): void
    {
        $this->wallets->failOutbound($this->txn($order), $order->failure_message ?? 'Payment failed at provider');
    }

    public function reversed(PaymentOrder $order): void
    {
        $this->wallets->providerReversal($this->txn($order), 'Payout returned by '.$order->provider.' (simulated)');
    }

    private function txn(PaymentOrder $order): WalletTransaction
    {
        $txn = $order->payable;
        if (! $txn instanceof WalletTransaction) {
            throw new \LogicException('Outbound wallet order is not linked to a wallet transaction.');
        }
        if (! $txn->payment_order_id) {
            $txn->forceFill(['payment_order_id' => $order->id])->save();
        }

        return $txn;
    }
}
