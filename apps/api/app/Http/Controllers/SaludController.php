<?php

namespace App\Http\Controllers;

use App\Modulos\Compartido\Monitoreo\Monitoreo;
use Illuminate\Http\JsonResponse;

/**
 * EDUN-206 · Ruta de salud: base de datos, Redis y cola. Pública y sin datos sensibles (ni
 * versiones, ni mensajes de error, ni nombres de servidores): solo «ok» o «falla».
 */
class SaludController extends Controller
{
    public function __invoke(Monitoreo $monitoreo): JsonResponse
    {
        $salud = $monitoreo->salud();

        return response()->json($salud, $salud['estado'] === 'ok' ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
