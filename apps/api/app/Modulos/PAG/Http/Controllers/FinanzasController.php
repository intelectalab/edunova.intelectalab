<?php

namespace App\Modulos\PAG\Http\Controllers;

use App\Modulos\PAG\Services\Convenios;
use App\Modulos\PAG\Services\PagosEnLinea;
use App\Modulos\PAG\Services\Recaudacion;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Sprint 22 · Convenios, recaudación bancaria, conciliación en línea y reportes financieros. */
class FinanzasController
{
    public function __construct(private Convenios $convenios, private Recaudacion $recaudacion) {}

    // ── EDUN-84 / EDUN-85 / EDUN-86 · Convenios

    public function convenios(): JsonResponse
    {
        return response()->json(['data' => $this->convenios->listar()]);
    }

    public function elegibles(int $apoderado): JsonResponse
    {
        return response()->json(['data' => $this->convenios->cargosElegibles($apoderado)]);
    }

    public function simular(Request $request): JsonResponse
    {
        $d = $request->validate(['total' => ['required', 'numeric', 'gt:0'], 'cuotas' => ['required', 'integer'], 'primera' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['cuotas' => Convenios::simular((float) $d['total'], (int) $d['cuotas'], $d['primera'])]);
    }

    public function crear(Request $request): JsonResponse
    {
        $d = $request->validate([
            'apoderado_id' => ['required', 'integer'], 'cargos' => ['required', 'array', 'min:1'], 'cargos.*' => ['integer'],
            'cuotas' => ['required', 'integer', 'between:2,12'], 'primera' => ['required', 'date_format:Y-m-d'], 'observaciones' => ['nullable', 'string', 'max:1000'],
        ], ['cuotas.between' => 'El convenio va de 2 a 12 cuotas.', 'cargos.required' => 'Elige los cargos vencidos.']);
        $id = $this->convenios->crear((int) $d['apoderado_id'], $d['cargos'], (int) $d['cuotas'], $d['primera'], $d['observaciones'] ?? null, (int) $request->user()->id);

        return response()->json($this->convenios->detalle($id), 201);
    }

    public function firmaPapel(Request $request, int $convenio): JsonResponse
    {
        $d = $request->validate(['archivo_id' => ['required', 'integer']], ['archivo_id.required' => 'Adjunta el acta firmada.']);
        abort_unless(DB::table('archivos')->where('id', $d['archivo_id'])->exists(), 422);
        $this->convenios->firmar($convenio, 'papel', (int) $request->user()->id, (string) $request->ip(), (int) $d['archivo_id']);

        return response()->json($this->convenios->detalle($convenio));
    }

    public function aprobar(Request $request, int $convenio): JsonResponse
    {
        $this->convenios->aprobar($convenio, (int) $request->user()->id);

        return response()->json($this->convenios->detalle($convenio));
    }

    public function anular(Request $request, int $convenio): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']]);
        $this->convenios->anular($convenio, $d['motivo']);

        return response()->json($this->convenios->detalle($convenio));
    }

    public function acta(int $convenio, AlmacenArchivos $almacen): JsonResponse
    {
        $id = DB::table('convenios_fraccionamiento')->where('id', $convenio)->value('acta_archivo_id');
        abort_if(! $id, 404);

        return response()->json(['url' => $almacen->urlTemporal(Archivo::query()->findOrFail($id))]);
    }

    // ── EDUN-87 · La familia

    public function misConvenios(Request $request): JsonResponse
    {
        $apoderados = $this->convenios->familiaDe((int) $request->user()->id);

        return response()->json(['data' => $apoderados->flatMap(fn ($a) => $this->convenios->listar((int) $a))->values()]);
    }

    private function convenioPropio(Request $request, int $convenio): object
    {
        $c = DB::table('convenios_fraccionamiento')->find($convenio);
        abort_unless($c && $this->convenios->familiaDe((int) $request->user()->id)->contains($c->apoderado_id), 404);

        return $c;
    }

    public function firmarApp(Request $request, int $convenio): JsonResponse
    {
        $this->convenioPropio($request, $convenio);
        $request->validate(['acepto' => ['accepted']], ['acepto.accepted' => 'Confirma que leíste y aceptas el acta del convenio.']);
        $this->convenios->firmar($convenio, 'app', (int) $request->user()->id, (string) $request->ip());

        return response()->json($this->convenios->detalle($convenio));
    }

    public function pagarCuota(Request $request, int $convenio, int $cuota, PagosEnLinea $enLinea): JsonResponse
    {
        $c = $this->convenioPropio($request, $convenio);
        abort_unless($c->estado === 'vigente', 422, 'El convenio no está vigente.');
        $montos = collect($this->convenios->cargosParaCuota($convenio, $cuota))->mapWithKeys(fn ($m) => [$m['id'] => $m['monto']])->all();
        abort_if(! $montos, 422, 'La cuota no tiene saldo por pagar.');

        return response()->json($enLinea->crearOrden(array_keys($montos), FamiliaController::alumnosDe((int) $request->user()->id), (int) $request->user()->id, $request->user()->email, $montos), 201);
    }

    // ── EDUN-79 / EDUN-80 · Recaudación bancaria

    public function recaudacion(): JsonResponse
    {
        return response()->json(['data' => $this->recaudacion->archivos(), 'bancos' => array_keys(config('recaudacion.bancos'))]);
    }

    public function archivoDeudas(Request $request): Response
    {
        $d = $request->validate(['banco' => ['required', Rule::in(array_keys(config('recaudacion.bancos')))]]);
        $r = $this->recaudacion->generarDeudas($d['banco'], (int) $request->user()->id);

        return response($r['contenido'], 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$r['nombre']}\"", 'X-Lineas' => (string) $r['lineas']]);
    }

    public function archivoPagos(Request $request): JsonResponse
    {
        $d = $request->validate(['banco' => ['required', Rule::in(array_keys(config('recaudacion.bancos')))], 'archivo' => ['required', 'file', 'max:5120']],
            ['archivo.required' => 'Sube el archivo de pagos del banco.']);

        return response()->json($this->recaudacion->procesarPagos($d['banco'], $d['archivo'], (int) $request->user()->id), 201);
    }

    public function lineas(int $archivo): JsonResponse
    {
        return response()->json(['data' => $this->recaudacion->lineas($archivo)]);
    }

    // ── EDUN-89 · Conciliación en línea

    public function conciliacion(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => ['nullable', 'regex:/^\d{4}-\d{2}$/']])['mes'] ?? null;
        $data = $this->recaudacion->pagosEnLinea($mes);

        return response()->json(['data' => $data, 'totales' => ['monto' => round(array_sum(array_column($data, 'monto')), 2),
            'comision' => round(array_sum(array_map(fn ($t) => $t['comision'] ?? 0, $data)), 2), 'neto' => round(array_sum(array_map(fn ($t) => $t['neto'] ?? 0, $data)), 2)]]);
    }

    public function conciliar(Request $request): JsonResponse
    {
        $d = $request->validate(['reporte' => ['required', 'file', 'max:5120']], ['reporte.required' => 'Sube el reporte de abonos de la pasarela (CSV).']);

        return response()->json($this->recaudacion->conciliar($d['reporte']));
    }

    // ── EDUN-83 · Reportes financieros

    public function reportes(Request $request): JsonResponse
    {
        $anio = (int) ($request->validate(['anio' => ['nullable', 'integer', 'between:2020,2100']])['anio'] ?? now()->year);

        return response()->json($this->recaudacion->reporte($anio));
    }

    public function reportesExcel(Request $request): Response
    {
        $anio = (int) ($request->validate(['anio' => ['nullable', 'integer', 'between:2020,2100']])['anio'] ?? now()->year);

        return response($this->recaudacion->reporteExcel($anio), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"reporte-financiero-{$anio}.xlsx\""]);
    }
}
