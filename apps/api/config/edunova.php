<?php

// Parámetros propios de Edunova.
return [

    'archivos' => [
        // Tamaño máximo por archivo; se rechaza antes de subir (EDUN-207).
        'max_mb' => (int) env('EDUNOVA_ARCHIVOS_MAX_MB', 10),
        // Vigencia de las URL firmadas de descarga (SEG-H08: 5 minutos).
        'url_minutos' => (int) env('EDUNOVA_ARCHIVOS_URL_MINUTOS', 5),
    ],

    // Web de gestión (enlaces de invitación). En el VPS se publica en /app del mismo dominio.
    // Acceso de demostración por rol (solo servidor de pruebas): sin contraseña, con el colegio ficticio.
    'demo' => [
        'activo' => (bool) env('EDUNOVA_DEMO_ACCESO', false),
        'colegio' => env('EDUNOVA_DEMO_COLEGIO', 'smp'),
    ],
    'web_url' => env('EDUNOVA_WEB_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/app'),

    // EDUN-27 · Comprobantes de Edunova a los colegios (cobro mensual). Los datos de la empresa
    // se configuran en el servidor; mientras FACTURACION_SIMULADA=true se emiten simulados.
    'facturacion_saas' => [
        'ruc' => env('EDUNOVA_RUC', ''),
        'razon_social' => env('EDUNOVA_RAZON_SOCIAL', 'Edunova'),
        'serie_factura' => env('EDUNOVA_SERIE_FACTURA', 'F001'),
        'serie_boleta' => env('EDUNOVA_SERIE_BOLETA', 'B001'),
        'ambiente' => env('EDUNOVA_FACTURACION_AMBIENTE', 'beta'),
        'adaptador' => env('EDUNOVA_FACTURACION_ADAPTADOR'),
    ],

    // Dominio de los subdominios de los colegios (<slug>.<dominio>).
    'dominio_colegios' => env('EDUNOVA_DOMINIO_COLEGIOS', 'edunova.pe'),

];
