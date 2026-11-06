<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EDUN-209 · Cabeceras de seguridad de la API (docs/05: HTTPS, HSTS y cabeceras).
 * La API solo devuelve JSON: no se puede incrustar en marcos ni interpretar como otro tipo.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $h = $respuesta->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure() || app()->environment('production')) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $respuesta;
    }
}
