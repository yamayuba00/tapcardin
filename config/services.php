<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Ongkir ekspedisi: 'dummy' = tarif placeholder & pelacakan simulasi (lihat App\Support\Shipping).
    'shipping' => [
        'driver' => env('SHIPPING_DRIVER', 'dummy'),
        'origin_zone' => env('SHIPPING_ORIGIN_ZONE', 'Jawa-Bali'),
        'weight_gram_per_item' => (int) env('SHIPPING_WEIGHT_PER_ITEM_GRAM', 15),
    ],

    // Pembayaran langganan diatur di config/payment.php (PAYMENT_*).

    // Kunci akses API (header X-Tap-Key). Penghalang akses iseng, bukan rahasia: kunci di halaman web terbaca di sumber JS.
    'tap_api' => ['key' => env('TAPCARD_API_KEY')],

];
