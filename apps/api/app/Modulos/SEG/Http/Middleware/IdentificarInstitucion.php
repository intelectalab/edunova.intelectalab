<?php

namespace App\Modulos\SEG\Http\Middleware;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Autorizacion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Colegio activo de la petición (docs/rls-tablas-globales.md): abre una transacción, fija
 * app.institucion_id con SET LOCAL y comprueba que el usuario tenga una membresía vigente.
 *
 * El colegio llega en la cabecera X-Institucion. La elección de colegio al iniciar sesión
 * y su persistencia en el token llegan con EDUN-35; este middleware ya es el punto único.
 */
class IdentificarInstitucion
{
    public function __construct(private Autorizacion $autorizacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        $institucion = $request->header('X-Institucion');
        if ($institucion === null || ! ctype_digit($institucion)) {
            return response()->json(['message' => 'Elige un colegio para continuar.'], 400);
        }

        return DB::transaction(function () use ($request, $next, $institucion) {
            ContextoInstitucion::fijar((int) $institucion);
            $this->autorizacion->olvidar();

            $usuario = $request->user();
            if ($usuario && ! $this->autorizacion->esMiembro($usuario)) {
                return response()->json(['message' => 'No perteneces a este colegio.'], 403);
            }

            return $next($request);
        });
    }
}
