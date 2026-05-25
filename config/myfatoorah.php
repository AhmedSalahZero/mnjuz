<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MyFatoorah Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Credentials can be configured via .env or the admin payment gateway panel.
    | Environment variables take precedence when set.
    |
    */

    'api_key' => env('MYFATOORAH_API_KEY'),

    'base_url' => env('MYFATOORAH_BASE_URL'),

    'webhook_secret' => env('MYFATOORAH_WEBHOOK_SECRET'),

    'mode' => env('MYFATOORAH_MODE', 'sandbox'),

    'country_code' => env('MYFATOORAH_COUNTRY_CODE', 'SAU'),

    'currency' => env('MYFATOORAH_CURRENCY', 'SAR'),

    'language' => env('MYFATOORAH_LANGUAGE', 'ar'),

    'base_urls' => [
        'sandbox' => 'https://apitest.myfatoorah.com',
        'production' => 'https://api-sa.myfatoorah.com',
    ],

    'processor' => 'myfatoorah',

    'gateway_name' => 'MyFatoorah',

];
