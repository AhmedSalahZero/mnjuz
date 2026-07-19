<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign send rate limiting
    |--------------------------------------------------------------------------
    |
    | Controls how many campaign messages are queued per organization on each
    | scheduler tick. WhatsApp quality limits are enforced per phone number, so
    | batching is applied per organization rather than globally.
    |
    */

    'send_batch_size' => (int) env('CAMPAIGN_SEND_BATCH_SIZE', 10),

    'send_interval_seconds' => (int) env('CAMPAIGN_SEND_INTERVAL_SECONDS', 5),

];
