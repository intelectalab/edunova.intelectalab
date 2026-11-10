<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Modulos\SEG\Services\DosPasos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EDUN-38 · /api/v1/auth/dos-pasos/*: activar con la app autenticadora, verificar y respaldos. */
class DosPasosController
{
    public function __construct(private DosPasos $dosPasos) {}

    public function iniciar(Request $request): JsonResponse
    {
        return response()->json($this->dosPasos->iniciar($request->user()));
    }

    public function activar(Request $request): JsonResponse
    {
        $codigo = $this->codigo($request);
        $codigos = $this->dosPasos->activar($request->user(), $codigo, $request->user()->currentAccessToken());

        return response()->json(['message' => 'Segundo factor activado.', 'codigos_respaldo' => $codigos]);
    }

    public function verificar(Request $request): JsonResponse
    {
        $codigo = $this->codigo($request);
        $usoRespaldo = $this->dosPasos->verificar($request->user(), $codigo, $request->user()->currentAccessToken());

        return response()->json([
            'message' => 'Identidad confirmada.',
            'uso_respaldo' => $usoRespaldo,
            'respaldos_restantes' => $this->dosPasos->respaldosRestantes($request->user()->refresh()),
        ]);
    }

    public function respaldos(Request $request): JsonResponse
    {
        $codigo = $this->codigo($request);

        return response()->json(['codigos_respaldo' => $this->dosPasos->regenerarRespaldos($request->user(), $codigo)]);
    }

    private function codigo(Request $request): string
    {
        return (string) $request->validate(
            ['codigo' => ['required', 'string', 'max:20']],
            ['codigo.required' => 'Escribe el código de 6 dígitos de tu app.'],
        )['codigo'];
    }
}
