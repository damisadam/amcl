<?php

namespace App\Events;

class ApprovalTaskAssigned extends TenantBroadcastEvent
{
    public function __construct(int $tenantId, int $requestId, ?string $stage, ?int $roleId)
    {
        parent::__construct($tenantId, ['request_id' => $requestId, 'stage' => $stage, 'role_id' => $roleId]);
    }

    public function broadcastAs(): string
    {
        return 'approval_task_assigned';
    }
}
