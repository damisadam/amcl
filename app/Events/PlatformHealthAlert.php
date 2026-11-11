<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformHealthAlert implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'broadcasts';

    public int $tries = 1;

    public function __construct(public string $kind, public string $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('platform.health')];
    }

    public function broadcastAs(): string
    {
        return 'platform_health_alert';
    }
}
