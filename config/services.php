
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services
    | such as Resend, Postmark, AWS, Xendit, and more.
    |
    | This provides a conventional location for third-party service
    | credentials and configuration.
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
    |--------------------------------------------------------------------------
    | Xendit Test Mode - SafeSakay
    |--------------------------------------------------------------------------
    |
    | Xendit payment gateway (Invoices / payment links / e-wallets).
    | Keep XENDIT_ENABLED=false until keys and webhooks are configured.
    | Secret keys must remain in the Laravel backend only.
    |
    */

    'xendit' => [

        'enabled' => env('XENDIT_ENABLED', false),

        'secret_key' => env('XENDIT_SECRET_KEY'),

        'base_url' => env(
            'XENDIT_BASE_URL',
            'https://api.xendit.co'
        ),

        'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),

        'success_redirect_url' => env(
            'XENDIT_SUCCESS_REDIRECT_URL'
        ),

        'failure_redirect_url' => env(
            'XENDIT_FAILURE_REDIRECT_URL'
        ),

        'session_duration_seconds' => (int) env(
            'XENDIT_SESSION_DURATION_SECONDS',
            7200
        ),

        'allow_http_redirects' => env('XENDIT_ALLOW_HTTP_REDIRECTS', false),

        'test_mode_only' => env('XENDIT_TEST_MODE_ONLY', true),

        'operator_payouts' => env(
            'XENDIT_OPERATOR_PAYOUTS',
            false
        ),

    ],

    'expo_push' => [
        'enabled' => env('EXPO_PUSH_ENABLED', true),
        'url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        // Only needed when "Enhanced push security" is turned on for the Expo project.
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

];
