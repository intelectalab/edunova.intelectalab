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

    // EDUN-233 · Revalidación del sitio Next.js al publicar desde el CMS.
    'sitio' => [
        'revalidar_url' => env('SITIO_REVALIDAR_URL'),
        'revalidar_secreto' => env('SITIO_REVALIDAR_SECRETO'),
        // Sprint 6 · El servidor del sitio reenvía los formularios con la IP y el navegador del
        // visitante; la API solo les cree si viene este secreto compartido.
        'proxy_secreto' => env('SITIO_PROXY_SECRETO'),
    ],

    // EDUN-208 · Webhook de rebotes y quejas del proveedor de correo.
    'correo' => [
        'webhook_secreto' => env('CORREO_WEBHOOK_SECRETO'),
    ],

];
