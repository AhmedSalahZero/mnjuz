<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MyFatoorah Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Live credentials and runtime settings (API key, mode, currency, language)
    | are configured in the admin panel:
    | /admin/payment-gateways → MyFatoorah
    |
    | .env is not used for mode or currency.
    |
    */

    'defaults' => [
        'mode' => 'sandbox',
        'currency' => 'SAR',
        'language' => 'ar',
        'country_code' => 'SAU',
    ],

    /** Optional fallback only when admin panel has no API key yet (local dev). */
    'api_key' => env('MYFATOORAH_API_KEY'),

    /** Optional fallback for webhook secret if not set in admin. */
    'webhook_secret' => env('MYFATOORAH_WEBHOOK_SECRET'),

    'base_urls' => [
        'sandbox' => 'https://apitest.myfatoorah.com',
        'production' => 'https://api-sa.myfatoorah.com',
    ],

    'processor' => 'myfatoorah',

    'gateway_name' => 'MyFatoorah',

];
