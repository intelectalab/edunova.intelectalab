<?php

namespace App\Modulos\POR\Http\Controllers;

use App\Modulos\POR\Services\Salidas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** EDUN-214 · Portería: lista del turno para verificar sin señal y registro de salidas (en línea o en lote). */
class SalidasController
{
    public function __construct(private Salidas $salidas) {}

    public function lista(): JsonResponse
    {
        return response()->json($this->salidas->lista())->header('Cache-Control', 'no-store');
    }

    public function buscar(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']])['q'];

        return response()->json(['data' => $this->salidas->buscar($q)]);
    }

    public function puedenRecoger(int $alumno): JsonResponse
    {
        return response()->json(['data' => $this->salidas->puedenRecoger($alumno)])->header('Cache-Control', 'no-store');
    }

    public function registrar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'], 'numero_documento' => ['required', 'string', 'max:15'], 'motivo' => ['nullable', 'string', 'max:200'],
        ], ['numero_documento.required' => 'Escribe el documento de quien recoge.']);

        return response()->json($this->salidas->registrar((int) $d['alumno_id'], $d['numero_documento'], 'porteria', $d['motivo'] ?? null, (int) $request->user()->id), 201);
    }

    public function lote(Request $request): JsonResponse
    {
        $d = $request->validate([
            'salidas' => ['required', 'array', 'min:1', 'max:50'], 'salidas.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'salidas.*.alumno_id' => ['required', 'integer'], 'salidas.*.numero_documento' => ['required', 'string', 'max:15'],
            'salidas.*.salio_at' => ['required', 'date'], 'salidas.*.motivo' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json($this->salidas->registrarLote($d['salidas'], (int) $request->user()->id));
    }

    public function delDia(Request $request): JsonResponse
    {
        $f = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? null;

        return response()->json(['data' => $this->salidas->delDia($f)]);
    }
}
