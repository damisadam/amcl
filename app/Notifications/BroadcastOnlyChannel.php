<?php

namespace App\Notifications;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;

/** Broadcast channel that never breaks the request if the websocket server is down. */
class BroadcastOnlyChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification)
    {
        if (config('broadcasting.default') === 'null') {
            return null;
        }
        try {
            return parent::send($notifiable, $notification);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
