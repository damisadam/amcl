<?php

namespace App\Services\Payments\Handlers;

use App\Models\LoanPayment;
use App\Models\PaymentOrder;
use App\Services\Lending\RepaymentService;
use App\Services\Payments\PaymentPurposeHandler;

/** Raast / QR-IBAN / 1Bill / Easypaisa / JazzCash instalment collections. */
class InstalmentCollectionHandler implements PaymentPurposeHandler
{
    public function __construct(private RepaymentService $repayments) {}

    public function succeeded(PaymentOrder $order): void
    {
        $this->repayments->settlePending($this->payment($order));
    }

    public function failed(PaymentOrder $order): void
    {
        $this->repayments->failPending($this->payment($order), $order->failure_message ?? 'Collection failed');
    }

    public function reversed(PaymentOrder $order): void
    {
        $this->repayments->reverse($this->payment($order), 'Collection reversed by provider (simulated)', null, system: true);
    }

    private function payment(PaymentOrder $order): LoanPayment
    {
        return $order->payable instanceof LoanPayment ? $order->payable : throw new \LogicException('Order not linked to a loan payment.');
    }
}
