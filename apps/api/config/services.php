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
        // URL pública del sitio (enlaces de los correos, p. ej. el seguimiento del reclamo).
        'url' => env('SITIO_URL', env('APP_URL')),
    ],

    // EDUN-206 · Alertas del monitoreo (webhook tipo Slack/Teams/Discord: POST JSON {text}).
    'alertas' => [
        'webhook' => env('ALERTAS_WEBHOOK_URL'),
    ],

    // EDUN-275 · CRM que recibe los leads (webhook genérico: POST JSON con token Bearer).
    // Sin URL no se envía nada: los leads quedan en cms.leads_pendientes hasta configurarlo.
    'crm' => [
        'url' => env('CRM_WEBHOOK_URL'),
        'token' => env('CRM_WEBHOOK_TOKEN'),
        'timeout' => (int) env('CRM_WEBHOOK_TIMEOUT', 10),
    ],

    // EDUN-294 · reCAPTCHA v3 del Libro de Reclamaciones (sin secreto, no se exige).
    'recaptcha' => [
        'secreto' => env('RECAPTCHA_SECRETO'),
        'umbral' => (float) env('RECAPTCHA_UMBRAL', 0.5),
    ],

    // EDUN-90 · Proveedores de facturación electrónica: «codigo:Nombre,codigo:Nombre».
    'facturacion' => [
        'proveedores' => env('FACTURACION_PROVEEDORES', 'proveedor_a:Proveedor A,proveedor_b:Proveedor B'),
        // Mientras no haya conector real, los comprobantes se emiten con el proveedor simulado.
        'simular' => (bool) env('FACTURACION_SIMULADA', true),
        // código del proveedor => clase que implementa App\Modulos\PAG\Facturacion\ProveedorFacturacion
        'adaptadores' => [],
    ],

    // EDUN-88 · Pasarela de pagos: cada colegio cobra con su propia cuenta. Sin conector real
    // (o en el servidor de pruebas) se usa la pasarela simulada, con el mismo webhook firmado.
    'pasarela' => [
        'simular' => (bool) env('PASARELA_SIMULADA', true),
        'adaptadores' => [],
    ],

    // EDUN-196 · Notificaciones push de la app (Expo entrega por APNs y FCM).
    'expo' => [
        'push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
    ],

    // EDUN-208 · Webhook de rebotes y quejas del proveedor de correo.
    'correo' => [
        'webhook_secreto' => env('CORREO_WEBHOOK_SECRETO'),
    ],

];
