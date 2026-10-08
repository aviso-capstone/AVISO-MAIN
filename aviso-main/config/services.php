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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
    ],

    'smsapiph' => [
        'key' => env('SMSAPIPH_KEY'),
        'url' => env('SMSAPIPH_URL', 'https://smsapiph.onrender.com/api/v1/send/sms'),
    ],

    'unismsapi' => [
        'key'       => env('UNISMSAPI_KEY'),
        'url'       => env('UNISMSAPI_URL', 'https://unismsapi.com/api/sms'),
        'sender_id' => env('UNISMSAPI_SENDER_ID', 'UnisoftDEV'),
    ],

    'skysms' => [
        'key' => env('SKYSMS_KEY'),
        'url' => env('SKYSMS_URL', 'https://skysms.skyio.site/api/v1/sms/send'),
    ],

    'mapbox' => [
        'token' => env('VITE_MAPBOX_TOKEN'),
    ],

];
