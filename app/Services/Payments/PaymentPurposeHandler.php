<?php

namespace App\Services\Payments;

use App\Models\PaymentOrder;

/**
 * Business reaction to a confirmed provider outcome. Called inside the same DB transaction that
 * transitions the payment order, so business state and payment state commit atomically.
 */
interface PaymentPurposeHandler
{
    public function succeeded(PaymentOrder $order): void;

    public function failed(PaymentOrder $order): void;

    public function reversed(PaymentOrder $order): void;
}
