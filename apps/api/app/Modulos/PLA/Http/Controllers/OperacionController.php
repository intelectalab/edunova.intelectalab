<?php

namespace App\Modulos\PLA\Http\Controllers;

use App\Modulos\PLA\Jobs\GenerarExportacion;
use App\Modulos\PLA\Services\ChecklistImplementacion;
use App\Modulos\PLA\Services\CobroMensual;
use App\Modulos\PLA\Services\ExportacionColegio;
use App\Modulos\PLA\Services\Suspensiones;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operación del SaaS en la consola: parámetros legales (EDUN-30), suspensión por falta de pago
 * (EDUN-28), exportación completa (EDUN-34) y lista de implementación con activación (EDUN-212).
 */
class OperacionController
{
    private function db()
    {
        return DB::connection('plataforma');
    }

    public function parametros(): JsonResponse
    {
        $filas = $this->db()->table('parametros_legales')->orderBy('codigo')->orderByDesc('vigente_desde')->get();
        $hoy = now()->toDateString();
        // El valor vigente de cada código: el más reciente que ya empezó a regir.
        $vigentes = $filas->filter(fn ($p) => $p->vigente_desde <= $hoy)->groupBy('codigo')->map->first()->pluck('id')->all();

        return response()->json([
            'data' => $filas->map(fn ($p) => (array) $p + ['vigente' => in_array($p->id, $vigentes, true)])->values(),
            'sin_confirmar' => $filas->whereIn('id', $vigentes)->where('confirmado', false)->count(),
        ]);
    }

    public function guardarParametro(Request $request, Auditoria $auditoria): JsonResponse
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_.]+$/'],
            'valor' => ['required', 'string', 'max:200'],
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'fuente' => ['required', 'string', 'max:250'],
            'confirmado' => ['nullable', 'boolean'],
        ], ['fuente.required' => 'Indica la norma o fuente del valor.', 'codigo.regex' => 'El código va en minúsculas con puntos o guiones bajos.']);
        $this->db()->table('parametros_legales')->upsert(
            [['codigo' => $d['codigo'], 'valor' => $d['valor'], 'vigente_desde' => $d['vigente_desde'], 'fuente' => $d['fuente'], 'confirmado' => (bool) ($d['confirmado'] ?? false)]],
            ['codigo', 'vigente_desde'], ['valor', 'fuente', 'confirmado'],
        );

        return $this->parametros();
    }

    public function confirmarParametro(Request $request, int $parametro): JsonResponse
    {
        $d = $request->validate(['confirmado' => ['required', 'boolean']]);
        $n = $this->db()->table('parametros_legales')->where('id', $parametro)->update(['confirmado' => (bool) $d['confirmado']]);
        abort_if($n === 0, 404);

        return $this->parametros();
    }

    public function programarSuspension(Request $request, int $institucion, Suspensiones $s): JsonResponse
    {
        $d = $request->validate(['fecha' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:250']], ['motivo.required' => 'Indica el motivo (p. ej. facturas de agosto y setiembre impagas).']);
        $s->programar($institucion, $d['fecha'], $d['motivo']);

        return response()->json(['message' => 'Suspensión programada. El promotor recibirá avisos 7 y 3 días antes.']);
    }

    public function cancelarSuspension(int $institucion, Suspensiones $s): JsonResponse
    {
        $s->cancelar($institucion);

        return response()->json(['message' => 'Suspensión cancelada.']);
    }

    public function cambiarEstado(Request $request, int $institucion, Suspensiones $s, ChecklistImplementacion $checklist): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['activo', 'suspendido'])], 'motivo' => ['nullable', 'string', 'max:250']]);
        $actual = $this->db()->table('instituciones')->where('id', $institucion)->value('estado');
        abort_if($actual === null, 404);
        match (true) {
            $d['estado'] === 'suspendido' => $s->suspender($institucion, $d['motivo'] ?? 'Falta de pago'),
            $actual === 'implementacion' => $checklist->activar($institucion),
            default => $s->reactivar($institucion),
        };

        return response()->json(['estado' => $this->db()->table('instituciones')->where('id', $institucion)->value('estado')]);
    }

    public function checklist(int $institucion, ChecklistImplementacion $c): JsonResponse
    {
        return response()->json(['data' => $c->estado($institucion)]);
    }

    public function marcarPaso(Request $request, int $institucion, string $paso, ChecklistImplementacion $c): JsonResponse
    {
        $d = $request->validate(['hecho' => ['required', 'boolean']]);

        return response()->json(['data' => $c->marcar($institucion, $paso, (bool) $d['hecho'])]);
    }

    public function exportaciones(int $institucion): JsonResponse
    {
        return response()->json(['data' => $this->db()->table('exportaciones')->where('institucion_id', $institucion)->orderByDesc('id')->limit(20)
            ->get(['id', 'estado', 'motivo', 'bytes', 'resumen', 'error', 'created_at', 'completado_at'])
            ->map(fn ($e) => array_merge((array) $e, ['resumen' => json_decode((string) $e->resumen, true)]))])->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

    public function exportar(Request $request, int $institucion, ExportacionColegio $exportacion): JsonResponse
    {
        $motivo = $request->validate(['motivo' => ['nullable', Rule::in(['portabilidad', 'baja', 'respaldo'])]])['motivo'] ?? 'portabilidad';
        $id = $exportacion->solicitar($institucion, $motivo, $request->user()->id);
        GenerarExportacion::dispatch($id);

        return response()->json(['id' => $id, 'message' => 'La exportación se está preparando. Aparecerá lista en unos minutos.'], 202);
    }

    public function descargarExportacion(int $institucion, int $exportacion, ExportacionColegio $exp): JsonResponse
    {
        abort_unless($this->db()->table('exportaciones')->where('id', $exportacion)->where('institucion_id', $institucion)->exists(), 404);

        return response()->json($exp->enlace($exportacion));
    }

    /** EDUN-27 · Cálculo del cobro mensual (vista previa) y facturas ya emitidas. */
    public function cobroMensual(Request $request, CobroMensual $cobro): JsonResponse
    {
        $periodo = $request->validate(['periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['periodo'];

        return response()->json(['periodo' => $periodo, 'calculo' => $cobro->calcular($periodo), 'facturas' => $cobro->listar($periodo)]);
    }

    public function emitirCobroMensual(Request $request, CobroMensual $cobro): JsonResponse
    {
        $periodo = $request->validate(['periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['periodo'];

        return response()->json($cobro->emitir($periodo, (int) $request->user()->id) + ['facturas' => $cobro->listar($periodo)]);
    }

    public function marcarFacturaSaas(Request $request, int $factura, CobroMensual $cobro): JsonResponse
    {
        $d = $request->validate(['estado' => ['required', 'in:pagada,anulada,emitida']]);
        $cobro->marcar($factura, $d['estado']);

        return response()->json(['message' => 'Actualizado.']);
    }
}
