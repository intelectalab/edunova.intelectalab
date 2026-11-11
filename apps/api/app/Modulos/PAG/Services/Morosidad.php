<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-81 · PAG-H13 Morosidad: tramos 1–30, 31–60 y más de 60 días por familia, gestión de
 * cobranza con compromiso de pago y recordatorio masivo. La deuda nunca bloquea notas ni libretas.
 */
class Morosidad
{
    public const TRAMOS = ['1-30' => '1 a 30 días', '31-60' => '31 a 60 días', '60+' => 'Más de 60 días'];

    public function __construct(private Notificador $notificador, private Auditoria $auditoria) {}

    /** Deuda vencida agrupada por apoderado responsable de pago. */
    public function familias(?string $tramo = null): array
    {
        $hoy = now()->toDateString();
        $cargos = DB::table('cargos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')
            ->join('alumno_apoderado as aa', fn ($j) => $j->on('aa.alumno_id', '=', 'c.alumno_id')->where('aa.es_responsable_pago', '=', true))
            ->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->whereIn('c.estado', ['pendiente', 'parcial'])->where('c.vencimiento', '<', $hoy)
            ->get(['c.id', 'c.vencimiento', DB::raw(Cobros::saldoSql().' as saldo'), 'a.nombres as alumno', 'p.id as apoderado_id', 'p.user_id',
                DB::raw("p.nombres || ' ' || p.apellidos as apoderado"), 'p.celular', 'p.email']);
        $ultimas = DB::table('gestiones_cobranza')->select('apoderado_id', DB::raw('max(created_at) as ultima'))->groupBy('apoderado_id')->pluck('ultima', 'apoderado_id');
        $compromisos = DB::table('compromisos_pago')->where('estado', 'vigente')->get()->keyBy('apoderado_id');

        return $cargos->groupBy('apoderado_id')->map(function ($g, $id) use ($ultimas, $compromisos) {
            $dias = (int) now()->startOfDay()->diffInDays(Carbon::parse($g->min('vencimiento')), true);
            $t = $dias > 60 ? '60+' : ($dias > 30 ? '31-60' : '1-30');
            $f = $g->first();

            return ['apoderado_id' => (int) $id, 'user_id' => $f->user_id, 'apoderado' => $f->apoderado, 'celular' => $f->celular, 'email' => $f->email,
                'alumnos' => $g->pluck('alumno')->unique()->values()->all(), 'cargos' => $g->count(), 'deuda' => round((float) $g->sum('saldo'), 2),
                'dias' => $dias, 'tramo' => $t, 'ultima_gestion' => $ultimas[$id] ?? null,
                'compromiso' => isset($compromisos[$id]) ? ['monto' => (float) $compromisos[$id]->monto, 'fecha' => $compromisos[$id]->fecha_compromiso] : null];
        })->filter(fn ($f) => ! $tramo || $f['tramo'] === $tramo)->sortByDesc('deuda')->values()->all();
    }

    public function resumen(): array
    {
        $f = collect($this->familias());

        return collect(array_keys(self::TRAMOS))->mapWithKeys(fn ($t) => [$t => ['familias' => $f->where('tramo', $t)->count(), 'deuda' => round($f->where('tramo', $t)->sum('deuda'), 2)]])->all();
    }

    public function historial(int $apoderadoId): array
    {
        return [
            'gestiones' => DB::table('gestiones_cobranza as g')->leftJoin('users as u', 'u.id', '=', 'g.realizado_por')->where('g.apoderado_id', $apoderadoId)->orderByDesc('g.id')
                ->get(['g.id', 'g.canal', 'g.resultado', 'g.detalle', 'g.created_at', DB::raw("u.nombres || ' ' || u.apellidos as por")])->map(fn ($g) => (array) $g)->all(),
            'compromisos' => DB::table('compromisos_pago')->where('apoderado_id', $apoderadoId)->orderByDesc('id')->get()->map(fn ($c) => array_merge((array) $c, ['monto' => (float) $c->monto]))->all(),
        ];
    }

    public function registrarGestion(int $apoderadoId, string $canal, string $resultado, ?string $detalle, ?float $monto, ?string $fecha, int $usuarioId): int
    {
        abort_if(! DB::table('apoderados')->where('id', $apoderadoId)->exists(), 404);
        if (($monto === null) !== ($fecha === null)) {
            throw ValidationException::withMessages(['compromiso' => ['Para un compromiso indica el monto y la fecha.']]);
        }
        if ($fecha && $fecha < now()->toDateString()) {
            throw ValidationException::withMessages(['fecha' => ['La fecha del compromiso debe ser hoy o futura.']]);
        }

        return DB::transaction(function () use ($apoderadoId, $canal, $resultado, $detalle, $monto, $fecha, $usuarioId) {
            $inst = ContextoInstitucion::requerido();
            $compromiso = null;
            if ($monto !== null) {
                DB::table('compromisos_pago')->where('apoderado_id', $apoderadoId)->where('estado', 'vigente')->update(['estado' => 'incumplido']);
                $compromiso = (int) DB::table('compromisos_pago')->insertGetId(['institucion_id' => $inst, 'apoderado_id' => $apoderadoId, 'monto' => round($monto, 2), 'fecha_compromiso' => $fecha]);
            }
            $id = (int) DB::table('gestiones_cobranza')->insertGetId(['institucion_id' => $inst, 'apoderado_id' => $apoderadoId, 'canal' => $canal, 'resultado' => mb_substr($resultado, 0, 200),
                'detalle' => $detalle, 'realizado_por' => $usuarioId, 'compromiso_pago_id' => $compromiso]);
            $this->auditoria->registrar('gestion_cobranza', 'gestiones_cobranza', $id, null, ['apoderado_id' => $apoderadoId, 'compromiso' => $monto]);

            return $id;
        });
    }

    /** Recordatorio a las familias de un tramo (app y correo; WhatsApp si se elige). */
    public function recordatorio(?string $tramo, array $canales, int $usuarioId): array
    {
        $familias = collect($this->familias($tramo))->filter(fn ($f) => $f['user_id']);
        $colegio = DB::table('instituciones')->where('id', ContextoInstitucion::requerido())->value('nombre');
        $enviados = 0;
        foreach ($familias as $f) {
            $r = $this->notificador->enviar([(int) $f['user_id']], $canales, 'pagos', 'Recordatorio de pago', 'Tiene una deuda vencida de S/ '.number_format($f['deuda'], 2)." con {$colegio}. Puede pagar en línea desde la app o en caja.", ['pantalla' => 'pagos']);
            $enviados += array_sum($r) > 0 ? 1 : 0;
            DB::table('gestiones_cobranza')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'apoderado_id' => $f['apoderado_id'], 'canal' => 'recordatorio', 'resultado' => 'Recordatorio masivo ('.implode(', ', $canales).')', 'realizado_por' => $usuarioId]);
        }

        return ['familias' => $familias->count(), 'enviados' => $enviados];
    }
}
