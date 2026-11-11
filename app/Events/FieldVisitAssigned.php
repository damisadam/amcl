<?php

namespace App\Events;

class FieldVisitAssigned extends TenantBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'field_visit_assigned';
    }
}
