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

    // Each gym connects its own Chapa account; only Chapa's address is set here (ADR 0009).
    'chapa' => [
        'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'),
    ],

    // How text messages are sent: "log" (writes them to the log) or "afromessage" (ADR 0010).
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    // The platform's own AfroMessage account, used when SMS_DRIVER=afromessage.
    'afromessage' => [
        'base_url' => env('AFROMESSAGE_BASE_URL', 'https://api.afromessage.com/api'),
        'token' => env('AFROMESSAGE_TOKEN'),
        'identifier_id' => env('AFROMESSAGE_IDENTIFIER_ID'),
        'sender_name' => env('AFROMESSAGE_SENDER_NAME'),
    ],

];
