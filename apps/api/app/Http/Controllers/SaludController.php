<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Ruta de salud de la API: confirma que la base de datos y Redis responden.
 * La amplía EDUN-206 (monitoreo y alertas).
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $servicios = [
            'base_de_datos' => $this->probar(fn () => DB::select('select version() as v')[0]->v),
            'redis' => $this->probar(fn () => Redis::connection()->ping() ? 'ok' : 'sin respuesta'),
        ];

        $ok = collect($servicios)->every(fn ($s) => $s['ok']);

        return response()->json([
            'estado' => $ok ? 'ok' : 'degradado',
            'version' => app()->version(),
            'servicios' => $servicios,
        ], $ok ? 200 : 503);
    }

    private function probar(callable $prueba): array
    {
        try {
            return ['ok' => true, 'detalle' => (string) $prueba()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detalle' => class_basename($e)];
        }
    }
}
