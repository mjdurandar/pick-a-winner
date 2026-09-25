<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Alerts
    |--------------------------------------------------------------------------
    |
    | Who is told when the master sheet sync parks a new location or a change
    | that nobody has accepted yet. Comma-separated for more than one mailbox.
    |
    | Left empty this resolves to services.google.sheets.owner_email, which is
    | the only account allowed to approve a batch — sending the alert anywhere
    | else by default would tell people about work they cannot do.
    |
    */

    'approval_recipients' => env('APPROVAL_NOTIFICATION_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Standing CC
    |--------------------------------------------------------------------------
    |
    | Copied on every email the application sends. Comma-separated.
    |
    | Applied by App\Listeners\AddAlwaysCcRecipient on the send event, so it
    | covers mail added later without anyone having to remember it. Password
    | resets are the deliberate exception — see NEVER_CC on that listener.
    |
    */

    'always_cc' => env('MAIL_ALWAYS_CC'),

    /*
    |--------------------------------------------------------------------------
    | Film Site Check Alerts
    |--------------------------------------------------------------------------
    |
    | Who is told when a film's website and the Win App stop agreeing. One mail
    | per check, per new difference — never for differences already reported.
    | Comma-separated for more than one mailbox.
    |
    | Left empty this falls back to approval_recipients, then to the master sheet
    | owner, so the alert always reaches someone who can act on it.
    |
    */

    'site_check_recipients' => env('SITE_CHECK_NOTIFICATION_EMAIL'),

    // Copied on the film site alert only. Comma-separated.
    'site_check_cc' => env('SITE_CHECK_CC'),

    /*
    |--------------------------------------------------------------------------
    | Weekly Digest
    |--------------------------------------------------------------------------
    |
    | Who gets Monday's digest of last week's screenings and whatever the master
    | sheet sync and the film site checks are still waiting on. Comma-separated.
    |
    | Left empty it falls back to approval_recipients, and then to the master
    | sheet owner: half the mail is work only they can action, so it should still
    | reach someone who can do something about it.
    |
    */

    'weekly_digest_recipients' => env('WEEKLY_DIGEST_EMAIL'),

    // Copied on the weekly digest only. Comma-separated.
    'weekly_digest_cc' => env('WEEKLY_DIGEST_CC'),

    /*
    |--------------------------------------------------------------------------
    | Brand
    |--------------------------------------------------------------------------
    |
    | The name on every email — header, footer and subject lines.
    |
    | Deliberately not config('app.name'): APP_NAME also derives the cache prefix
    | (config/cache.php) and the session cookie name (config/session.php), so
    | renaming it would orphan the cached Mailchimp auto-sync settings and sign
    | every user out. This changes the mail and nothing else.
    |
    */

    'brand' => env('MAIL_BRAND', 'Win App'),

];
