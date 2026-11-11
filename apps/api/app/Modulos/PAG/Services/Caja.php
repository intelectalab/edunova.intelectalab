<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAG-05 · Caja.
 *
 * - EDUN-75 · PAG-H07 Abrir con monto inicial, buscar al alumno por nombre o DNI, cobrar con
 *   varios medios en un mismo pago y entregar la boleta (PDF o correo).
 * - EDUN-76 · PAG-H08 Cierre con arqueo: totales por medio, diferencia con justificación
 *   obligatoria y reporte en PDF. La caja no se cierra con diferencias sin justificar (regla 13).
 */
class Caja
{
    public function __construct(private Auditoria $auditoria, private Cobros $cobros, private Comprobantes $comprobantes) {}

    public function cajas(): array
    {
        return DB::table('cajas as c')->leftJoin('caja_sesiones as s', fn ($j) => $j->on('s.caja_id', '=', 'c.id')->whereNull('s.cerrada_at'))
            ->leftJoin('users as u', 'u.id', '=', 's.abierta_por')->where('c.activa', true)->orderBy('c.nombre')
            ->get(['c.id', 'c.nombre', 's.id as sesion_id', 's.abierta_at', 's.abierta_por', DB::raw("u.nombres || ' ' || u.apellidos as abierta_por_nombre")])
            ->map(fn ($c) => (array) $c)->all();
    }

    public function crearCaja(string $nombre): int
    {
        return (int) DB::table('cajas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'nombre' => trim($nombre)]);
    }

    public function abrir(int $cajaId, float $montoInicial, int $usuarioId): int
    {
        abort_if(! DB::table('cajas')->where('id', $cajaId)->where('activa', true)->exists(), 404);
        if (DB::table('caja_sesiones')->where('caja_id', $cajaId)->whereNull('cerrada_at')->exists()) {
            throw ValidationException::withMessages(['caja_id' => ['Esa caja ya está abierta. Ciérrala antes de abrirla de nuevo.']]);
        }
        if (DB::table('caja_sesiones')->where('abierta_por', $usuarioId)->whereNull('cerrada_at')->exists()) {
            throw ValidationException::withMessages(['caja_id' => ['Ya tienes otra caja abierta.']]);
        }
        $id = (int) DB::table('caja_sesiones')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'caja_id' => $cajaId, 'abierta_por' => $usuarioId, 'monto_inicial' => round($montoInicial, 2),
        ]);
        $this->auditoria->registrar('caja_abierta', 'caja_sesiones', $id, null, ['caja_id' => $cajaId, 'monto_inicial' => $montoInicial]);

        return $id;
    }

    public function sesionAbierta(int $usuarioId): ?object
    {
        return DB::table('caja_sesiones as s')->join('cajas as c', 'c.id', '=', 's.caja_id')->where('s.abierta_por', $usuarioId)->whereNull('s.cerrada_at')
            ->first(['s.*', 'c.nombre as caja']);
    }

    /** Alumnos por nombre, apellidos o DNI con su deuda pendiente y sus apoderados (para la boleta). */
    public function buscar(string $texto): array
    {
        $t = '%'.mb_strtolower(trim($texto)).'%';
        $alumnos = DB::table('alumnos as a')->whereNull('a.eliminado_at')
            ->where(fn ($w) => $w->whereRaw("lower(a.apellido_paterno || ' ' || coalesce(a.apellido_materno, '') || ' ' || a.nombres) like ?", [$t])->orWhere('a.numero_documento', 'like', $t))
            ->orderBy('a.apellido_paterno')->limit(10)->get(['a.id', 'a.nombres', 'a.apellido_paterno', 'a.apellido_materno', 'a.numero_documento']);
        $ids = $alumnos->pluck('id')->all();
        $cargos = collect($this->cobros->pendientes($ids))->groupBy('alumno_id');
        $apoderados = DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('aa.alumno_id', $ids)
            ->orderByDesc('aa.es_responsable_pago')->get(['aa.alumno_id', 'p.id', 'p.nombres', 'p.apellidos', 'p.numero_documento', 'p.email', 'aa.es_responsable_pago'])->groupBy('alumno_id');

        return $alumnos->map(fn ($a) => (array) $a + [
            'nombre' => trim("{$a->apellido_paterno} {$a->apellido_materno}, {$a->nombres}"),
            'cargos' => ($cargos[$a->id] ?? collect())->values()->all(),
            'apoderados' => ($apoderados[$a->id] ?? collect())->map(fn ($p) => (array) $p)->values()->all(),
        ])->all();
    }

    /**
     * @param  list<array{id: int, monto: float}>  $cargos
     * @param  list<array{medio: string, monto: float, referencia?: string|null}>  $medios
     * @param  array{tipo_doc?: string, numero_doc: string, nombre: string, email?: string|null}  $cliente
     * @return array{pago_id: int, comprobante_id: int}
     */
    public function cobrar(int $usuarioId, array $cargos, array $medios, ?int $apoderadoId, string $tipoComprobante, array $cliente): array
    {
        $sesion = $this->sesionAbierta($usuarioId);
        if (! $sesion) {
            throw ValidationException::withMessages(['caja' => ['Abre una caja antes de cobrar.']]);
        }

        return DB::transaction(function () use ($sesion, $usuarioId, $cargos, $medios, $apoderadoId, $tipoComprobante, $cliente) {
            $pago = $this->cobros->registrar($cargos, $medios, ['caja_sesion_id' => $sesion->id, 'apoderado_id' => $apoderadoId], $usuarioId);
            $comprobante = $this->comprobantes->emitirParaPago($pago, $tipoComprobante, $cliente);

            return ['pago_id' => $pago, 'comprobante_id' => $comprobante];
        });
    }

    /** Totales de la sesión por medio (lo esperado en el arqueo). */
    public function resumen(int $sesionId): array
    {
        $s = DB::table('caja_sesiones as s')->join('cajas as c', 'c.id', '=', 's.caja_id')->leftJoin('users as u', 'u.id', '=', 's.abierta_por')
            ->where('s.id', $sesionId)->first(['s.*', 'c.nombre as caja', DB::raw("u.nombres || ' ' || u.apellidos as cajero")]);
        abort_if(! $s, 404);
        $pagos = DB::table('pagos')->where('caja_sesion_id', $sesionId)->where('estado', 'valido')->pluck('id');
        $porMedio = array_fill_keys(array_keys(Cobros::MEDIOS), 0.0);
        foreach (DB::table('pago_medios')->whereIn('pago_id', $pagos)->get(['medio', 'monto']) as $m) {
            $porMedio[$m->medio] += (float) $m->monto;
        }
        foreach (DB::table('pagos')->whereIn('id', $pagos)->whereNotExists(fn ($q) => $q->from('pago_medios')->whereColumn('pago_medios.pago_id', 'pagos.id'))->get(['medio', 'monto']) as $p) {
            $porMedio[$p->medio] = ($porMedio[$p->medio] ?? 0) + (float) $p->monto;
        }
        $porMedio = array_map(fn ($v) => round($v, 2), $porMedio);
        $anulados = DB::table('pagos')->where('caja_sesion_id', $sesionId)->where('estado', 'anulado')->count();

        return [
            'sesion' => array_merge((array) $s, ['monto_inicial' => (float) $s->monto_inicial]),
            'por_medio' => $porMedio, 'cobrado' => round(array_sum($porMedio), 2), 'pagos' => $pagos->count(), 'anulados' => $anulados,
            'efectivo_esperado' => round((float) $s->monto_inicial + $porMedio['efectivo'], 2),
        ];
    }

    /**
     * @param  array<string, float>  $contado  medio => monto contado
     */
    public function cerrar(int $sesionId, array $contado, ?string $justificacion, int $usuarioId): array
    {
        $r = $this->resumen($sesionId);
        if ($r['sesion']['cerrada_at']) {
            throw ValidationException::withMessages(['caja' => ['La caja ya está cerrada.']]);
        }
        $esperado = $r['por_medio'];
        $esperado['efectivo'] = $r['efectivo_esperado'];
        $arqueo = [];
        $diferencia = 0.0;
        foreach ($esperado as $medio => $monto) {
            $cont = round((float) ($contado[$medio] ?? ($medio === 'efectivo' ? 0 : $monto)), 2);
            $arqueo[$medio] = ['esperado' => $monto, 'contado' => $cont, 'diferencia' => round($cont - $monto, 2)];
            $diferencia += $cont - $monto;
        }
        $diferencia = round($diferencia, 2);
        if (abs($diferencia) >= 0.01 && mb_strlen(trim((string) $justificacion)) < 10) {
            throw ValidationException::withMessages(['justificacion' => ['Hay una diferencia de S/ '.number_format($diferencia, 2).': explica el motivo (al menos 10 caracteres).']]);
        }
        DB::table('caja_sesiones')->where('id', $sesionId)->update([
            'cerrada_at' => now(), 'cerrada_por' => $usuarioId, 'arqueo' => json_encode($arqueo), 'diferencia' => $diferencia, 'justificacion' => $justificacion ?: null,
        ]);
        $this->auditoria->registrar('caja_cerrada', 'caja_sesiones', $sesionId, null, ['diferencia' => $diferencia, 'cobrado' => $r['cobrado']]);

        return ['diferencia' => $diferencia, 'arqueo' => $arqueo];
    }

    public function reportePdf(int $sesionId): string
    {
        $r = $this->resumen($sesionId);
        $s = $r['sesion'];
        $arqueo = $s['arqueo'] ? json_decode($s['arqueo'], true) : null;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($n) => 'S/ '.number_format((float) $n, 2);
        $hora = fn ($t) => $t ? Carbon::parse($t)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—';
        $filas = '';
        foreach (Cobros::MEDIOS as $k => $nombre) {
            $a = $arqueo[$k] ?? null;
            $filas .= '<tr><td>'.$e($nombre).'</td><td class="n">'.$m($k === 'efectivo' ? $r['efectivo_esperado'] : $r['por_medio'][$k]).'</td><td class="n">'.($a ? $m($a['contado']) : '—').'</td><td class="n">'.($a ? $m($a['diferencia']) : '—').'</td></tr>';
        }
        $pagos = DB::table('pagos as p')->leftJoin('comprobantes as c', fn ($j) => $j->on('c.pago_id', '=', 'p.id')->whereIn('c.tipo', ['01', '03']))
            ->where('p.caja_sesion_id', $sesionId)->orderBy('p.id')->get(['p.fecha', 'p.medio', 'p.monto', 'p.estado', DB::raw("c.serie || '-' || c.numero as comprobante"), 'c.cliente_nombre']);
        $detalle = $pagos->map(fn ($p) => '<tr><td>'.$e($hora($p->fecha)).'</td><td>'.$e($p->comprobante ?? '—').'</td><td>'.$e($p->cliente_nombre ?? '').'</td><td>'.$e($p->medio).'</td><td class="n">'.$m($p->monto).($p->estado === 'anulado' ? ' (anulado)' : '').'</td></tr>')->implode('');
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}h1{font-size:15px}table{width:100%;border-collapse:collapse;margin:10px 0}
            th,td{border-bottom:1px solid #ddd;padding:4px;text-align:left}.n{text-align:right}</style></head><body>'
            .'<h1>Arqueo de caja · '.$e($s['caja']).'</h1><p>Cajero: '.$e($s['cajero']).' · Apertura: '.$e($hora($s['abierta_at'])).' · Cierre: '.$e($hora($s['cerrada_at'])).'<br>Monto inicial: '.$m($s['monto_inicial']).' · Cobrado: '.$m($r['cobrado']).' en '.$r['pagos'].' pagos'.($r['anulados'] ? " · {$r['anulados']} anulados" : '').'</p>'
            .'<table><thead><tr><th>Medio</th><th class="n">Esperado</th><th class="n">Contado</th><th class="n">Diferencia</th></tr></thead><tbody>'.$filas.'</tbody></table>'
            .($s['diferencia'] !== null ? '<p><b>Diferencia total: '.$m($s['diferencia']).'</b>'.($s['justificacion'] ? ' · Justificación: '.$e($s['justificacion']) : '').'</p>' : '<p>Caja aún abierta.</p>')
            .'<h2 style="font-size:12px">Detalle</h2><table><thead><tr><th>Hora</th><th>Comprobante</th><th>Cliente</th><th>Medio</th><th class="n">Monto</th></tr></thead><tbody>'.$detalle.'</tbody></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return (string) $pdf->output();
    }
}
