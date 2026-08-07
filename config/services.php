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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mailchimp' => [
        // OAuth2 credentials from a Mailchimp Registered App (Account -> Extras ->
        // Registered Apps). Used only by the CSV import feature, which connects per
        // account rather than reading the static API keys below. The redirect must
        // match what is registered with Mailchimp exactly; left null it falls back
        // to the callback route.
        'oauth' => [
            'client_id' => env('MAILCHIMP_OAUTH_CLIENT_ID'),
            'client_secret' => env('MAILCHIMP_OAUTH_CLIENT_SECRET'),
            'redirect' => env('MAILCHIMP_OAUTH_REDIRECT'),
        ],

        'anz' => [
            'key' => env('MAILCHIMP_API_KEY'),
            'server' => env('MAILCHIMP_SERVER_PREFIX'),
        ],
        'usa' => [
            'key' => env('MAILCHIMP_USA_API_KEY'),
            'server' => env('MAILCHIMP_USA_SERVER_PREFIX'),
        ],
        // Legacy support
        'key' => env('MAILCHIMP_API_KEY'),
        'server' => env('MAILCHIMP_SERVER_PREFIX'),
    ],

    'eventbrite' => [
        'api_token' => env('EVENTBRITE_API_TOKEN'),
    ],

];
