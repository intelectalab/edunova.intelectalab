<?php

namespace App\Modulos\COD\Http\Controllers;

use App\Modulos\COD\Services\Comedor;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Services\Autorizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** S32 · Comedor escolar (EDUN-168 a 175). */
class ComedorController
{
    public function __construct(private Comedor $comedor) {}

    public function semana(Request $request): JsonResponse
    {
        $d = $request->validate(['lunes' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->comedor->semana($d['lunes'] ?? now()->toDateString()), 'tiene_nutricionista' => Comedor::tieneNutricionista()]);
    }

    public function guardarMenu(Request $request): JsonResponse
    {
        $d = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'], 'entrada' => ['nullable', 'string', 'max:150'], 'fondo' => ['required', 'string', 'min:3', 'max:150'],
            'bebida' => ['nullable', 'string', 'max:100'], 'postre' => ['nullable', 'string', 'max:100'], 'kcal' => ['nullable', 'integer', 'between:0,3000'],
            'proteinas_g' => ['nullable', 'numeric', 'between:0,500'], 'carbohidratos_g' => ['nullable', 'numeric', 'between:0,1000'], 'grasas_g' => ['nullable', 'numeric', 'between:0,500'],
            'alergenos' => ['nullable', 'string', 'max:200'],
        ], ['fondo.required' => 'Escribe el plato de fondo.']);

        return response()->json($this->comedor->guardarMenu($d));
    }

    public function moverMenu(Request $request, int $menu): JsonResponse
    {
        $d = $request->validate(['accion' => ['required', Rule::in(['proponer', 'aprobar', 'devolver', 'publicar'])], 'observacion' => ['nullable', 'string', 'max:1000'],
            'porciones' => ['nullable', 'array'], 'porciones.*.porcion' => ['nullable', 'string', 'max:60'], 'porciones.*.kcal' => ['nullable', 'integer', 'between:0,3000']]);
        if (in_array($d['accion'], ['aprobar', 'devolver'], true)) {
            abort_unless(app(Autorizacion::class)->tiene($request->user(), 'cod.nutricion.aprobar'), 403);
        }

        return response()->json($this->comedor->moverMenu($menu, $d['accion'], $d['porciones'] ?? null, $d['observacion'] ?? null, $request->user()));
    }

    public function planes(): JsonResponse
    {
        return response()->json(['data' => $this->comedor->planes(),
            'conceptos' => DB::table('conceptos_cobro')->where('activo', true)->whereIn('tipo', ['servicio', 'otro'])->orderBy('nombre')->get(['id', 'nombre', 'monto'])]);
    }

    public function guardarPlan(Request $request, ?int $plan = null): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'min:3', 'max:60'], 'modalidad' => ['required', Rule::in(['mensual', 'diario'])], 'concepto_cobro_id' => ['nullable', 'integer'], 'activo' => ['boolean']]);

        return response()->json($this->comedor->guardarPlan($plan, $d), $plan ? 200 : 201);
    }

    public function inscripciones(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->comedor->inscripciones($request->validate(['buscar' => ['nullable', 'string', 'max:80']])['buscar'] ?? null)]);
    }

    public function inscribir(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'com_plan_id' => ['required', 'integer'], 'desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde']]);

        return response()->json($this->comedor->inscribir((int) $d['alumno_id'], (int) $d['com_plan_id'], $d['desde'], $d['hasta'] ?? null, $request->user()), 201);
    }

    public function terminar(Request $request, int $inscripcion): JsonResponse
    {
        $this->comedor->terminarInscripcion($inscripcion, $request->validate(['hasta' => ['required', 'date_format:Y-m-d']])['hasta']);

        return response()->json(['ok' => true]);
    }

    public function lista(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->comedor->listaDelDia($request->validate(['fecha' => ['nullable', 'date_format:Y-m-d']])['fecha'] ?? null)]);
    }

    public function servir(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['nullable', 'integer', 'required_without:codigo'], 'codigo' => ['nullable', 'string', 'max:30'], 'origen' => ['required', Rule::in(['qr', 'lista', 'manual'])]]);

        return response()->json($this->comedor->servir(isset($d['alumno_id']) ? (int) $d['alumno_id'] : null, $d['codigo'] ?? null, $d['origen'], $request->user()));
    }

    public function restricciones(): JsonResponse
    {
        return response()->json(['data' => $this->comedor->restricciones()]);
    }

    public function registrarRestriccion(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(['alergia', 'intolerancia', 'dieta', 'otra'])], 'detalle' => ['required', 'string', 'min:3', 'max:200'], 'reemplazo' => ['nullable', 'string', 'max:200']]);

        return response()->json($this->comedor->registrarRestriccion((int) $d['alumno_id'], $d['tipo'], $d['detalle'], $d['reemplazo'] ?? null, $request->user()), 201);
    }

    public function retirarRestriccion(int $restriccion): JsonResponse
    {
        $this->comedor->retirarRestriccion($restriccion);

        return response()->json(['ok' => true]);
    }

    public function medir(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'campana_id' => ['required', 'integer'], 'peso_kg' => ['required', 'numeric', 'between:5,200'], 'talla_cm' => ['required', 'numeric', 'between:40,220']]);

        return response()->json($this->comedor->medir((int) $d['alumno_id'], (int) $d['campana_id'], (float) $d['peso_kg'], (float) $d['talla_cm'], $request->user()), 201);
    }

    public function indicadores(): JsonResponse
    {
        return response()->json(['data' => $this->comedor->indicadores()]);
    }

    public function orientar(Request $request, int $alumno): JsonResponse
    {
        return response()->json(['enviados' => $this->comedor->orientar($alumno, $request->validate(['mensaje' => ['required', 'string', 'min:10', 'max:1000']])['mensaje'])]);
    }

    public function familia(Request $request): JsonResponse
    {
        return response()->json($this->comedor->deFamilia(FamiliaController::alumnosDe((int) $request->user()->id)));
    }
}
