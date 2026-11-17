<?php

namespace App\Modulos\MOV\Http\Controllers;

use App\Modulos\MOV\Services\AppPersonal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** EDUN-199 · App del personal: movilidad (recorrido y ubicación) y enfermería (atención rápida). */
class AppPersonalController
{
    public function __construct(private AppPersonal $app) {}

    public function rutas(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->app->misRutas($request->user())]);
    }

    public function iniciar(Request $request, int $ruta): JsonResponse
    {
        $d = $request->validate(['sentido' => ['required', Rule::in(['ida', 'vuelta'])]]);

        return response()->json(['recorrido_id' => $this->app->iniciar($request->user(), $ruta, $d['sentido'])], 201);
    }

    public function posiciones(Request $request, int $recorrido): JsonResponse
    {
        $d = $request->validate(['posiciones' => ['required', 'array', 'min:1', 'max:100'], 'posiciones.*.latitud' => ['required', 'numeric', 'between:-90,90'],
            'posiciones.*.longitud' => ['required', 'numeric', 'between:-180,180'], 'posiciones.*.velocidad_kmh' => ['nullable', 'numeric'], 'posiciones.*.precision_m' => ['nullable', 'numeric'],
            'posiciones.*.registrado_at' => ['nullable', 'date']]);

        return response()->json(['guardadas' => $this->app->posiciones($request->user(), $recorrido, $d['posiciones'])]);
    }

    public function evento(Request $request, int $recorrido): JsonResponse
    {
        $d = $request->validate(['asignacion_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(['subio', 'bajo', 'no_subio'])], 'latitud' => ['nullable', 'numeric'], 'longitud' => ['nullable', 'numeric']]);
        $this->app->evento($request->user(), $recorrido, (int) $d['asignacion_id'], $d['tipo'], $d['latitud'] ?? null, $d['longitud'] ?? null);

        return response()->json(['message' => 'Registrado.'], 201);
    }

    /** EDUN-163 · Marcas de subida y bajada guardadas sin conexión. */
    public function eventosLote(Request $request): JsonResponse
    {
        $d = $request->validate([
            'eventos' => ['required', 'array', 'min:1', 'max:100'], 'eventos.*.id' => ['required', 'string', 'max:40', 'distinct'],
            'eventos.*.recorrido_id' => ['required', 'integer'], 'eventos.*.asignacion_id' => ['required', 'integer'],
            'eventos.*.tipo' => ['required', Rule::in(['subio', 'bajo', 'no_subio'])], 'eventos.*.ocurrido_at' => ['required', 'date'],
            'eventos.*.latitud' => ['nullable', 'numeric', 'between:-90,90'], 'eventos.*.longitud' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json($this->app->eventosLote($request->user(), $d['eventos']));
    }

    public function finalizar(Request $request, int $recorrido): JsonResponse
    {
        $d = $request->validate(['finalizado_at' => ['nullable', 'date']]);
        $this->app->finalizar($request->user(), $recorrido, isset($d['finalizado_at']) ? Carbon::parse($d['finalizado_at']) : null);

        return response()->json(['message' => 'Recorrido finalizado.']);
    }

    public function buscarAlumno(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']])['q'];

        return response()->json(['data' => $this->app->buscarAlumno($q)]);
    }

    public function atender(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'motivo' => ['required', 'string', 'max:200'], 'accion' => ['required', 'string', 'max:2000'],
            'derivado' => ['boolean'], 'retiro_documento' => ['nullable', 'string', 'max:15'], 'avisar_padre' => ['boolean']],
            ['motivo.required' => 'Indica el motivo de la atención.', 'accion.required' => 'Indica qué se hizo.']);

        return response()->json(['id' => $this->app->atender((int) $d['alumno_id'], $d['motivo'], $d['accion'], (bool) ($d['derivado'] ?? false), $d['retiro_documento'] ?? null,
            (bool) ($d['avisar_padre'] ?? true), (int) $request->user()->id)], 201);
    }

    /** EDUN-216 · Atenciones guardadas sin señal (alumno por carné o DNI, con su hora real). */
    public function atencionesLote(Request $request): JsonResponse
    {
        $d = $request->validate(['atenciones' => ['required', 'array', 'min:1', 'max:50'], 'atenciones.*.id' => ['required', 'string', 'max:60'], 'atenciones.*.codigo' => ['required', 'string', 'max:30'],
            'atenciones.*.motivo' => ['required', 'string', 'max:200'], 'atenciones.*.accion' => ['required', 'string', 'max:2000'], 'atenciones.*.atendido_at' => ['required', 'date']]);

        return response()->json($this->app->atencionesLote($d['atenciones'], (int) $request->user()->id));
    }
}
