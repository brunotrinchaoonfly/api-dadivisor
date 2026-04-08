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

    /*
    |--------------------------------------------------------------------------
    | Claude API (Anthropic) — mantido por compatibilidade
    |--------------------------------------------------------------------------
    */
    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers AI
    |--------------------------------------------------------------------------
    */
    'cloudflare_ai' => [
        'account_id'     => env('CLOUDFLARE_AI_ACCOUNT_ID'),
        'api_token'      => env('CLOUDFLARE_AI_API_TOKEN'),
        'model'          => env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3-8b-instruct'),
        'fallback_regex' => env('CLOUDFLARE_AI_FALLBACK_REGEX', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Onfly API
    |--------------------------------------------------------------------------
    */
    'onfly' => [
        'base_url'      => env('ONFLY_API_BASE', 'https://api.onfly.com.br'),
        'client_id'     => env('ONFLY_CLIENT_ID'),
        'client_secret' => env('ONFLY_CLIENT_SECRET'),
        'gateway_url'   => env('ONFLY_GATEWAY_URL', 'https://gateway.viagens.dev'),
        'token_dev'     => env('TOKEN_DEV'),
    ],

];
