<?php

namespace App\Events;

class ComplaintReceived extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'complaint_received';
    }
}
