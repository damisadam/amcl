<?php

namespace App\Support;

/**
 * Fire-and-forget real-time events. Reverb is a UX channel only: the business record is always
 * persisted first, and a broadcast failure (Reverb/Redis down) never fails the business operation.
 */
final class Realtime
{
    public static function emit(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
