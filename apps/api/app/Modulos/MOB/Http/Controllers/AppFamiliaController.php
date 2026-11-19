<?php

namespace App\Modulos\MOB\Http\Controllers;

use App\Modulos\MOB\Services\InicioFamilia;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S36 · Pantallas de la familia en la app: inicio, asistencia del mes, horario y foto del hijo.
 * Propiedad: solo sus hijos (o él mismo, si es alumno). Otro alumno responde 404.
 */
class AppFamiliaController
{
    public function __construct(private InicioFamilia $inicio) {}

    private function propio(Request $request, int $alumno): int
    {
        abort_unless(in_array($alumno, FamiliaController::alumnosDe((int) $request->user()->id), true), 404);

        return $alumno;
    }

    public function inicio(Request $request): JsonResponse
    {
        return response()->json($this->inicio->resumen($request->user()));
    }

    public function asistencia(Request $request): JsonResponse
    {
        $d = $request->validate([
            'alumno_id' => ['required', 'integer'],
            'mes' => ['nullable', 'date_format:Y-m'],
        ]);

        return response()->json($this->inicio->asistenciaMes($this->propio($request, (int) $d['alumno_id']), $d['mes'] ?? now()->format('Y-m')));
    }

    public function notas(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'periodo_id' => ['nullable', 'integer']]);

        return response()->json($this->inicio->notasPeriodo($this->propio($request, (int) $d['alumno_id']), isset($d['periodo_id']) ? (int) $d['periodo_id'] : null));
    }

    public function horario(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer']]);

        return response()->json(['horario' => $this->inicio->horario($this->propio($request, (int) $d['alumno_id']))]);
    }

    public function foto(Request $request, int $alumno): Response
    {
        $foto = $this->inicio->foto($this->propio($request, $alumno));
        abort_if(! $foto, 404);

        return response($foto['contenido'], 200, ['Content-Type' => $foto['mime'], 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
