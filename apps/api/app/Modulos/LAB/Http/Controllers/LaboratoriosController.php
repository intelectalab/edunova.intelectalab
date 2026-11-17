<?php

namespace App\Modulos\LAB\Http\Controllers;

use App\Modulos\LAB\Services\Laboratorios;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** S35 · Laboratorios y talleres (EDUN-191 a 194). */
class LaboratoriosController
{
    public function __construct(private Laboratorios $lab) {}

    public function espacios(): JsonResponse
    {
        return response()->json(['data' => $this->lab->espacios(),
            'secciones' => DB::table('secciones as s')->join('grados as g', 'g.id', '=', 's.grado_id')->join('anios_escolares as y', 'y.id', '=', 's.anio_escolar_id')->where('y.estado', 'abierto')
                ->orderBy('g.nivel')->orderBy('g.numero')->orderBy('s.nombre')->get(['s.id', DB::raw("g.nombre || ' ' || s.nombre as nombre")]),
            'personal' => DB::table('personal')->where('estado', 'activo')->orderBy('apellidos')->get(['id', DB::raw("apellidos || ', ' || nombres as nombre")])]);
    }

    public function guardarEspacio(Request $request, ?int $espacio = null): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'min:2', 'max:80'], 'tipo' => ['required', Rule::in(['laboratorio', 'computo', 'taller', 'otro'])], 'capacidad' => ['nullable', 'integer', 'min:1'], 'activo' => ['boolean']]);

        return response()->json($this->lab->guardarEspacio($espacio, $d), $espacio ? 200 : 201);
    }

    public function reservas(Request $request): JsonResponse
    {
        $d = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d'], 'espacio_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->lab->reservas($d['desde'] ?? now()->startOfWeek()->toDateString(), $d['hasta'] ?? now()->endOfWeek()->toDateString(), $d['espacio_id'] ?? null)]);
    }

    public function reservar(Request $request): JsonResponse
    {
        $d = $request->validate(['lab_espacio_id' => ['required', 'integer'], 'fecha' => ['required', 'date_format:Y-m-d'], 'hora_inicio' => ['required', 'date_format:H:i'], 'hora_fin' => ['required', 'date_format:H:i'],
            'seccion_id' => ['nullable', 'integer'], 'materiales' => ['nullable', 'string', 'max:1000']]);

        return response()->json($this->lab->reservar($d, $request->user()), 201);
    }

    public function moverReserva(Request $request, int $reserva, Autorizacion $permisos): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['realizada', 'cancelada'])]]);
        $this->lab->moverReserva($reserva, $d['estado'], $request->user(), $permisos->tiene($request->user(), 'lab.reservas.gestionar'));

        return response()->json(['ok' => true]);
    }

    public function equipos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->lab->equipos($request->validate(['estado' => ['nullable', Rule::in(['disponible', 'prestado', 'reparacion', 'baja'])]])['estado'] ?? null)]);
    }

    public function guardarEquipo(Request $request, ?int $equipo = null): JsonResponse
    {
        $d = $request->validate(['codigo' => ['required', 'string', 'max:30'], 'tipo' => ['required', 'string', 'max:40'], 'descripcion' => ['nullable', 'string', 'max:200'], 'estado' => ['nullable', Rule::in(['disponible', 'reparacion', 'baja'])]]);

        return response()->json($this->lab->guardarEquipo($equipo, $d), $equipo ? 200 : 201);
    }

    public function prestar(Request $request): JsonResponse
    {
        $d = $request->validate(['codigo' => ['required', 'string', 'max:30'], 'personal_id' => ['required', 'integer'], 'seccion_id' => ['nullable', 'integer'], 'hasta' => ['required', 'date']]);

        return response()->json($this->lab->prestar($d['codigo'], (int) $d['personal_id'], $d['seccion_id'] ?? null, $d['hasta'], (int) $request->user()->id), 201);
    }

    public function devolver(Request $request): JsonResponse
    {
        $d = $request->validate(['codigo' => ['required', 'string', 'max:30'], 'resultado' => ['required', Rule::in(['bueno', 'danado'])], 'observacion' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->lab->devolver($d['codigo'], $d['resultado'], $d['observacion'] ?? null, (int) $request->user()->id));
    }

    public function materiales(): JsonResponse
    {
        return response()->json(['data' => $this->lab->materiales()]);
    }

    public function guardarMaterial(Request $request, ?int $material = null): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'min:2', 'max:150'], 'unidad' => ['required', 'string', 'max:20'], 'stock' => ['required', 'numeric', 'min:0'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'], 'vence' => ['nullable', 'date_format:Y-m-d'], 'ubicacion' => ['nullable', 'string', 'max:60'],
            'peligroso' => ['boolean'], 'con_llave' => ['boolean'], 'hoja_seguridad' => ['nullable', 'file', 'mimes:pdf', 'max:10240']]);
        unset($d['hoja_seguridad']);

        return response()->json($this->lab->guardarMaterial($material, $d, $request->file('hoja_seguridad'), (int) $request->user()->id), $material ? 200 : 201);
    }

    public function entregarMaterial(Request $request, int $material): JsonResponse
    {
        return response()->json($this->lab->entregarMaterial($material, (float) $request->validate(['cantidad' => ['required', 'numeric', 'gt:0']])['cantidad'], (int) $request->user()->id));
    }

    public function hoja(int $material): JsonResponse
    {
        return response()->json(['url' => $this->lab->urlHoja($material)]);
    }

    public function incidencias(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->lab->incidencias($request->validate(['estado' => ['nullable', Rule::in(['abierta', 'en_proceso', 'cerrada'])]])['estado'] ?? null)]);
    }

    public function reportar(Request $request): JsonResponse
    {
        $d = $request->validate(['detalle' => ['required', 'string', 'min:5', 'max:2000'], 'equipo' => ['nullable', 'string', 'max:30']]);

        return response()->json($this->lab->reportar($d['detalle'], $d['equipo'] ?? null, $request->user()), 201);
    }

    public function atender(Request $request, int $incidencia): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['en_proceso', 'cerrada'])], 'respuesta' => ['nullable', 'string', 'max:2000']]);

        return response()->json($this->lab->atender($incidencia, $d['estado'], $d['respuesta'] ?? null, $request->user()));
    }
}
