<?php

namespace App\Services\Payments\Handlers;

use App\Models\PaymentOrder;
use App\Models\VendorPayment;
use App\Services\Accounting\VendorPaymentService;
use App\Services\Payments\PaymentPurposeHandler;

class VendorPaymentHandler implements PaymentPurposeHandler
{
    public function __construct(private VendorPaymentService $service) {}

    public function succeeded(PaymentOrder $order): void
    {
        $this->service->markPaid($this->payment($order));
    }

    public function failed(PaymentOrder $order): void
    {
        $this->service->markFailed($this->payment($order), $order->failure_message ?? 'Payment failed');
    }

    public function reversed(PaymentOrder $order): void
    {
        $this->service->markReversed($this->payment($order), 'Returned by provider (simulated)');
    }

    private function payment(PaymentOrder $order): VendorPayment
    {
        return $order->payable instanceof VendorPayment ? $order->payable : throw new \LogicException('Order not linked to a vendor payment.');
    }
}
