<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * PAG-07 · Recaudación bancaria, conciliación en línea y reportes.
 *
 * - EDUN-79 · Archivo de deudas BCP o Interbank con los cargos vencidos o por vencer del mes.
 * - EDUN-80 · Archivo de pagos del banco: concilia por código de recaudación; marca monto distinto,
 *   sin coincidencia o duplicado; registra el pago y emite el comprobante de los conciliados.
 * - EDUN-89 · Pagos en línea con monto, comisión y neto, comparados con el reporte de abonos.
 * - EDUN-83 · Ingresos por mes, concepto y nivel, proyección de cobranza y exportación a Excel.
 * Los formatos de los bancos se configuran en config/recaudacion.php.
 */
class Recaudacion
{
    public function __construct(private AlmacenArchivos $almacen, private Auditoria $auditoria, private Cobros $cobros, private Comprobantes $comprobantes) {}

    private static function banco(string $banco): array
    {
        $b = config("recaudacion.bancos.{$banco}");
        if (! $b) {
            throw ValidationException::withMessages(['banco' => ['Elige BCP o Interbank.']]);
        }

        return $b;
    }

    /** @return array{id: int, lineas: int, contenido: string, nombre: string} */
    public function generarDeudas(string $banco, int $usuarioId): array
    {
        $b = self::banco($banco);
        $sep = config('recaudacion.separador');
        $fin = now()->endOfMonth()->toDateString();
        $cargos = DB::table('cargos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->whereIn('c.estado', ['pendiente', 'parcial'])
            ->where('c.vencimiento', '<=', $fin)->where('c.emision', '<=', $fin)->whereNotNull('c.codigo_recaudacion')->orderBy('c.vencimiento')
            ->get(['c.codigo_recaudacion', 'c.descripcion', 'c.vencimiento', DB::raw(Cobros::saldoSql().' as saldo'), DB::raw("a.apellido_paterno || ' ' || a.nombres as alumno")]);
        $lineas = $cargos->map(fn ($c) => implode($sep, array_map(fn ($col) => match ($col) {
            'codigo_recaudacion' => $c->codigo_recaudacion,
            'alumno' => mb_strtoupper(str_replace($sep, ' ', $c->alumno)),
            'concepto' => mb_strtoupper(str_replace($sep, ' ', mb_substr($c->descripcion, 0, 40))),
            'vencimiento' => Carbon::parse($c->vencimiento)->format($b['formato_fecha']),
            'monto' => number_format((float) $c->saldo, 2, '.', ''),
            default => '',
        }, $b['deudas'])))->all();
        $contenido = implode("\r\n", $lineas)."\r\n";
        $nombre = strtolower($banco).'-deudas-'.now()->format('Ymd').'.txt';
        $archivo = $this->almacen->guardarContenido($contenido, $nombre, 'text/plain', $usuarioId, true);
        $id = (int) DB::table('recaudacion_archivos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'banco' => $banco, 'direccion' => 'deudas',
            'archivo_id' => $archivo->id, 'lineas' => count($lineas), 'estado' => 'generado', 'procesado_por' => $usuarioId]);
        $this->auditoria->registrar('recaudacion_deudas', 'recaudacion_archivos', $id, null, ['banco' => $banco, 'lineas' => count($lineas)]);

        return ['id' => $id, 'lineas' => count($lineas), 'contenido' => $contenido, 'nombre' => $nombre];
    }

    public function procesarPagos(string $banco, UploadedFile $archivo, int $usuarioId): array
    {
        $b = self::banco($banco);
        $sep = config('recaudacion.separador');
        $guardado = $this->almacen->guardar($archivo, $usuarioId, true);
        $inst = ContextoInstitucion::requerido();
        $procesoId = (int) DB::table('recaudacion_archivos')->insertGetId(['institucion_id' => $inst, 'banco' => $banco, 'direccion' => 'pagos', 'archivo_id' => $guardado->id, 'procesado_por' => $usuarioId]);
        $vistos = [];
        $res = ['conciliado' => 0, 'monto_distinto' => 0, 'sin_coincidencia' => 0, 'duplicado' => 0];
        $n = 0;
        foreach (preg_split('/\r\n|\r|\n/', trim((string) file_get_contents($archivo->getRealPath()))) as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $n++;
            $p = explode($sep, $linea);
            $codigo = trim($p[$b['pagos']['codigo_recaudacion']] ?? '');
            $monto = round((float) str_replace(',', '.', trim($p[$b['pagos']['monto']] ?? '0')), 2);
            $operacion = trim($p[$b['pagos']['operacion']] ?? '') ?: null;
            try {
                $fecha = Carbon::createFromFormat($b['formato_fecha'], trim($p[$b['pagos']['fecha_pago']] ?? ''))->toDateString();
            } catch (Throwable) {
                $fecha = null;
            }
            $cargo = DB::table('cargos')->where('codigo_recaudacion', $codigo)->first(['id', 'alumno_id', 'estado', DB::raw(Cobros::saldoSql('cargos').' as saldo')]);
            $operacionRepetida = $operacion && DB::table('recaudacion_lineas as l')->join('recaudacion_archivos as r', 'r.id', '=', 'l.recaudacion_archivo_id')
                ->where('r.banco', $banco)->where('l.operacion', $operacion)->where('l.resultado', 'conciliado')->exists();
            $resultado = match (true) {
                // Anulado o exonerado después de enviar las deudas: no se cobra (tesorería lo revisa en la línea).
                ! $cargo || in_array($cargo->estado, ['anulado', 'exonerado'], true) => 'sin_coincidencia',
                isset($vistos[$codigo]) || $operacionRepetida || $cargo->estado === 'pagado' => 'duplicado',
                abs((float) $cargo->saldo - $monto) > 0.009 => 'monto_distinto',
                default => 'conciliado',
            };
            $vistos[$codigo] = true;
            $lineaId = (int) DB::table('recaudacion_lineas')->insertGetId(['institucion_id' => $inst, 'recaudacion_archivo_id' => $procesoId, 'numero_linea' => $i + 1,
                'codigo_recaudacion' => mb_substr($codigo ?: '?', 0, 20), 'monto' => $monto, 'fecha_pago' => $fecha, 'cargo_id' => $cargo->id ?? null, 'resultado' => $resultado, 'operacion' => $operacion]);
            if ($resultado === 'conciliado') {
                DB::transaction(function () use ($cargo, $monto, $operacion, $lineaId, $banco) {
                    $apoderado = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $cargo->alumno_id)
                        ->orderByDesc('aa.es_responsable_pago')->first(['p.id', 'p.numero_documento', 'p.nombres', 'p.apellidos', 'p.email']);
                    $pago = $this->cobros->registrar([['id' => (int) $cargo->id, 'monto' => $monto]], [], ['medio' => 'recaudacion', 'apoderado_id' => $apoderado->id ?? null, 'referencia' => "{$banco} ".($operacion ?? '')], null);
                    DB::table('pagos')->where('id', $pago)->update(['recaudacion_linea_id' => $lineaId]);
                    DB::table('recaudacion_lineas')->where('id', $lineaId)->update(['pago_id' => $pago]);
                    try {
                        $this->comprobantes->emitirParaPago($pago, '03', ['numero_doc' => $apoderado->numero_documento ?? '-', 'nombre' => $apoderado ? "{$apoderado->nombres} {$apoderado->apellidos}" : '', 'email' => $apoderado->email ?? null]);
                    } catch (ValidationException) {
                        // Sin proveedor de facturación: el pago queda registrado y la boleta se emite después.
                    }
                });
            }
            $res[$resultado]++;
        }
        DB::table('recaudacion_archivos')->where('id', $procesoId)->update(['lineas' => $n, 'conciliadas' => $res['conciliado'], 'estado' => $n === $res['conciliado'] ? 'completado' : 'observado']);
        $this->auditoria->registrar('recaudacion_pagos', 'recaudacion_archivos', $procesoId, null, ['banco' => $banco] + $res);

        return ['id' => $procesoId, 'lineas' => $n] + $res;
    }

    public function archivos(): array
    {
        return DB::table('recaudacion_archivos as r')->leftJoin('archivos as a', 'a.id', '=', 'r.archivo_id')->orderByDesc('r.id')->limit(100)
            ->get(['r.*', 'a.nombre_original'])->map(fn ($r) => (array) $r)->all();
    }

    public function lineas(int $archivoId): array
    {
        return DB::table('recaudacion_lineas as l')->leftJoin('cargos as c', 'c.id', '=', 'l.cargo_id')->leftJoin('alumnos as a', 'a.id', '=', 'c.alumno_id')
            ->where('l.recaudacion_archivo_id', $archivoId)->orderBy('l.numero_linea')
            ->get(['l.*', 'c.descripcion', DB::raw("a.apellido_paterno || ' ' || a.nombres as alumno"), DB::raw(Cobros::saldoSql('c').' as saldo')])
            ->map(fn ($l) => array_merge((array) $l, ['monto' => (float) $l->monto, 'saldo' => $l->saldo !== null ? (float) $l->saldo : null]))->all();
    }

    // ── EDUN-89 · Conciliación de pagos en línea

    public function pagosEnLinea(?string $mes): array
    {
        return DB::table('pasarela_transacciones as t')->where('t.estado', 'pagada')
            ->when($mes, fn ($q, $v) => $q->whereRaw("to_char(t.created_at at time zone 'America/Lima', 'YYYY-MM') = ?", [$v]))->orderByDesc('t.id')->limit(500)
            ->get(['t.id', 't.orden', 't.proveedor', 't.medio', 't.monto', 't.comision', 't.neto', 't.fecha_abono', 't.conciliacion', 't.conciliacion_detalle', 't.created_at', 't.excedente', 't.excedente_resuelto_at', 't.excedente_nota'])
            ->map(fn ($t) => array_merge((array) $t, ['monto' => (float) $t->monto, 'comision' => $t->comision !== null ? (float) $t->comision : null, 'neto' => $t->neto !== null ? (float) $t->neto : null, 'excedente' => (float) $t->excedente]))->all();
    }

    /** Reporte de abonos de la pasarela (CSV con orden, monto, comisión, neto y fecha de abono). */
    public function conciliar(UploadedFile $reporte): array
    {
        $filas = array_map(fn ($l) => str_getcsv($l, str_contains($l, ';') ? ';' : ','), array_filter(preg_split('/\r\n|\r|\n/', trim((string) file_get_contents($reporte->getRealPath())))));
        $cab = array_map(fn ($h) => mb_strtolower(trim($h)), array_shift($filas) ?? []);
        $col = fn (string $nombre) => array_search($nombre, $cab, true);
        foreach (['orden', 'monto', 'comision', 'neto'] as $req) {
            if ($col($req) === false) {
                throw ValidationException::withMessages(['reporte' => ["El reporte debe tener la columna «{$req}» (orden, monto, comision, neto, fecha_abono)."]]);
            }
        }
        $res = ['conciliado' => 0, 'monto_distinto' => 0, 'comision_distinta' => 0, 'sin_registro' => 0, 'no_abonado' => 0];
        $vistas = [];
        foreach ($filas as $f) {
            $orden = trim($f[$col('orden')] ?? '');
            $vistas[] = $orden;
            $t = DB::table('pasarela_transacciones')->where('orden', $orden)->where('estado', 'pagada')->first();
            if (! $t) {
                $res['sin_registro']++;

                continue;
            }
            $monto = round((float) $f[$col('monto')], 2);
            $comision = round((float) $f[$col('comision')], 2);
            [$estado, $detalle] = match (true) {
                abs($monto - (float) $t->monto) > 0.009 => ['monto_distinto', "Pasarela: S/ {$monto} · Edunova: S/ {$t->monto}"],
                $t->comision !== null && abs($comision - (float) $t->comision) > 0.009 => ['comision_distinta', "Comisión pasarela: S/ {$comision} · registrada: S/ {$t->comision}"],
                default => ['conciliado', null],
            };
            DB::table('pasarela_transacciones')->where('id', $t->id)->update(['conciliacion' => $estado, 'conciliacion_detalle' => $detalle, 'conciliado_at' => now(),
                'comision' => $comision, 'neto' => round((float) $f[$col('neto')], 2), 'fecha_abono' => ($i = $col('fecha_abono')) !== false && ! empty($f[$i]) ? Carbon::parse($f[$i])->toDateString() : $t->fecha_abono]);
            $res[$estado]++;
        }
        // Pagadas en Edunova que la pasarela no informa como abonadas.
        $res['no_abonado'] = DB::table('pasarela_transacciones')->where('estado', 'pagada')->whereNull('conciliacion')->whereNotIn('orden', $vistas ?: [''])
            ->where('created_at', '<', now()->subDays(3))->update(['conciliacion' => 'no_abonado', 'conciliacion_detalle' => 'No figura en el reporte de abonos.']);

        return $res;
    }

    // ── EDUN-83 · Reportes financieros

    public function reporte(int $anio): array
    {
        $ingresos = DB::table('pago_aplicaciones as pa')->join('pagos as p', 'p.id', '=', 'pa.pago_id')->join('cargos as c', 'c.id', '=', 'pa.cargo_id')
            ->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')->leftJoin('matriculas as m', 'm.id', '=', 'c.matricula_id')
            ->leftJoin('secciones as s', 's.id', '=', 'm.seccion_id')->leftJoin('grados as g', 'g.id', '=', 's.grado_id')
            ->where('p.estado', 'valido')->whereRaw("extract(year from p.fecha at time zone 'America/Lima') = ?", [$anio])
            ->selectRaw("to_char(p.fecha at time zone 'America/Lima', 'YYYY-MM') as mes, cp.nombre as concepto, coalesce(g.nivel, 'sin nivel') as nivel, sum(pa.monto) as monto")
            ->groupByRaw('1, 2, 3')->orderBy('mes')->get()->map(fn ($r) => ['mes' => $r->mes, 'concepto' => $r->concepto, 'nivel' => $r->nivel, 'monto' => round((float) $r->monto, 2)]);
        $proyeccion = DB::table('cargos as c')->where('c.estado', '<>', 'anulado')->whereRaw('extract(year from c.vencimiento) = ?', [$anio])
            ->selectRaw("to_char(c.vencimiento, 'YYYY-MM') as mes, sum(c.monto - c.descuento) as esperado, sum(c.pagado) as cobrado, sum(case when c.estado in ('pendiente','parcial') then c.monto - c.descuento + c.mora - c.pagado else 0 end) as pendiente")
            ->groupByRaw('1')->orderBy('mes')->get()->map(fn ($r) => ['mes' => $r->mes, 'esperado' => round((float) $r->esperado, 2), 'cobrado' => round((float) $r->cobrado, 2), 'pendiente' => round((float) $r->pendiente, 2),
                'porcentaje' => (float) $r->esperado > 0 ? (int) round((float) $r->cobrado * 100 / (float) $r->esperado) : 0]);

        return [
            'anio' => $anio, 'total' => round($ingresos->sum('monto'), 2),
            'por_mes' => $ingresos->groupBy('mes')->map(fn ($g, $m) => ['mes' => $m, 'monto' => round($g->sum('monto'), 2)])->values()->all(),
            'por_concepto' => $ingresos->groupBy('concepto')->map(fn ($g, $c) => ['concepto' => $c, 'monto' => round($g->sum('monto'), 2)])->sortByDesc('monto')->values()->all(),
            'por_nivel' => $ingresos->groupBy('nivel')->map(fn ($g, $n) => ['nivel' => $n, 'monto' => round($g->sum('monto'), 2)])->values()->all(),
            'detalle' => $ingresos->all(), 'proyeccion' => $proyeccion->all(),
        ];
    }

    public function reporteExcel(int $anio): string
    {
        $r = $this->reporte($anio);
        $ruta = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        $w = new Writer;
        $w->openToFile($ruta);
        $b = (new Style)->setFontBold();
        $w->addRow(Row::fromValues(["Ingresos {$anio}"], $b));
        $w->addRow(Row::fromValues(['Mes', 'Concepto', 'Nivel', 'Monto (S/)'], $b));
        foreach ($r['detalle'] as $d) {
            $w->addRow(Row::fromValues([$d['mes'], $d['concepto'], $d['nivel'], $d['monto']]));
        }
        $w->addRow(Row::fromValues(['']));
        $w->addRow(Row::fromValues(['Proyección de cobranza'], $b));
        $w->addRow(Row::fromValues(['Mes', 'Esperado', 'Cobrado', 'Pendiente', '% cobrado'], $b));
        foreach ($r['proyeccion'] as $p) {
            $w->addRow(Row::fromValues([$p['mes'], $p['esperado'], $p['cobrado'], $p['pendiente'], $p['porcentaje']]));
        }
        $w->close();
        $c = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $c;
    }
}
