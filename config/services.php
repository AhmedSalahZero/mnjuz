<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

    'paypal' => [
        'class' => App\Services\PayPalService::class,
    ],

    'stripe' => [
        'class' => App\Services\StripeService::class,
    ],

    'paystack' => [
        'class' => App\Services\PayStackService::class,
    ],

    'flutterwave' => [
        'class' => App\Services\FlutterwaveService::class,
    ],

    'myfatoorah' => [
        'class' => App\Services\MyFatoorahService::class,
    ],

    // 'clickpay' => [
    //     'class' => Modules\Clickpaysa\Controllers\ProcessPayment::class,
    // ],

    'razorpay' => [
        'class' => Modules\Razorpay\Controllers\ProcessPayment::class,
    ],

    'pabbly subscriptions' => [
        'class' => Modules\Pabbly\Controllers\ProcessPayment::class,
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS', resource_path('firebase/wazz-chat-firebase-adminsdk-fbsvc-bd4e1c725b.json')),
    ],

    /*
     |--------------------------------------------------------------------------
     | قنوات التواصل
     |--------------------------------------------------------------------------
     |
     | إعداد على مستوى التطبيق لا المنشأة: تطبيق ميتا واحد وتطبيق تيك توك
     | واحد يخدمان كل العملاء، وتوكن كل عميل يُخزَّن في channel_accounts.
     |
     | app_secret يُستعمل للتحقّق من توقيع الـ webhook، فغيابه يعني قبول أي
     | حمولة — والسائق يُسجّل تحذيراً حين يجده فارغاً.
     */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        // توكن الـ handshake — على مستوى التطبيق لا الحساب.
        //
        // ميتا تتحقّق من الويبهوك مرّة واحدة في لوحة التطبيق، وذلك يحدث
        // **قبل** أن يربط أيّ عميل صفحته. فتوكن مخزَّن في الحساب لا وجود
        // له لحظة التحقّق، والتحقّق يفشل ولا يمكن إكمال الإعداد أصلاً.
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    ],

    'tiktok' => [
        'app_id' => env('TIKTOK_APP_ID'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'api_version' => env('TIKTOK_API_VERSION', 'v1.3'),
    ],
];
