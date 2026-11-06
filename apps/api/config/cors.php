<?php

/*
 * EDUN-209 · CORS: la API solo acepta orígenes propios (web de gestión, sitio y apps).
 * CORS_ORIGENES es una lista separada por comas. Las apps móviles nativas no envían Origin;
 * las vistas web de las apps (Expo) usan su propio origen, que se agrega aquí.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ORIGENES', 'http://localhost:5174,http://localhost:3000'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Institucion'],

    'exposed_headers' => [],

    'max_age' => 600,

    // La API usa tokens Bearer, no cookies de sesión.
    'supports_credentials' => false,

];
