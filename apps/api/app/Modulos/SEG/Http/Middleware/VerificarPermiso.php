<?php

namespace App\Modulos\SEG\Http\Middleware;

use App\Modulos\SEG\Services\Autorizacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EDUN-39 · Middleware de permisos por ruta: ->middleware('permiso:adm.alumnos.ver').
 * Con «a|b» basta uno de los permisos. Va después de «colegio» (necesita el colegio activo).
 */
class VerificarPermiso
{
    public function __construct(private Autorizacion $autorizacion) {}

    public function handle(Request $request, Closure $next, string $permisos): Response
    {
        $usuario = $request->user();
        if (! $usuario) {
            return response()->json(['message' => 'Inicia sesión para continuar.'], 401);
        }
        if (! $this->autorizacion->tieneAlguno($usuario, explode('|', $permisos))) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
