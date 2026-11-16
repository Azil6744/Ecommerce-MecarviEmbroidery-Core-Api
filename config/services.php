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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'), // Can be 'sandbox' or 'live'
    ],

    'central_auth' => [
        'internal_url' => env('CENTRAL_AUTH_INTERNAL_URL'),
        'url' => env(
            'CENTRAL_AUTH_URL',
            in_array(env('APP_ENV', 'production'), ['local', 'testing'])
                ? 'http://127.0.0.1:8001/api'
                : 'https://auth-api.mecarvi.com/api'
        ),
        'api_url' => env('CENTRAL_AUTH_API_URL'),
    ],

    'mccarvy_site' => [
        'slug' => env('MCCARVY_SITE_SLUG', env('SITE_SLUG', 'embroidery')),
    ],

    'pin_authorization' => [
        'secret' => env('PIN_AUTHORIZATION_SECRET', env('APP_KEY')),
    ],

    'internal_notifications' => [
        'secret' => env('INTERNAL_NOTIFICATION_SECRET', 'mccarvy_default_secret_key_12345'),
    ],

    'user_panel' => [
        'url' => env(
            'USER_PANEL_URL',
            in_array(env('APP_ENV', 'production'), ['local', 'testing'])
                ? 'http://localhost:3001'
                : 'https://user.mecarvi.com'
        ),
    ],

];
