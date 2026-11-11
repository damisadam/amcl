<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/** Sent to the wallet owner's private user channel and the tenant ops channel. */
class WalletTransactionCompleted extends TenantBroadcastEvent
{
    public function __construct(int $tenantId, public ?int $userId, array $data)
    {
        parent::__construct($tenantId, $data);
    }

    public function broadcastOn(): array
    {
        $channels = parent::broadcastOn();
        if ($this->userId) {
            $channels[] = new PrivateChannel('App.Models.User.'.$this->userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'wallet_transaction_completed';
    }
}
