<?php

namespace App\Modulos\Compartido\Correo;

use App\Modulos\SIT\Services\Integraciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/correo/eventos · Rebotes y quejas que avisa el proveedor de correo.
 *
 * El proveedor todavía no está decidido (EDUN-203), así que el formato es propio:
 *   { "eventos": [ { "tipo": "rebote"|"queja", "email": "…", "detalle": "…" } ] }
 * con la cabecera X-Edunova-Firma = HMAC-SHA256 del cuerpo con CORREO_WEBHOOK_SECRETO.
 * Al elegir proveedor se agrega un adaptador de su formato que llama a lo mismo.
 */
class EventosCorreoController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secreto = Integraciones::secretoCorreo();
        $esperada = hash_hmac('sha256', $request->getContent(), $secreto);
        if ($secreto === '' || ! hash_equals($esperada, (string) $request->header('X-Edunova-Firma'))) {
            return response()->json(['message' => 'Firma no válida.'], 401);
        }

        $datos = $request->validate([
            'eventos' => ['required', 'array', 'max:500'],
            'eventos.*.tipo' => ['required', 'in:rebote,queja'],
            'eventos.*.email' => ['required', 'email:rfc', 'max:254'],
            'eventos.*.detalle' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($datos['eventos'] as $evento) {
            CorreoTransaccional::suprimir($evento['email'], $evento['tipo'], $evento['detalle'] ?? null);
        }

        return response()->json(['registrados' => count($datos['eventos'])]);
    }
}
