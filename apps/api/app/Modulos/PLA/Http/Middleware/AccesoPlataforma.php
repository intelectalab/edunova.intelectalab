<?php

namespace App\Modulos\PLA\Http\Middleware;

use App\Modulos\SEG\Services\DosPasos;
use App\Modulos\SEG\Services\Sesion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consola interna /api/v1/plataforma/* (docs/05): solo staff de Edunova con la sesión verificada
 * por segundo factor. ->middleware('plataforma') deja entrar a superadmin y soporte (lectura);
 * ->middleware('plataforma:superadmin') solo al superadmin (crear y cambiar colegios).
 * Los servicios de la consola usan la conexión «plataforma» (rol edunova_plataforma).
 */
class AccesoPlataforma
{
    public function __construct(private Sesion $sesion) {}

    public function handle(Request $request, Closure $next, string $roles = 'superadmin|soporte'): Response
    {
        $usuario = $request->user();
        if (! $usuario) {
            return response()->json(['message' => 'Inicia sesión para continuar.'], 401);
        }
        if (array_intersect(explode('|', $roles), $this->sesion->rolesPlataforma($usuario)) === []) {
            return response()->json(['message' => 'No tienes acceso a la consola de la plataforma.'], 403);
        }
        if (! DosPasos::verificado($usuario->currentAccessToken())) {
            return response()->json(['message' => 'Confirma tu identidad con el segundo factor para continuar.', 'codigo' => 'dos_pasos'], 403);
        }

        return $next($request);
    }
}
