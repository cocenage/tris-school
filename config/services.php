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
    'day_off' => [
        'profile_url' => env('DAY_OFF_PROFILE_URL'),
        'admin_chat_url' => env('DAY_OFF_ADMIN_CHAT_URL'),
    ],

    'vacation' => [
        'admin_chat_url' => env('VACATION_ADMIN_CHAT_URL'),
    ],

    'inventory' => [
        'applications_url' => env('INVENTORY_APPLICATIONS_URL'),
        'admin_chat_url' => env('INVENTORY_ADMIN_CHAT_URL'),
    ],

    'staff_forms' => [
        'chat_id' => env('TELEGRAM_CHAT_ID_STAFF_FORMS'),
        'thread_id' => env('TELEGRAM_THREAD_ID_STAFF_FORMS'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),

        'chat_id_formweekend' => env('TELEGRAM_CHAT_ID_FORMWEEKEND'),
        'thread_id_formweekend' => env('TELEGRAM_THREAD_ID_FORMWEEKEND'),

        'chat_id_vacation' => env('TELEGRAM_CHAT_ID_VACATION'),
        'thread_id_vacation' => env('TELEGRAM_THREAD_ID_VACATION'),

        'chat_id_inventory' => env('TELEGRAM_CHAT_ID_INVENTORY'),
        'thread_id_inventory' => env('TELEGRAM_THREAD_ID_INVENTORY'),

        'chat_id_calendar' => env('TELEGRAM_CHAT_ID_CALENDAR'),
        'thread_id_calendar' => env('TELEGRAM_THREAD_ID_CALENDAR'),
    ],

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

];
