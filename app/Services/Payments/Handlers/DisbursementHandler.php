<?php

namespace App\Services\Payments\Handlers;

use App\Models\Disbursement;
use App\Models\PaymentOrder;
use App\Services\Lending\DisbursementService;
use App\Services\Payments\PaymentPurposeHandler;

class DisbursementHandler implements PaymentPurposeHandler
{
    public function __construct(private DisbursementService $service) {}

    public function succeeded(PaymentOrder $order): void
    {
        $this->service->confirm($this->disbursement($order));
    }

    public function failed(PaymentOrder $order): void
    {
        $this->service->fail($this->disbursement($order), $order->failure_message ?? 'Payout failed');
    }

    public function reversed(PaymentOrder $order): void
    {
        $this->service->providerReturned($this->disbursement($order), 'Payout returned by provider (simulated)');
    }

    private function disbursement(PaymentOrder $order): Disbursement
    {
        return $order->payable instanceof Disbursement ? $order->payable : throw new \LogicException('Order not linked to a disbursement.');
    }
}
