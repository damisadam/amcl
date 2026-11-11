<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for real-time UI events. Payloads carry identifiers and labels only (never balances,
 * CNICs or AML data); clients re-fetch authoritative data from the server.
 * Channels are private and authorized per tenant in routes/channels.php.
 */
abstract class TenantBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $queue = 'broadcasts';

    /** Real-time is best-effort UX: never retry (state is already persisted and screens also poll). */
    public int $tries = 1;

    public function __construct(public int $tenantId, public array $data = []) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.ops")];
    }

    public function broadcastWith(): array
    {
        return $this->data + ['at' => now()->toIso8601String()];
    }
}
