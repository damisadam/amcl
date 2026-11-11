<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification: persisted to the database (authoritative) and pushed over Reverb
 * on the user's private channel (best effort, queued).
 */
class InAppNotification extends Notification
{
    use Queueable;

    public function __construct(public string $event, public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database', BroadcastOnlyChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event] + $this->payload;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage(['event' => $this->event] + $this->payload))->onQueue('broadcasts');
    }
}
