<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Http\Middleware\VisitanteSitio;
use App\Modulos\SIT\Http\Requests\ReclamoRequest;
use App\Modulos\SIT\Services\LibroReclamaciones;
use App\Modulos\SIT\Services\Recaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Libro de Reclamaciones virtual (TRASPASO §8):
 *   POST /sitio/reclamaciones                                         registrar (EDUN-294)
 *   GET  /sitio/reclamaciones/seguimiento?expediente=&documento=      estado (EDUN-298)
 */
class ReclamacionesController
{
    /** EDUN-298 · 10 consultas fallidas seguidas desde una IP bloquean la siguiente 15 minutos. */
    public const FALLOS_PERMITIDOS = 10;

    public const BLOQUEO_SEGUNDOS = 15 * 60;

    public function __construct(private LibroReclamaciones $libro, private Recaptcha $recaptcha) {}

    public function store(ReclamoRequest $request): JsonResponse
    {
        $ip = VisitanteSitio::ip($request);
        if (! $this->recaptcha->valido($request->input('recaptcha'), 'reclamo', $ip)) {
            throw ValidationException::withMessages(['recaptcha' => 'No pudimos verificar el envío. Recarga la página e inténtalo de nuevo.']);
        }

        $d = $request->validated();
        $r = $this->libro->registrar($d, array_values($request->file('adjuntos', [])), $ip);

        return response()->json([
            'message' => 'Reclamación registrada.',
            'expediente' => $r['expediente'],
            'vence_en' => $r['vence_en'],
            'email' => $r['email'],
            'plazo_dias_habiles' => LibroReclamaciones::PLAZO_DIAS_HABILES,
        ], 201);
    }

    public function seguimiento(Request $request): JsonResponse
    {
        $clave = 'seguimiento-reclamo:'.VisitanteSitio::ip($request);
        if (RateLimiter::tooManyAttempts($clave, self::FALLOS_PERMITIDOS)) {
            $minutos = (int) ceil(RateLimiter::availableIn($clave) / 60);

            return response()->json(['message' => "Hiciste demasiadas consultas sin resultado. Inténtalo de nuevo en {$minutos} minutos."], 429);
        }

        $expediente = strtoupper(trim((string) $request->query('expediente')));
        $documento = trim((string) $request->query('documento'));

        $fila = null;
        if (preg_match('/^REC-\d{4}-\d{4,}$/', $expediente) && preg_match('/^[0-9A-Za-z -]{6,15}$/', $documento)) {
            $fila = DB::selectOne('select * from cms.seguimiento_reclamo(?, ?)', [$expediente, $documento]);
        }

        if (! $fila) {
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);

            // Respuesta genérica: no dice si existe el expediente ni de quién es.
            return response()->json(['message' => 'No encontramos un reclamo con esos datos.'], 404);
        }

        RateLimiter::clear($clave);

        return response()->json([
            'expediente' => $fila->numero_expediente,
            'tipo' => $fila->tipo,
            'estado' => $fila->estado,
            'actualizado_en' => $fila->actualizado_en,
            'vence_en' => $fila->vence_en,
        ])->header('Cache-Control', 'no-store');
    }
}
