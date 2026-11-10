<?php

namespace App\Modulos\SEG\Http\Middleware;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Autorizacion;
use App\Modulos\SEG\Services\DosPasos;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Colegio activo de la petición (docs/rls-tablas-globales.md): abre una transacción, fija
 * app.institucion_id con SET LOCAL y comprueba que el usuario tenga una membresía vigente.
 *
 * El colegio sale del token de la sesión (POST /auth/colegio) o, si no hay, de la cabecera
 * X-Institucion.
 */
class IdentificarInstitucion
{
    public function __construct(private Autorizacion $autorizacion) {}

    public function handle(Request $request, Closure $next): Response
    {
        // El colegio elegido al iniciar sesión vive en el token (EDUN-35). Si el token no
        // lo tiene, se acepta la cabecera X-Institucion; la membresía se valida igual.
        $token = $request->user()?->currentAccessToken();
        $institucion = isset($token->institucion_id) ? (string) $token->institucion_id : $request->header('X-Institucion');
        if ($institucion === null || ! ctype_digit($institucion)) {
            return response()->json(['message' => 'Elige un colegio para continuar.'], 400);
        }

        return DB::transaction(function () use ($request, $next, $institucion) {
            ContextoInstitucion::fijar((int) $institucion);
            $this->autorizacion->olvidar();

            // EDUN-28 · Colegio suspendido o dado de baja: nadie trabaja ahí (aviso neutro).
            $estado = DB::table('instituciones')->where('id', (int) $institucion)->value('estado');
            if (in_array($estado, ['suspendido', 'baja'], true)) {
                return response()->json(['message' => 'El servicio de tu colegio no está disponible por el momento. Comunícate con el colegio.', 'codigo' => 'colegio_no_disponible'], 403);
            }

            $usuario = $request->user();
            if ($usuario && ! $this->autorizacion->esMiembro($usuario)) {
                return response()->json(['message' => 'No perteneces a este colegio.'], 403);
            }

            // EDUN-38 · Promotor, director y tesorería (o quien activó el segundo factor)
            // necesitan la sesión verificada con el código de la app autenticadora.
            if ($usuario && ! DosPasos::verificado($usuario->currentAccessToken())
                && ($usuario->dos_pasos_activo_at !== null || $this->autorizacion->tieneRol($usuario, ...DosPasos::ROLES_OBLIGATORIOS))) {
                return response()->json(['message' => 'Confirma tu identidad con el segundo factor para continuar.', 'codigo' => 'dos_pasos'], 403);
            }

            return $next($request);
        });
    }
}
