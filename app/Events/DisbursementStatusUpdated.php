<?php

namespace App\Events;

class DisbursementStatusUpdated extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'disbursement_status_updated';
    }
}
