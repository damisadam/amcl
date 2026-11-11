<?php

namespace App\Events;

class ApplicationSubmitted extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'application_submitted';
    }
}
