<?php

namespace App\Services\Payments\Handlers;

use App\Models\PaymentOrder;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentPurposeHandler;
use App\Services\Wallet\WalletService;

class WalletTopupHandler implements PaymentPurposeHandler
{
    public function __construct(private WalletService $wallets) {}

    public function succeeded(PaymentOrder $order): void
    {
        $this->wallets->settleTopUp($this->txn($order));
    }

    public function failed(PaymentOrder $order): void
    {
        $this->wallets->failTopUp($this->txn($order), $order->failure_message ?? 'Declined by provider');
    }

    public function reversed(PaymentOrder $order): void
    {
        $this->wallets->providerReversal($this->txn($order), 'Reversed by '.$order->provider.' (simulated)');
    }

    private function txn(PaymentOrder $order): WalletTransaction
    {
        $txn = $order->payable;
        if (! $txn instanceof WalletTransaction) {
            throw new \LogicException('Top-up order is not linked to a wallet transaction.');
        }
        if (! $txn->payment_order_id) {
            $txn->forceFill(['payment_order_id' => $order->id])->save();
        }

        return $txn;
    }
}
