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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),

        // documents.readonly, not the full Drive scope: this integration
        // only ever reads one Doc's text into a ticker — narrowing the
        // OAuth consent screen to what's actually used is both a
        // security default and, practically, the difference between a
        // scope Google's verification process waves through and one
        // that needs an app review.
        'scopes' => ['https://www.googleapis.com/auth/documents.readonly'],
    ],

    'mcp' => [
        // Fixed dev token, not Sanctum/OAuth — see routes/ai.php and
        // App\Http\Middleware\EnsureValidMcpToken for why this is
        // deliberately the simplest thing that works for a local POC.
        'token' => env('MCP_DEV_TOKEN'),
    ],

];
