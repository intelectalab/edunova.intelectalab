<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\CargaDocente;
use App\Modulos\ADM\Services\Horarios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** EDUN-48 / EDUN-49 · ADM-05 Carga docente y horario semanal sin cruces. */
class HorariosController
{
    public function __construct(private CargaDocente $cargas, private Horarios $horarios) {}

    public function cargas(Request $request): JsonResponse
    {
        return response()->json($this->cargas->listar($this->anio($request)));
    }

    public function asignarCarga(Request $request): JsonResponse
    {
        $d = $request->validate([
            'seccion_id' => ['required', 'integer'],
            'area_curricular_id' => ['required', 'integer'],
            'personal_id' => ['nullable', 'integer'],
            'horas_semanales' => ['nullable', 'integer', 'between:1,20'],
        ]);

        return response()->json($this->cargas->asignar((int) $d['seccion_id'], (int) $d['area_curricular_id'], $d['personal_id'] ?? null, $d['horas_semanales'] ?? null));
    }

    public function bloques(): JsonResponse
    {
        return response()->json(['data' => $this->horarios->bloques(), 'sugeridos' => Horarios::SUGERIDOS]);
    }

    public function guardarBloques(Request $request): JsonResponse
    {
        $d = $request->validate([
            'turno' => ['required', Rule::in(array_keys(Horarios::TURNOS))],
            'bloques' => ['required', 'array', 'min:1', 'max:16'],
            'bloques.*.hora_inicio' => ['required', 'date_format:H:i'],
            'bloques.*.hora_fin' => ['required', 'date_format:H:i'],
            'bloques.*.es_recreo' => ['nullable', 'boolean'],
        ], ['bloques.*.hora_inicio.date_format' => 'Usa horas como 08:00.', 'bloques.*.hora_fin.date_format' => 'Usa horas como 08:45.']);

        return response()->json(['data' => $this->horarios->guardarBloques($d['turno'], $d['bloques'])]);
    }

    public function ver(Request $request): JsonResponse
    {
        [$anio, $seccion, $personal] = $this->vista($request);

        return response()->json($this->horarios->ver($anio, $seccion, $personal));
    }

    public function asignarCelda(Request $request): JsonResponse
    {
        $d = $request->validate([
            'seccion_id' => ['required', 'integer'],
            'dia_semana' => ['required', 'integer', 'between:1,6'],
            'bloque_horario_id' => ['required', 'integer'],
            'carga_docente_id' => ['nullable', 'integer'],
            'ambiente_id' => ['nullable', 'integer'],
        ]);
        $this->horarios->asignar((int) $d['seccion_id'], (int) $d['dia_semana'], (int) $d['bloque_horario_id'], $d['carga_docente_id'] ?? null, $d['ambiente_id'] ?? null);

        return response()->json(['message' => 'Horario actualizado.']);
    }

    public function pdf(Request $request): Response
    {
        [$anio, $seccion, $personal] = $this->vista($request);

        return response($this->horarios->pdf($anio, $seccion, $personal), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="horario.pdf"',
        ]);
    }

    /** @return array{0: int, 1: int|null, 2: int|null} */
    private function vista(Request $request): array
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer'],
            'seccion_id' => ['nullable', 'required_without:personal_id', 'integer'],
            'personal_id' => ['nullable', 'integer'],
        ], ['seccion_id.required_without' => 'Elige una sección o un docente.']);

        return [(int) $d['anio_escolar_id'], isset($d['seccion_id']) ? (int) $d['seccion_id'] : null, isset($d['personal_id']) ? (int) $d['personal_id'] : null];
    }

    private function anio(Request $request): int
    {
        return (int) $request->validate(['anio_escolar_id' => ['required', 'integer']])['anio_escolar_id'];
    }
}
