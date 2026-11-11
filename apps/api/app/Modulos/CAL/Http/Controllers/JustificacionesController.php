<?php

namespace App\Modulos\CAL\Http\Controllers;

use App\Modulos\CAL\Services\Justificaciones;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EDUN-96 · Justificación de inasistencias:
 *   GET|POST /familia/justificaciones           la familia (solo sus hijos)
 *   GET  /asistencia/justificaciones            bandeja del tutor, coordinación y dirección
 *   POST /asistencia/justificaciones/{id}       aceptar o rechazar
 *   GET  /justificaciones/{id}/sustento         foto o PDF del sustento (familia o quien resuelve)
 */
class JustificacionesController
{
    public function __construct(private Justificaciones $justificaciones) {}

    public function misJustificaciones(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->justificaciones->deFamilia(FamiliaController::alumnosDe((int) $request->user()->id))]);
    }

    public function solicitar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'fecha_desde' => ['required', 'date_format:Y-m-d'],
            'fecha_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'sustento' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
        ], [
            'motivo.required' => 'Cuenta el motivo de la inasistencia.',
            'motivo.min' => 'Cuenta el motivo con un poco más de detalle.',
            'fecha_hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'sustento.mimes' => 'El sustento debe ser una foto (JPG, PNG, WebP o HEIC) o un PDF.',
            'sustento.max' => 'El sustento puede pesar hasta 5 MB.',
        ]);
        abort_unless(in_array((int) $d['alumno_id'], FamiliaController::alumnosDe((int) $request->user()->id), true), 403, 'Solo puedes justificar a tus hijos.');

        return response()->json($this->justificaciones->solicitar((int) $d['alumno_id'], $d['fecha_desde'], $d['fecha_hasta'], $d['motivo'], $request->file('sustento'), $request->user()), 201);
    }

    public function bandeja(Request $request): JsonResponse
    {
        $d = $request->validate(['estado' => ['nullable', Rule::in(['pendiente', 'aceptada', 'rechazada'])]]);

        return response()->json(['data' => $this->justificaciones->bandeja($request->user(), $d['estado'] ?? null)]);
    }

    public function resolver(Request $request, int $justificacion): JsonResponse
    {
        $d = $request->validate(['decision' => ['required', Rule::in(['aceptar', 'rechazar'])], 'respuesta' => ['nullable', 'string', 'max:300']]);

        return response()->json($this->justificaciones->resolver($justificacion, $d['decision'] === 'aceptar', $d['respuesta'] ?? null, $request->user()));
    }

    public function sustento(Request $request, int $justificacion): JsonResponse
    {
        return response()->json(['url' => $this->justificaciones->urlSustento($justificacion, $request->user(), FamiliaController::alumnosDe((int) $request->user()->id))]);
    }
}
