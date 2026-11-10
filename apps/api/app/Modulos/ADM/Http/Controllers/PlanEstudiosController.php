<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\PlanEstudios;
use App\Modulos\ADM\Services\Secciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-45 / EDUN-46 · ADM-03: plan de estudios por grado, secciones del año y aulas. */
class PlanEstudiosController
{
    public function __construct(private PlanEstudios $plan, private Secciones $secciones) {}

    public function show(Request $request): JsonResponse
    {
        $anio = $this->anio($request);

        return response()->json(['grados' => $this->plan->plan($anio), 'catalogo' => $this->plan->catalogo()]);
    }

    public function generarGrados(): JsonResponse
    {
        return response()->json(['creados' => $this->plan->generarGrados()]);
    }

    public function guardarGrado(Request $request, int $grado): JsonResponse
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer'],
            'escala' => ['required', Rule::in(['literal', 'vigesimal'])],
            'areas' => ['present', 'array', 'max:30'],
            'areas.*.area_curricular_id' => ['required', 'integer'],
            'areas.*.horas_semanales' => ['nullable', 'integer', 'between:1,20'],
        ], ['escala.required' => 'Elige la escala de calificación.', 'areas.*.horas_semanales.between' => 'Las horas por semana van de 1 a 20.']);
        $this->plan->guardarGrado($grado, (int) $d['anio_escolar_id'], $d['escala'], $d['areas']);

        return response()->json(['grados' => $this->plan->plan((int) $d['anio_escolar_id'])]);
    }

    public function copiar(Request $request): JsonResponse
    {
        $d = $this->copia($request);

        return response()->json($this->plan->copiar($d['desde_anio_id'], $d['hacia_anio_id']));
    }

    public function secciones(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->secciones->listar($this->anio($request))] + $this->secciones->opciones());
    }

    public function guardarSeccion(Request $request, ?int $seccion = null): JsonResponse
    {
        $d = $request->validate([
            'anio_escolar_id' => ['required', 'integer'],
            'grado_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:10', 'regex:/^[A-Za-zÑñ0-9 -]+$/u'],
            'turno' => ['required', Rule::in(['manana', 'tarde', 'noche'])],
            'ambiente_id' => ['nullable', 'integer'],
            'tutor_personal_id' => ['nullable', 'integer'],
            'vacantes' => ['nullable', 'integer', 'between:1,80'],
        ], ['grado_id.required' => 'Elige el grado.', 'nombre.required' => 'Escribe el nombre de la sección (A, B, Única…).', 'vacantes.between' => 'Las vacantes van de 1 a 80.']);
        $id = $this->secciones->guardar($d, $seccion);

        return response()->json(['id' => $id], $seccion ? 200 : 201);
    }

    public function eliminarSeccion(int $seccion): JsonResponse
    {
        $this->secciones->eliminar($seccion);

        return response()->json(['message' => 'Sección eliminada.']);
    }

    public function copiarSecciones(Request $request): JsonResponse
    {
        $d = $this->copia($request);

        return response()->json($this->secciones->copiar($d['desde_anio_id'], $d['hacia_anio_id']));
    }

    public function crearAmbiente(Request $request): JsonResponse
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:60'],
            'tipo' => ['nullable', Rule::in(['aula', 'laboratorio', 'taller', 'computo', 'losa', 'auditorio', 'otro'])],
            'capacidad' => ['nullable', 'integer', 'between:1,500'],
        ], ['nombre.required' => 'Escribe el nombre del aula.']);

        return response()->json($this->secciones->crearAmbiente($d['nombre'], $d['tipo'] ?? 'aula', $d['capacidad'] ?? null), 201);
    }

    private function anio(Request $request): int
    {
        return (int) $request->validate(['anio_escolar_id' => ['required', 'integer']], ['anio_escolar_id.required' => 'Elige el año escolar.'])['anio_escolar_id'];
    }

    /** @return array{desde_anio_id: int, hacia_anio_id: int} */
    private function copia(Request $request): array
    {
        $d = $request->validate(['desde_anio_id' => ['required', 'integer'], 'hacia_anio_id' => ['required', 'integer']]);

        return ['desde_anio_id' => (int) $d['desde_anio_id'], 'hacia_anio_id' => (int) $d['hacia_anio_id']];
    }
}
