<?php

namespace App\Modulos\MDL\Http\Controllers;

use App\Modulos\MDL\Services\ConectorMoodle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * S30 · Moodle conectado (EDUN-143 a 149): prueba, pausa, ingreso único, sincronización,
 * bitácora, notas por aprobar, tabla de conversión e imagen del colegio.
 */
class MoodleController
{
    public function __construct(private ConectorMoodle $moodle) {}

    public function probar(): JsonResponse
    {
        return response()->json($this->moodle->probar());
    }

    public function pausar(Request $request): JsonResponse
    {
        $this->moodle->pausar($request->validate(['pausado' => ['required', 'boolean']])['pausado']);

        return response()->json(['ok' => true]);
    }

    public function secreto(): JsonResponse
    {
        return response()->json(['secreto' => $this->moodle->regenerarSecreto()]);
    }

    public function sso(Request $request): JsonResponse
    {
        $d = $request->validate(['curso' => ['nullable', 'integer']]);

        return response()->json(['url' => $this->moodle->enlaceSso($request->user(), $d['curso'] ?? null)]);
    }

    public function resumen(): JsonResponse
    {
        return response()->json($this->moodle->resumen());
    }

    public function sincronizar(Request $request): JsonResponse
    {
        $d = $request->validate(['tipo' => ['required', Rule::in(['todo', 'cursos', 'usuarios', 'notas'])]]);

        return response()->json(match ($d['tipo']) {
            'cursos' => ['preparados' => $this->moodle->prepararCursos(), 'cursos' => $this->moodle->crearCursos()],
            'usuarios' => ['usuarios' => $this->moodle->sincronizarUsuarios()],
            'notas' => ['notas' => $this->moodle->traerNotas()],
            default => $this->moodle->sincronizarTodo(),
        });
    }

    public function bitacora(): JsonResponse
    {
        return response()->json(['data' => $this->moodle->bitacora()]);
    }

    public function notas(Request $request): JsonResponse
    {
        $d = $request->validate(['estado' => ['nullable', Rule::in(['pendiente', 'aprobada', 'descartada'])]]);

        return response()->json([
            'data' => $this->moodle->notasPendientes($request->user(), $d['estado'] ?? 'pendiente'),
            'periodos' => DB::table('periodos as p')->join('anios_escolares as y', 'y.id', '=', 'p.anio_escolar_id')->where('y.estado', 'abierto')->orderBy('p.numero')->get(['p.id', 'p.nombre', 'p.estado']),
        ]);
    }

    public function resolverNotas(Request $request): JsonResponse
    {
        $d = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer'], 'decision' => ['required', Rule::in(['aprobar', 'descartar'])],
            'competencia_id' => ['nullable', 'integer'], 'periodo_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->moodle->resolverNotas(array_map('intval', $d['ids']), $d['decision'] === 'aprobar', $d['competencia_id'] ?? null, $d['periodo_id'] ?? null, $request->user()));
    }

    public function competencias(Request $request): JsonResponse
    {
        $d = $request->validate(['area_curricular_id' => ['required', 'integer']]);

        return response()->json(['data' => DB::table('competencias')->where('area_curricular_id', $d['area_curricular_id'])->where('vigente', true)->orderBy('orden')->get(['id', 'nombre'])]);
    }

    public function conversion(Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            $d = $request->validate(['rangos' => ['required', 'array', 'min:2', 'max:4'], 'rangos.*.desde' => ['required', 'numeric', 'between:0,100'], 'rangos.*.valor' => ['required', 'string']]);
            $this->moodle->guardarConversion($d['rangos']);
        }
        $tabla = DB::table('moodle_tablas_conversion')->where('predeterminada', true)->value('rangos');

        return response()->json(['rangos' => $tabla ? json_decode($tabla, true) : ConectorMoodle::CONVERSION]);
    }

    public function imagen(): JsonResponse
    {
        return response()->json($this->moodle->imagen());
    }
}
