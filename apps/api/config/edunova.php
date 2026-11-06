<?php

// Parámetros propios de Edunova.
return [

    'archivos' => [
        // Tamaño máximo por archivo; se rechaza antes de subir (EDUN-207).
        'max_mb' => (int) env('EDUNOVA_ARCHIVOS_MAX_MB', 10),
        // Vigencia de las URL firmadas de descarga (SEG-H08: 5 minutos).
        'url_minutos' => (int) env('EDUNOVA_ARCHIVOS_URL_MINUTOS', 5),
    ],

];
