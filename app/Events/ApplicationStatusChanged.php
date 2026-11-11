<?php

namespace App\Events;

class ApplicationStatusChanged extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'application_status_changed';
    }
}
