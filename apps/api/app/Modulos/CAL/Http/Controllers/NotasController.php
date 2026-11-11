<?php

namespace App\Modulos\CAL\Http\Controllers;

use App\Modulos\CAL\Services\CierrePeriodos;
use App\Modulos\CAL\Services\RegistroNotas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sprint 18 · CAL: notas por competencia, actividades, revisión, cierre y reapertura del periodo. */
class NotasController
{
    public function __construct(private RegistroNotas $notas, private CierrePeriodos $cierres) {}

    public function cargas(Request $request): JsonResponse
    {
        $anio = (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];

        return response()->json(['cargas' => $this->notas->cargas($request->user(), $anio), 'periodos' => $this->notas->periodos($anio)]);
    }

    public function grilla(Request $request, int $carga): JsonResponse
    {
        $periodo = (int) $request->validate(['periodo_id' => ['required', 'integer']])['periodo_id'];

        return response()->json($this->notas->grilla($carga, $periodo, $request->user()));
    }

    public function guardar(Request $request, int $carga): JsonResponse
    {
        $d = $request->validate([
            'periodo_id' => ['required', 'integer'], 'alumno_id' => ['required', 'integer'], 'competencia_id' => ['required', 'integer'],
            'valor' => ['nullable', 'string', 'max:3'], 'conclusion' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->notas->guardar($carga, (int) $d['periodo_id'], (int) $d['alumno_id'], (int) $d['competencia_id'], $d['valor'] ?? null, $d['conclusion'] ?? null, $request->user()));
    }

    public function actividades(Request $request, int $carga): JsonResponse
    {
        $periodo = (int) $request->validate(['periodo_id' => ['required', 'integer']])['periodo_id'];

        return response()->json($this->notas->actividades($carga, $periodo, $request->user()));
    }

    public function crearActividad(Request $request, int $carga): JsonResponse
    {
        $d = $request->validate([
            'periodo_id' => ['required', 'integer'], 'competencia_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:150'], 'fecha' => ['nullable', 'date_format:Y-m-d'],
        ], ['nombre.required' => 'Ponle un nombre a la actividad.', 'competencia_id.required' => 'Elige la competencia que evalúa.']);
        $id = $this->notas->crearActividad($carga, (int) $d['periodo_id'], (int) $d['competencia_id'], $d['nombre'], $d['fecha'] ?? null, $request->user());

        return response()->json(['id' => $id] + $this->notas->actividades($carga, (int) $d['periodo_id'], $request->user()), 201);
    }

    public function notasActividad(Request $request, int $actividad): JsonResponse
    {
        $d = $request->validate(['notas' => ['required', 'array'], 'notas.*.valor' => ['nullable', 'string', 'max:3'], 'notas.*.comentario' => ['nullable', 'string', 'max:500']]);

        return response()->json(['guardadas' => $this->notas->guardarNotasActividad($actividad, $d['notas'], $request->user())]);
    }

    public function eliminarActividad(Request $request, int $actividad): JsonResponse
    {
        $this->notas->eliminarActividad($actividad, $request->user());

        return response()->json(['message' => 'Actividad eliminada.']);
    }

    public function avance(int $periodo): JsonResponse
    {
        return response()->json($this->cierres->avance($periodo));
    }

    public function recordar(int $periodo): JsonResponse
    {
        return response()->json($this->cierres->recordar($periodo));
    }

    public function cerrar(Request $request, int $periodo): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer']]);
        $this->cierres->cerrar((int) $d['seccion_id'], $periodo, (int) $request->user()->id);

        return response()->json($this->cierres->avance($periodo));
    }

    public function reabrir(Request $request, int $periodo): JsonResponse
    {
        $d = $request->validate(['seccion_id' => ['required', 'integer'], 'motivo' => ['required', 'string', 'min:10', 'max:500']],
            ['motivo.required' => 'Reabrir exige un motivo.', 'motivo.min' => 'Explica el motivo (al menos 10 caracteres).']);
        $this->cierres->reabrir((int) $d['seccion_id'], $periodo, $d['motivo'], (int) $request->user()->id);

        return response()->json($this->cierres->avance($periodo));
    }
}
