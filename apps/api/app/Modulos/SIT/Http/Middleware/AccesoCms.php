<?php

namespace App\Modulos\SIT\Http\Middleware;

use App\Modulos\SEG\Services\Sesion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * EDUN-226 · Guarda de /api/v1/cms/*: solo el superadmin del SaaS (rol de plataforma).
 * Ningún rol de colegio entra, ni siquiera el promotor (TRASPASO §9).
 *
 * Abre la transacción de la petición y fija app.rol_cms y app.usuario_id, que es lo que
 * usa el RLS del esquema cms. La conexión es la de la app (sin bypass de RLS).
 */
class AccesoCms
{
    public function __construct(private Sesion $sesion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if (! $usuario) {
            return response()->json(['message' => 'Inicia sesión para continuar.'], 401);
        }
        if (! in_array('superadmin', $this->sesion->rolesPlataforma($usuario), true)) {
            return response()->json(['message' => 'No tienes acceso al Panel CMS.'], 403);
        }

        return DB::transaction(function () use ($request, $next, $usuario) {
            DB::select("select set_config('app.rol_cms', 'superadmin', true), set_config('app.usuario_id', ?, true)", [(string) $usuario->id]);

            return $next($request);
        });
    }
}
