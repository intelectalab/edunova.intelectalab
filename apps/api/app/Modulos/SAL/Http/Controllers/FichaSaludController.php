<?php

namespace App\Modulos\SAL\Http\Controllers;

use App\Modulos\MOV\Services\SeguimientoMovilidad;
use App\Modulos\SAL\Services\Salud;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S31 · Ficha de salud y campañas (EDUN-177, 178) y seguimiento de la movilidad (EDUN-166, 167).
 */
class FichaSaludController
{
    public function __construct(private Salud $salud) {}

    private function datosFicha(Request $request): array
    {
        return $request->validate([
            'grupo_sanguineo' => ['nullable', 'string', 'max:5'], 'seguro' => ['nullable', 'string', 'max:40'],
            'alergias' => ['nullable', 'string', 'max:2000'], 'condiciones' => ['nullable', 'string', 'max:2000'], 'medicacion' => ['nullable', 'string', 'max:2000'],
            'contacto_emergencia' => ['nullable', 'string', 'max:200'], 'telefono_emergencia' => ['nullable', 'string', 'max:15'],
        ]);
    }

    public function fichas(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->salud->fichas($request->validate(['buscar' => ['nullable', 'string', 'max:80']])['buscar'] ?? null)]);
    }

    public function ficha(Request $request, int $alumno): JsonResponse
    {
        return response()->json($this->salud->ficha($alumno, $request->user(), 'enfermeria'));
    }

    public function guardarFicha(Request $request, int $alumno): JsonResponse
    {
        return response()->json($this->salud->guardarFicha($alumno, $this->datosFicha($request), $request->user(), 'enfermeria'));
    }

    public function atenciones(Request $request): JsonResponse
    {
        $d = $request->validate(['fecha' => ['nullable', 'date_format:Y-m-d'], 'alumno_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->salud->atenciones($d['fecha'] ?? null, isset($d['alumno_id']) ? (int) $d['alumno_id'] : null)]);
    }

    public function campanas(): JsonResponse
    {
        return response()->json(['data' => $this->salud->campanas(),
            'secciones' => DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')->where('y.estado', 'abierto')
                ->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->get(['s.id', DB::raw("g.nombre || ' ' || s.nombre as nombre")])]);
    }

    public function crearCampana(Request $request): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'min:3', 'max:150'], 'fecha' => ['required', 'date_format:Y-m-d'], 'descripcion' => ['required', 'string', 'min:10', 'max:3000'],
            'secciones' => ['required', 'array', 'min:1'], 'secciones.*' => ['integer']]);

        return response()->json($this->salud->crearCampana($d['nombre'], $d['fecha'], $d['descripcion'], $d['secciones'], $request->user()), 201);
    }

    public function autorizados(int $campana): JsonResponse
    {
        return response()->json(['data' => $this->salud->autorizados($campana)]);
    }

    // ─── Familia

    public function fichaFamilia(Request $request, int $alumno): JsonResponse
    {
        abort_unless(in_array($alumno, FamiliaController::alumnosDe((int) $request->user()->id), true), 403, 'Solo la ficha de tus hijos.');

        return response()->json($this->salud->ficha($alumno, $request->user(), 'familia'));
    }

    public function guardarFichaFamilia(Request $request, int $alumno): JsonResponse
    {
        // Solo el apoderado actualiza la ficha (el alumno no puede borrar sus alergias o su medicación).
        abort_unless(in_array($alumno, FamiliaController::hijosDeApoderado((int) $request->user()->id), true), 403, 'Solo el apoderado actualiza la ficha de salud.');

        return response()->json($this->salud->guardarFicha($alumno, $this->datosFicha($request), $request->user(), 'familia'));
    }

    // ─── Movilidad

    public function movilidadFamilia(Request $request, SeguimientoMovilidad $seguimiento): JsonResponse
    {
        return response()->json(['data' => $seguimiento->familia($request->user())]);
    }

    public function monitor(SeguimientoMovilidad $seguimiento): JsonResponse
    {
        return response()->json(['data' => $seguimiento->monitor()]);
    }
}
