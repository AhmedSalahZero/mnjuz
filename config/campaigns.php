<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Campaign send rate limiting
    |--------------------------------------------------------------------------
    |
    | Controls how many campaign messages are queued per organization and
    | channel on each scheduler tick. Platform limits apply to the sending
    | account (a WhatsApp number, a Telegram bot, a page), and an organization
    | sends each channel's campaigns from one account, so every channel gets
    | its own batch: a large WhatsApp campaign does not hold back the same
    | organization's Telegram campaign.
    |
    */

    'send_batch_size' => (int) env('CAMPAIGN_SEND_BATCH_SIZE', 10),

    'send_interval_seconds' => (int) env('CAMPAIGN_SEND_INTERVAL_SECONDS', 5),

    /*
    | Per-channel batch override. Email goes through the merchant's own SMTP
    | server (Office 365 allows 30 messages a minute, Gmail fewer), one
    | connection per message, so it gets a smaller batch: 2 per 5-second tick
    | is about 24 a minute per organization.
    */
    'channel_batch_sizes' => [
        'email' => (int) env('CAMPAIGN_EMAIL_SEND_BATCH_SIZE', 2),
    ],

];
