<?php

namespace App\Http\Middleware;

use App\Modulos\Compartido\Monitoreo\Monitoreo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** EDUN-206 · Cuenta las respuestas 5xx de la API para el monitoreo. */
class ContarErrores
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        if ($respuesta->getStatusCode() >= 500) {
            Monitoreo::contarError5xx();
        }

        return $respuesta;
    }
}
