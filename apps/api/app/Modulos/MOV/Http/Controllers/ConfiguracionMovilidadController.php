<?php

namespace App\Modulos\MOV\Http\Controllers;

use App\Modulos\MOV\Services\ConfiguracionMovilidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-161 / EDUN-162 · Configuración de la movilidad escolar en la web de gestión. */
class ConfiguracionMovilidadController
{
    public function __construct(private ConfiguracionMovilidad $config) {}

    public function resumen(): JsonResponse
    {
        return response()->json($this->config->resumen());
    }

    public function guardarUnidad(Request $request, ?int $unidad = null): JsonResponse
    {
        $d = $request->validate([
            'placa' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\- ]{5,10}$/'], 'capacidad' => ['required', 'integer', 'between:1,80'],
            'soat_vence' => ['nullable', 'date'], 'autorizacion_vence' => ['nullable', 'date'], 'revision_tecnica_vence' => ['nullable', 'date'],
            'propia' => ['boolean'], 'empresa' => ['nullable', 'string', 'max:150'],
        ], ['placa.regex' => 'Escribe la placa como figura en la tarjeta (ej.: ABC-123).']);

        return response()->json(['id' => $this->config->guardarUnidad($unidad, $d)], $unidad ? 200 : 201);
    }

    public function eliminarUnidad(int $unidad): JsonResponse
    {
        $this->config->eliminarUnidad($unidad);

        return response()->json(['message' => 'Unidad eliminada.']);
    }

    public function guardarPersonal(Request $request, ?int $persona = null): JsonResponse
    {
        $d = $request->validate([
            'nombres' => ['required', 'string', 'max:200'], 'numero_documento' => ['required', 'string', 'max:15'], 'rol' => ['required', Rule::in(['conductor', 'auxiliar'])],
            'licencia' => ['nullable', 'string', 'max:20'], 'licencia_vence' => ['nullable', 'date'], 'user_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['id' => $this->config->guardarPersonal($persona, $d)], $persona ? 200 : 201);
    }

    public function guardarRuta(Request $request, ?int $ruta = null): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80'], 'turno' => ['required', Rule::in(ConfiguracionMovilidad::TURNOS)],
            'mov_unidad_id' => ['nullable', 'integer'], 'conductor_id' => ['nullable', 'integer'], 'auxiliar_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['id' => $this->config->guardarRuta($ruta, $d)], $ruta ? 200 : 201);
    }

    public function guardarParaderos(Request $request, int $ruta): JsonResponse
    {
        $d = $request->validate([
            'paraderos' => ['present', 'array', 'max:60'], 'paraderos.*.id' => ['nullable', 'integer'], 'paraderos.*.nombre' => ['required', 'string', 'max:150'],
            'paraderos.*.hora_estimada' => ['nullable', 'date_format:H:i'], 'paraderos.*.latitud' => ['nullable', 'numeric', 'between:-90,90'], 'paraderos.*.longitud' => ['nullable', 'numeric', 'between:-180,180'],
        ], ['paraderos.*.nombre.required' => 'Cada paradero necesita un nombre.']);

        return response()->json(['data' => $this->config->guardarParaderos($ruta, $d['paraderos'])]);
    }

    public function alumnos(int $ruta): JsonResponse
    {
        return response()->json(['data' => $this->config->alumnosDeRuta($ruta)]);
    }

    public function guardarAlumnos(Request $request, int $ruta): JsonResponse
    {
        $d = $request->validate(['asignaciones' => ['present', 'array', 'max:80'], 'asignaciones.*.alumno_id' => ['required', 'integer'], 'asignaciones.*.paradero_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->config->guardarAlumnos($ruta, $d['asignaciones'])]);
    }

    public function buscarAlumnos(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']])['q'];

        return response()->json(['data' => $this->config->buscarAlumnos($q)]);
    }
}
