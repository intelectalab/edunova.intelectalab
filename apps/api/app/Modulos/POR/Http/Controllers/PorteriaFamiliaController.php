<?php

namespace App\Modulos\POR\Http\Controllers;

use App\Modulos\POR\Services\PorteriaFamilia;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EDUN-187 autorizados desde la app · EDUN-189 salida anticipada · EDUN-190 visitas.
 */
class PorteriaFamiliaController
{
    public function __construct(private PorteriaFamilia $porteria) {}

    /** Solo el apoderado: un alumno no registra quién lo recoge ni pide su propia salida. */
    private function hijos(Request $request): array
    {
        return FamiliaController::hijosDeApoderado((int) $request->user()->id);
    }

    public function autorizados(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->porteria->autorizados($this->hijos($request))]);
    }

    public function guardarAutorizado(Request $request, ?int $autorizado = null): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'nombres' => ['required', 'string', 'min:3', 'max:200'],
            'numero_documento' => ['required', 'string', 'max:15'],
            'parentesco' => ['required', 'string', 'min:2', 'max:40'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
            'activo' => ['boolean'],
        ]);

        return response()->json($this->porteria->guardarAutorizado($autorizado, $d, $request->user(), $this->hijos($request)), $autorizado ? 200 : 201);
    }

    private function datosSalida(Request $request): array
    {
        return $request->validate([
            'alumno_id' => ['required', 'integer'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'por_autorizado_id' => ['nullable', 'integer'],
        ], ['motivo.required' => 'Cuenta el motivo de la salida.']);
    }

    public function salidasFamilia(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->porteria->anticipadas($this->hijos($request))]);
    }

    public function solicitar(Request $request): JsonResponse
    {
        return response()->json($this->porteria->solicitar($this->datosSalida($request), $request->user(), $this->hijos($request)), 201);
    }

    public function anularFamilia(Request $request, int $salida): JsonResponse
    {
        return response()->json($this->porteria->anular($salida, $request->user(), $this->hijos($request)));
    }

    public function anticipadas(Request $request): JsonResponse
    {
        $d = $request->validate(['estado' => ['nullable', Rule::in(['solicitada', 'autorizada', 'salio', 'rechazada', 'anulada'])]]);

        return response()->json(['data' => $this->porteria->anticipadas(null, $d['estado'] ?? null)]);
    }

    /** Secretaría registra una salida anticipada (por teléfono o en persona): queda autorizada. */
    public function registrarAnticipada(Request $request): JsonResponse
    {
        return response()->json($this->porteria->solicitar($this->datosSalida($request), $request->user(), [], 'secretaria'), 201);
    }

    public function resolver(Request $request, int $salida): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['autorizar', 'rechazar'])], 'respuesta' => ['nullable', 'string', 'max:300']]);

        return response()->json($this->porteria->resolver($salida, $d['decision'] === 'autorizar', $d['respuesta'] ?? null, $request->user()));
    }

    public function anular(Request $request, int $salida): JsonResponse
    {
        return response()->json($this->porteria->anular($salida, $request->user()));
    }

    public function visitas(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->porteria->visitas($d['fecha'] ?? null)]);
    }

    public function registrarVisita(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nombres' => ['required', 'string', 'min:3', 'max:200'],
            'numero_documento' => ['required', 'string', 'min:6', 'max:15'],
            'visita_a' => ['required', 'string', 'min:2', 'max:150'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->porteria->registrarVisita($d, (int) $request->user()->id), 201);
    }

    public function salidaVisita(int $visita): JsonResponse
    {
        $this->porteria->salidaVisita($visita);

        return response()->json(['ok' => true]);
    }
}
