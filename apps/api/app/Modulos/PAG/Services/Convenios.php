<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PAG-09 · Convenios de fraccionamiento (reglas 14 a 17 de PAG).
 *
 * - EDUN-84 · Solo deuda vencida de la familia, de 2 a 12 cuotas con fechas, sin intereses ni mora
 *   (parámetro legal por confirmar, D-15), acta en PDF y borrador hasta firma y aprobación.
 * - EDUN-85 · El apoderado firma en la app (o se adjunta el acta firmada); dirección aprueba y pasa
 *   a vigente; todo queda en la auditoría. Un cargo no puede estar en dos convenios activos.
 * - EDUN-86 · El convenio no crea deuda: los pagos se aplican a los cargos originales y las cuotas
 *   se marcan pagadas en orden; recordatorio antes del vencimiento; incumplido a los 30 días.
 * - EDUN-87 · La familia ve sus cuotas, lo pagado y el saldo, y paga la cuota en línea.
 */
class Convenios
{
    public function __construct(private Auditoria $auditoria, private AlmacenArchivos $almacen, private Notificador $notificador) {}

    /** Cargos vencidos (con saldo) de los hijos de un apoderado que no están en otro convenio activo. */
    public function cargosElegibles(int $apoderadoId): array
    {
        $hijos = DB::table('alumno_apoderado')->where('apoderado_id', $apoderadoId)->pluck('alumno_id');

        return DB::table('cargos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->whereIn('c.alumno_id', $hijos)->whereIn('c.estado', ['pendiente', 'parcial'])
            ->where('c.vencimiento', '<', now()->toDateString())
            ->whereNotExists(fn ($q) => $q->from('convenio_cargos as cc')->join('convenios_fraccionamiento as cf', 'cf.id', '=', 'cc.convenio_fraccionamiento_id')
                ->whereColumn('cc.cargo_id', 'c.id')->whereIn('cf.estado', ['borrador', 'vigente']))
            ->orderBy('c.vencimiento')->get(['c.id', 'c.alumno_id', 'a.nombres as alumno', 'c.descripcion', 'c.vencimiento', DB::raw(Cobros::saldoSql().' as saldo')])
            ->map(fn ($c) => array_merge((array) $c, ['saldo' => (float) $c->saldo]))->all();
    }

    /** Cuotas iguales (la última ajusta los céntimos), mensuales desde la primera fecha. */
    public static function simular(float $total, int $cuotas, string $primera): array
    {
        if ($cuotas < 2 || $cuotas > 12) {
            throw ValidationException::withMessages(['cuotas' => ['El convenio va de 2 a 12 cuotas.']]);
        }
        $base = floor($total / $cuotas * 100) / 100;
        $r = [];
        for ($i = 1; $i <= $cuotas; $i++) {
            $r[] = ['numero' => $i, 'vencimiento' => Carbon::parse($primera)->addMonthsNoOverflow($i - 1)->toDateString(),
                'monto' => $i < $cuotas ? $base : round($total - $base * ($cuotas - 1), 2)];
        }

        return $r;
    }

    /** @param list<int> $cargoIds */
    public function crear(int $apoderadoId, array $cargoIds, int $cuotas, string $primera, ?string $observaciones, int $usuarioId): int
    {
        $elegibles = collect($this->cargosElegibles($apoderadoId))->keyBy('id');
        $cargos = collect($cargoIds)->unique()->map(fn ($id) => $elegibles->get((int) $id));
        if ($cargos->isEmpty() || $cargos->contains(null)) {
            throw ValidationException::withMessages(['cargos' => ['Elige solo cargos vencidos de la familia que no estén en otro convenio.']]);
        }
        if ($primera < now()->toDateString()) {
            throw ValidationException::withMessages(['primera' => ['La primera cuota debe vencer hoy o después.']]);
        }
        $total = round($cargos->sum('saldo'), 2);
        $plan = self::simular($total, $cuotas, $primera);

        return DB::transaction(function () use ($apoderadoId, $cargos, $plan, $total, $observaciones, $usuarioId) {
            $inst = ContextoInstitucion::requerido();
            DB::select('select pg_advisory_xact_lock(?, ?)', [$inst, 9001]);
            $n = 1 + (int) DB::table('convenios_fraccionamiento')->max(DB::raw("cast(split_part(numero, '-', 2) as int)"));
            $id = (int) DB::table('convenios_fraccionamiento')->insertGetId([
                'institucion_id' => $inst, 'numero' => sprintf('CF-%05d', $n), 'apoderado_id' => $apoderadoId, 'alumno_id' => $cargos->first()['alumno_id'],
                'monto_total' => $total, 'numero_cuotas' => count($plan), 'observaciones' => $observaciones,
            ]);
            foreach ($cargos as $c) {
                DB::table('convenio_cargos')->insert(['institucion_id' => $inst, 'convenio_fraccionamiento_id' => $id, 'cargo_id' => $c['id'], 'saldo_incluido' => $c['saldo']]);
            }
            foreach ($plan as $q) {
                DB::table('convenio_cuotas')->insert(['institucion_id' => $inst, 'convenio_fraccionamiento_id' => $id] + $q);
            }
            $acta = $this->almacen->guardarContenido($this->actaPdf($id), sprintf('acta-convenio-%05d.pdf', $n), 'application/pdf', $usuarioId, true);
            DB::table('convenios_fraccionamiento')->where('id', $id)->update(['acta_archivo_id' => $acta->id]);
            $this->auditoria->registrar('convenio_creado', 'convenios_fraccionamiento', $id, null, ['monto' => $total, 'cuotas' => count($plan), 'cargos' => $cargos->pluck('id')->all()]);

            return $id;
        });
    }

    /** Firma del apoderado (en la app) o acta firmada adjunta por tesorería. */
    public function firmar(int $id, string $canal, ?int $usuarioId, ?string $ip, ?int $archivoId = null): void
    {
        $c = DB::table('convenios_fraccionamiento')->find($id);
        abort_if(! $c, 404);
        if ($c->estado !== 'borrador' || $c->firmado_at) {
            throw ValidationException::withMessages(['convenio' => ['El convenio ya fue firmado o no está en borrador.']]);
        }
        $cambios = ['firmado_at' => now(), 'firmado_por' => $usuarioId, 'firma_canal' => $canal, 'firma_ip' => $ip];
        if ($archivoId) {
            $cambios['acta_archivo_id'] = $archivoId;
        }
        DB::table('convenios_fraccionamiento')->where('id', $id)->update($cambios);
        $this->auditoria->registrar('convenio_firmado', 'convenios_fraccionamiento', $id, null, ['canal' => $canal]);
    }

    public function aprobar(int $id, int $usuarioId): void
    {
        $c = DB::table('convenios_fraccionamiento')->find($id);
        abort_if(! $c, 404);
        if ($c->estado !== 'borrador') {
            throw ValidationException::withMessages(['convenio' => ['Solo se aprueba un convenio en borrador.']]);
        }
        if (! $c->firmado_at) {
            throw ValidationException::withMessages(['convenio' => ['Falta la firma del apoderado (en la app o el acta firmada).']]);
        }
        DB::table('convenios_fraccionamiento')->where('id', $id)->update(['estado' => 'vigente', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);
        $this->auditoria->registrar('convenio_aprobado', 'convenios_fraccionamiento', $id, ['estado' => 'borrador'], ['estado' => 'vigente']);
        $usuario = DB::table('apoderados')->where('id', $c->apoderado_id)->value('user_id');
        if ($usuario) {
            $this->notificador->enviar([(int) $usuario], ['app'], 'pagos', "Convenio {$c->numero} aprobado", 'Puedes ver tus cuotas y pagarlas desde la app.', ['pantalla' => 'pagos', 'id' => $id]);
        }
    }

    public function anular(int $id, string $motivo): void
    {
        $c = DB::table('convenios_fraccionamiento')->find($id);
        abort_if(! $c || in_array($c->estado, ['cumplido', 'anulado'], true), 404);
        DB::table('convenios_fraccionamiento')->where('id', $id)->update(['estado' => 'anulado', 'observaciones' => trim(($c->observaciones ? $c->observaciones."\n" : '').'Anulado: '.$motivo)]);
        $this->auditoria->registrar('convenio_anulado', 'convenios_fraccionamiento', $id, ['estado' => $c->estado], ['estado' => 'anulado', 'motivo' => $motivo]);
    }

    /**
     * Lo llama Cobros al aplicar un pago: el monto pagado a cargos de un convenio vigente se
     * reparte en sus cuotas en orden (regla 15).
     *
     * @param  list<array{id: int, monto: float}>  $aplicados
     */
    public static function alPagar(array $aplicados): void
    {
        $porConvenio = DB::table('convenio_cargos as cc')->join('convenios_fraccionamiento as cf', 'cf.id', '=', 'cc.convenio_fraccionamiento_id')
            ->whereIn('cc.cargo_id', array_column($aplicados, 'id'))->where('cf.estado', 'vigente')->get(['cc.cargo_id', 'cf.id as convenio'])->groupBy('convenio');
        $montos = collect($aplicados)->mapWithKeys(fn ($a) => [(int) $a['id'] => (float) $a['monto']]);
        foreach ($porConvenio as $convenio => $filas) {
            $restante = round($filas->sum(fn ($f) => $montos[(int) $f->cargo_id] ?? 0), 2);
            foreach (DB::table('convenio_cuotas')->where('convenio_fraccionamiento_id', $convenio)->whereColumn('pagado', '<', 'monto')->orderBy('numero')->lockForUpdate()->get() as $q) {
                if ($restante <= 0) {
                    break;
                }
                $aplica = min($restante, round((float) $q->monto - (float) $q->pagado, 2));
                DB::table('convenio_cuotas')->where('id', $q->id)->update(['pagado' => round((float) $q->pagado + $aplica, 2),
                    'estado' => abs((float) $q->pagado + $aplica - (float) $q->monto) < 0.005 ? 'pagada' : $q->estado]);
                $restante = round($restante - $aplica, 2);
            }
            if (! DB::table('convenio_cuotas')->where('convenio_fraccionamiento_id', $convenio)->where('estado', '<>', 'pagada')->exists()) {
                DB::table('convenios_fraccionamiento')->where('id', $convenio)->update(['estado' => 'cumplido']);
            }
        }
    }

    /** Al anular un pago: descuenta lo aplicado a las cuotas, de la última pagada hacia atrás. */
    public static function alAnular(array $aplicados): void
    {
        $porConvenio = DB::table('convenio_cargos as cc')->join('convenios_fraccionamiento as cf', 'cf.id', '=', 'cc.convenio_fraccionamiento_id')
            ->whereIn('cc.cargo_id', array_column($aplicados, 'id'))->whereIn('cf.estado', ['vigente', 'cumplido'])->get(['cc.cargo_id', 'cf.id as convenio', 'cf.estado'])->groupBy('convenio');
        $montos = collect($aplicados)->mapWithKeys(fn ($a) => [(int) $a['id'] => (float) $a['monto']]);
        $hoy = now()->toDateString();
        foreach ($porConvenio as $convenio => $filas) {
            $restante = round($filas->sum(fn ($f) => $montos[(int) $f->cargo_id] ?? 0), 2);
            foreach (DB::table('convenio_cuotas')->where('convenio_fraccionamiento_id', $convenio)->where('pagado', '>', 0)->orderByDesc('numero')->lockForUpdate()->get() as $q) {
                if ($restante <= 0) {
                    break;
                }
                $quita = min($restante, (float) $q->pagado);
                DB::table('convenio_cuotas')->where('id', $q->id)->update(['pagado' => round((float) $q->pagado - $quita, 2),
                    'estado' => $q->vencimiento < $hoy ? 'vencida' : 'pendiente']);
                $restante = round($restante - $quita, 2);
            }
            if ($filas->first()->estado === 'cumplido') {
                DB::table('convenios_fraccionamiento')->where('id', $convenio)->update(['estado' => 'vigente']);
            }
        }
    }

    /** Recordatorio 3 días antes, cuotas vencidas e incumplimiento a los 30 días (regla 17). */
    public function revisar(): array
    {
        $hoy = now()->toDateString();
        $r = ['recordatorios' => 0, 'vencidas' => 0, 'incumplidos' => 0];
        $r['vencidas'] = DB::table('convenio_cuotas as q')->join('convenios_fraccionamiento as c', 'c.id', '=', 'q.convenio_fraccionamiento_id')
            ->where('c.estado', 'vigente')->where('q.estado', 'pendiente')->where('q.vencimiento', '<', $hoy)->update(['q.estado' => 'vencida']);
        foreach (DB::table('convenio_cuotas as q')->join('convenios_fraccionamiento as c', 'c.id', '=', 'q.convenio_fraccionamiento_id')->join('apoderados as a', 'a.id', '=', 'c.apoderado_id')
            ->where('c.estado', 'vigente')->where('q.estado', 'pendiente')->whereNull('q.recordatorio_at')->whereBetween('q.vencimiento', [$hoy, now()->addDays(3)->toDateString()])
            ->get(['q.id', 'q.numero', 'q.monto', 'q.pagado', 'q.vencimiento', 'c.numero as convenio', 'a.user_id']) as $q) {
            if ($q->user_id) {
                $this->notificador->enviar([(int) $q->user_id], ['app'], 'pagos', "Cuota {$q->numero} del convenio {$q->convenio}", 'Vence el '.Carbon::parse($q->vencimiento)->format('d/m').' por S/ '.number_format((float) $q->monto - (float) $q->pagado, 2).'.', ['pantalla' => 'pagos']);
            }
            DB::table('convenio_cuotas')->where('id', $q->id)->update(['recordatorio_at' => now()]);
            $r['recordatorios']++;
        }
        $tesoreria = DB::table('membresias as m')->join('roles as ro', 'ro.id', '=', 'm.rol_id')->where('ro.codigo', 'tesoreria')->pluck('m.user_id')->all();
        foreach (DB::table('convenios_fraccionamiento as c')->where('c.estado', 'vigente')
            ->whereExists(fn ($e) => $e->from('convenio_cuotas as q')->whereColumn('q.convenio_fraccionamiento_id', 'c.id')->where('q.estado', '<>', 'pagada')->where('q.vencimiento', '<', now()->subDays(30)->toDateString()))
            ->get(['c.id', 'c.numero']) as $c) {
            DB::table('convenios_fraccionamiento')->where('id', $c->id)->update(['estado' => 'incumplido', 'incumplido_at' => now()]);
            $this->auditoria->registrar('convenio_incumplido', 'convenios_fraccionamiento', (int) $c->id, ['estado' => 'vigente'], ['estado' => 'incumplido']);
            $this->notificador->enviar($tesoreria, ['app'], 'pagos', "Convenio {$c->numero} incumplido", 'Una cuota lleva más de 30 días vencida. La deuda sigue sin bloquear notas ni libretas.', ['pantalla' => 'convenios', 'id' => $c->id]);
            $r['incumplidos']++;
        }

        return $r;
    }

    public function listar(?int $apoderadoId = null): array
    {
        return DB::table('convenios_fraccionamiento as c')->join('apoderados as a', 'a.id', '=', 'c.apoderado_id')->join('alumnos as al', 'al.id', '=', 'c.alumno_id')
            ->when($apoderadoId, fn ($q) => $q->where('c.apoderado_id', $apoderadoId))->orderByDesc('c.id')->limit(300)
            ->get(['c.*', DB::raw("a.nombres || ' ' || a.apellidos as apoderado"), 'al.nombres as alumno'])
            ->map(fn ($c) => $this->detalle((int) $c->id, $c))->all();
    }

    public function detalle(int $id, ?object $c = null): array
    {
        $c ??= DB::table('convenios_fraccionamiento as c')->join('apoderados as a', 'a.id', '=', 'c.apoderado_id')->join('alumnos as al', 'al.id', '=', 'c.alumno_id')
            ->where('c.id', $id)->first(['c.*', DB::raw("a.nombres || ' ' || a.apellidos as apoderado"), 'al.nombres as alumno']);
        abort_if(! $c, 404);
        $cuotas = DB::table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->orderBy('numero')->get()
            ->map(fn ($q) => ['id' => $q->id, 'numero' => $q->numero, 'vencimiento' => $q->vencimiento, 'monto' => (float) $q->monto, 'pagado' => (float) $q->pagado, 'estado' => $q->estado]);
        $pagado = round($cuotas->sum('pagado'), 2);

        return array_merge((array) $c, ['monto_total' => (float) $c->monto_total, 'cuotas' => $cuotas->all(), 'pagado' => $pagado, 'saldo' => round((float) $c->monto_total - $pagado, 2),
            'cargos' => DB::table('convenio_cargos as cc')->join('cargos as g', 'g.id', '=', 'cc.cargo_id')->where('cc.convenio_fraccionamiento_id', $id)
                ->get(['g.id', 'g.descripcion', 'cc.saldo_incluido'])->map(fn ($g) => ['id' => $g->id, 'descripcion' => $g->descripcion, 'saldo_incluido' => (float) $g->saldo_incluido])->all()]);
    }

    /** Montos a aplicar a los cargos del convenio para pagar una cuota (el más antiguo primero). */
    public function cargosParaCuota(int $id, int $numero): array
    {
        $q = DB::table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->where('numero', $numero)->first();
        abort_if(! $q || $q->estado === 'pagada', 404);
        $pendiente = round((float) $q->monto - (float) $q->pagado, 2);
        $r = [];
        foreach (DB::table('convenio_cargos as cc')->join('cargos as g', 'g.id', '=', 'cc.cargo_id')->where('cc.convenio_fraccionamiento_id', $id)
            ->whereIn('g.estado', ['pendiente', 'parcial'])->orderBy('g.vencimiento')->get(['g.id', DB::raw(Cobros::saldoSql('g').' as saldo')]) as $g) {
            if ($pendiente <= 0) {
                break;
            }
            $m = min($pendiente, (float) $g->saldo);
            $r[] = ['id' => (int) $g->id, 'monto' => round($m, 2)];
            $pendiente = round($pendiente - $m, 2);
        }

        return $r;
    }

    private function actaPdf(int $id): string
    {
        $d = $this->detalle($id);
        $inst = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->first(['nombre', 'razon_social', 'ruc']);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => 'S/ '.number_format((float) $v, 2);
        $cargos = implode('', array_map(fn ($g) => '<tr><td>'.$e($g['descripcion']).'</td><td class="n">'.$m($g['saldo_incluido']).'</td></tr>', $d['cargos']));
        $cuotas = implode('', array_map(fn ($q) => '<tr><td>'.$q['numero'].'</td><td>'.$e(Carbon::parse($q['vencimiento'])->format('d/m/Y')).'</td><td class="n">'.$m($q['monto']).'</td></tr>', $d['cuotas']));
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}table{width:100%;border-collapse:collapse;margin:8px 0}td,th{border:1px solid #ccc;padding:4px}.n{text-align:right}</style></head><body>'
            .'<h2>Acta de convenio de fraccionamiento '.$e($d['numero']).'</h2><p>'.$e($inst->razon_social ?: $inst->nombre).($inst->ruc ? ' · RUC '.$e($inst->ruc) : '').'</p>'
            .'<p>Apoderado(a): <b>'.$e($d['apoderado']).'</b> · Alumno(a): '.$e($d['alumno']).'</p>'
            .'<p>Deuda vencida que se fracciona (sin intereses ni mora adicional):</p><table><thead><tr><th>Cargo</th><th>Saldo</th></tr></thead><tbody>'.$cargos.'</tbody></table>'
            .'<p>Total: <b>'.$m($d['monto_total']).'</b> en '.$d['numero_cuotas'].' cuotas:</p><table><thead><tr><th>N.°</th><th>Vence</th><th>Monto</th></tr></thead><tbody>'.$cuotas.'</tbody></table>'
            .'<p>Los pagos se aplican a los cargos originales. Si una cuota permanece vencida más de 30 días el convenio queda incumplido. La deuda no impide el acceso a notas ni libretas.</p>'
            .'<br><br><table style="border:0"><tr><td style="border:0">_______________________<br>Apoderado(a)</td><td style="border:0">_______________________<br>Dirección</td></tr></table></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return (string) $pdf->output();
    }

    public function familiaDe(int $usuarioId): Collection
    {
        return DB::table('apoderados')->where('user_id', $usuarioId)->pluck('id');
    }
}
