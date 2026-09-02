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

    'product_images' => [
        'driver' => env('PRODUCT_IMAGE_DRIVER', 'fake'),
        'queue_connection' => env('PRODUCT_IMAGE_QUEUE_CONNECTION', 'deferred'),
        'max_output_bytes' => 20 * 1024 * 1024,
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'endpoint' => env('OPENAI_IMAGE_ENDPOINT', 'https://api.openai.com/v1/images/edits'),
            'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
            'size' => env('OPENAI_IMAGE_SIZE', '1024x1024'),
            'quality' => env('OPENAI_IMAGE_QUALITY', 'high'),
            'timeout' => (int) env('OPENAI_IMAGE_TIMEOUT', 240),
        ],
    ],

];
