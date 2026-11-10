<?php

namespace App\Modulos\PLA\Http\Middleware;

use App\Modulos\PLA\Services\ModulosColegio;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * EDUN-25 · ->middleware('modulo:BIB'): si el colegio no tiene el módulo encendido, la ruta
 * responde 403. Va después de «colegio». Los datos del módulo se conservan.
 */
class VerificarModulo
{
    public function __construct(private ModulosColegio $modulos) {}

    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        if (! $this->modulos->activo($modulo)) {
            $nombre = DB::table('modulos_catalogo')->where('codigo', strtoupper($modulo))->value('nombre') ?? $modulo;

            return response()->json(['message' => "El módulo «{$nombre}» no está activo para tu colegio.", 'codigo' => 'modulo_inactivo'], 403);
        }

        return $next($request);
    }
}
