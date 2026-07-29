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

    'azure_openai' => [
        'api_key' => env('AZURE_OPENAI_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('AZURE_OPENAI_BASE_URL'),
        'model' => env('AGENT_MODEL', 'gpt-5.4-mini'),
    ],

    'thy_mcp' => [
        'url' => env('THY_MCP_URL'),
        'token' => env('THY_MCP_TOKEN'),
        'token_file' => env('THY_MCP_TOKEN_FILE', base_path('temp/data/thy_tokens.json')),
        'client_name' => env('THY_MCP_CLIENT_NAME', env('APP_NAME', 'Dynamic Pricer')),
    ],

];
