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

        // The audience's own hosted sign-up form, taken from the embed code
        // Mailchimp generates (Audience -> Signup forms -> Embedded form). Its
        // parameters are what the form posts, not secrets.
        //
        // This is the only route back for a contact Mailchimp holds in a compliance
        // state: the API refuses them at any privilege level, because opting back in
        // has to come from the person. Submitting it for someone who is filling in
        // the sign-up form right now relays their own act; it is not a way to
        // re-add contacts in bulk, and must never be used for one.
        'hosted_form' => [
            'anz' => [
                'domain' => 'https://adventureentertainment.us13.list-manage.com',
                'u' => '8d8ea490ca17c83e195d0d40f',
                'id' => '0c4330d445',
                'f_id' => '00ea24eaf0',
                'tag' => '7218045',
            ],
            'usa' => [
                'domain' => 'https://flyfilmtour.us19.list-manage.com',
                'u' => 'e2c1a1d1c56dc4e1a61f99090',
                'id' => 'f703c9728c',
                'f_id' => '00e88fe4f0',
                'tag' => null,
            ],
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

    'google' => [
        // OAuth2 credentials from a Google Cloud project (APIs & Services ->
        // Credentials -> OAuth client ID, type "Web application"). The Google
        // Sheets API must be enabled on the same project or every read returns 403.
        //
        // The redirect must match what is registered in the Cloud console character
        // for character; left null it falls back to the callback route.
        'oauth' => [
            'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
            'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
            'redirect' => env('GOOGLE_OAUTH_REDIRECT'),
        ],

        // The shared master sheet the location sync pulls from. The id is the long
        // path segment in the sheet's URL:
        // docs.google.com/spreadsheets/d/<THIS PART>/edit
        //
        // The range is read in A1 notation and must include the header row — the
        // importer maps columns by header name, not by position, so inserting a
        // column in the sheet does not break the sync.
        'sheets' => [
            'master_sheet_id' => env('GOOGLE_MASTER_SHEET_ID'),
            'master_sheet_range' => env('GOOGLE_MASTER_SHEET_RANGE'),

            // The one account allowed near the sync screen. Narrower than the
            // admin role on purpose: this screen holds a Google connection and
            // can delete locations, and every other admin has no reason to be
            // there. Set MASTER_SHEET_OWNER_EMAIL to hand it to someone else.
            'owner_email' => env('MASTER_SHEET_OWNER_EMAIL', 'mj@adventureentertainment.com'),
        ],
    ],

    'eventbrite' => [
        'api_token' => env('EVENTBRITE_API_TOKEN'),
    ],

];
