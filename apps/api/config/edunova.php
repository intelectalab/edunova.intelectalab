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
    'web_url' => env('EDUNOVA_WEB_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/app'),

    // Dominio de los subdominios de los colegios (<slug>.<dominio>).
    'dominio_colegios' => env('EDUNOVA_DOMINIO_COLEGIOS', 'edunova.pe'),

];
