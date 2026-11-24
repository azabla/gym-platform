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

    // The staff app's offline door lists are signed with this Ed25519 key
    // (php artisan door-list:generate-key). See ADR 0038.
    'door_list' => [
        'signing_key' => env('DOOR_LIST_SIGNING_KEY'),
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

    // How push notifications are sent: "log" (writes them to the log) or "fcm" (ADR 0012).
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
    ],

    // The platform's Firebase project, used when PUSH_DRIVER=fcm. Its service account's JSON key
    // comes from FCM_CREDENTIALS_JSON (the key, base64-encoded), on hosts without a disk such as
    // Render, or else from FCM_CREDENTIALS (the path to the key's file), kept out of git (ADR 0028).
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'),
        'credentials_json' => env('FCM_CREDENTIALS_JSON'),
    ],

    // The platform's own AfroMessage account, used when SMS_DRIVER=afromessage.
    'afromessage' => [
        'base_url' => env('AFROMESSAGE_BASE_URL', 'https://api.afromessage.com/api'),
        'token' => env('AFROMESSAGE_TOKEN'),
        'identifier_id' => env('AFROMESSAGE_IDENTIFIER_ID'),
        'sender_name' => env('AFROMESSAGE_SENDER_NAME'),
    ],

];
