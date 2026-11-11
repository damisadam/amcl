<?php

namespace App\Events;

class PaymentStatusUpdated extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'payment_status_updated';
    }
}
