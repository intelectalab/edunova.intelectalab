<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\AniosEscolares;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-44 · ADM-02 Año escolar y periodos. */
class AniosEscolaresController
{
    private const MENSAJES = [
        'anio.required' => 'Escribe el año.',
        'tipo_periodo.required' => 'Elige bimestres o trimestres.',
        'fecha_inicio.required' => 'Elige la fecha de inicio.',
        'fecha_fin.required' => 'Elige la fecha de fin.',
        '*.date_format' => 'La fecha no es válida.',
        'periodos.*.nombre.required' => 'Cada periodo necesita un nombre.',
        'feriados.*.motivo.required' => 'Escribe el motivo de cada feriado.',
    ];

    public function __construct(private AniosEscolares $anios) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->anios->listar()]);
    }

    public function show(int $anio): JsonResponse
    {
        return response()->json($this->anios->detalle($anio));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'between:2020,2100'],
            'tipo_periodo' => ['required', Rule::in(['bimestre', 'trimestre'])],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d'],
            'importar_feriados' => ['nullable', 'boolean'],
        ], self::MENSAJES);

        return response()->json($this->anios->crear($datos), 201);
    }

    public function update(Request $request, int $anio): JsonResponse
    {
        $datos = $request->validate([
            'tipo_periodo' => ['nullable', Rule::in(['bimestre', 'trimestre'])],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['required', 'date_format:Y-m-d'],
            'periodos' => ['nullable', 'array', 'max:4'],
            'periodos.*.numero' => ['required', 'integer', 'between:1,4'],
            'periodos.*.nombre' => ['required', 'string', 'max:40'],
            'periodos.*.fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'periodos.*.fecha_fin' => ['required', 'date_format:Y-m-d'],
            'periodos.*.fecha_limite_notas' => ['nullable', 'date_format:Y-m-d'],
            'feriados' => ['nullable', 'array', 'max:60'],
            'feriados.*.fecha' => ['required', 'date_format:Y-m-d'],
            'feriados.*.motivo' => ['required', 'string', 'max:120'],
        ], self::MENSAJES);

        return response()->json($this->anios->actualizar($anio, $datos));
    }
}
