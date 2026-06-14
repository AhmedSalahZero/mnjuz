<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Log;

class ResilientPusherBroadcaster extends PusherBroadcaster
{
    private const MAX_ATTEMPTS = 3;

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                parent::broadcast($channels, $event, $payload);

                return;
            } catch (BroadcastException $e) {
                $lastException = $e;

                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(250_000 * $attempt);
                    continue;
                }
            }
        }

        Log::warning('Pusher broadcast failed after retries (non-fatal)', [
            'event' => $event,
            'channel_count' => count($channels),
            'attempts' => self::MAX_ATTEMPTS,
            'error' => $lastException?->getMessage(),
        ]);
    }
}
