<?php

declare(strict_types=1);

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

    /*
     * Stripe.
     *
     * The webhook tolerance bounds replay: an event whose timestamp is older
     * than this is rejected even when its signature is valid, so a captured
     * request cannot be replayed indefinitely.
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
        'api_version' => env('STRIPE_API_VERSION'),
    ],

    /*
     * Cloudflare. Read by the forward-DNS adapter in the Dns module and by the
     * reverse-DNS adapter in Ipam, through one connection class, so that a
     * token configured once is a token used everywhere.
     *
     * `account_id` is optional and gates exactly one capability: creating
     * zones. Without it the adapter reads and writes records in zones the
     * account already holds and refuses to create new ones, because creating a
     * zone in the wrong account is not something the platform can undo.
     */
    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'base_url' => env('CLOUDFLARE_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CLOUDFLARE_TIMEOUT_SECONDS', 10),
        'verify_tls' => (bool) env('CLOUDFLARE_VERIFY_TLS', true),
    ],

];
