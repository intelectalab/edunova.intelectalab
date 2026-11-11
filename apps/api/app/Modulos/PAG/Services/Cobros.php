<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\ADM\Services\Ratificacion;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aplicación del dinero a los cargos (la usan la caja, la pasarela y, luego, la recaudación).
 *
 * - Un pago se reparte entre cargos (pago_aplicaciones) y puede combinar medios (pago_medios).
 * - El cargo pasa a «parcial» o «pagado» según lo aplicado; nunca se paga más que su saldo.
 * - Anular un pago revierte lo aplicado (lo exige la nota de crédito, EDUN-78).
 * - La deuda nunca bloquea notas ni libretas: aquí solo se mueve dinero.
 */
class Cobros
{
    public const MEDIOS = ['efectivo' => 'Efectivo', 'yape' => 'Yape', 'plin' => 'Plin', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia'];

    public function __construct(private Auditoria $auditoria) {}

    public static function saldoSql(string $alias = 'c'): string
    {
        return "({$alias}.monto - {$alias}.descuento + {$alias}.mora - {$alias}.pagado)";
    }

    /** Cargos con saldo de los alumnos indicados (pendientes o parciales), del más antiguo al más nuevo. */
    public function pendientes(array $alumnoIds, bool $soloEmitidos = false): array
    {
        return DB::table('cargos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')
            ->whereIn('c.alumno_id', $alumnoIds)->whereIn('c.estado', ['pendiente', 'parcial'])
            ->when($soloEmitidos, fn ($q) => $q->where('c.emision', '<=', now()->toDateString()))
            ->orderBy('c.vencimiento')->orderBy('c.id')
            ->get(['c.id', 'c.alumno_id', 'c.descripcion', 'c.emision', 'c.vencimiento', 'c.monto', 'c.descuento', 'c.mora', 'c.pagado', 'c.estado', 'c.codigo_recaudacion',
                'cp.afecto_igv', 'cp.tipo_comprobante', DB::raw('a.nombres as alumno'), DB::raw(self::saldoSql().' as saldo')])
            ->map(fn ($c) => (array) $c + ['vencido' => $c->vencimiento < now()->toDateString()])
            ->map(fn ($c) => array_merge($c, array_map('floatval', array_intersect_key($c, array_flip(['monto', 'descuento', 'mora', 'pagado', 'saldo'])))))
            ->all();
    }

    /**
     * Registra un pago y lo aplica a los cargos.
     *
     * @param  list<array{id: int, monto: float|int|string}>  $cargos
     * @param  list<array{medio: string, monto: float|int|string, referencia?: string|null}>  $medios  vacío = un solo medio ($medio)
     * @param  array{caja_sesion_id?: int|null, pasarela_transaccion_id?: int|null, apoderado_id?: int|null, referencia?: string|null, medio?: string}  $origen
     */
    public function registrar(array $cargos, array $medios, array $origen, ?int $usuarioId): int
    {
        if (! $cargos) {
            throw ValidationException::withMessages(['cargos' => ['Elige al menos un cargo.']]);
        }
        $ids = array_map(fn ($c) => (int) $c['id'], $cargos);
        $filas = DB::table('cargos as c')->whereIn('c.id', $ids)->lockForUpdate()->get(['c.id', 'c.estado', 'c.descripcion', DB::raw(self::saldoSql().' as saldo')])->keyBy('id');
        $total = 0.0;
        foreach ($cargos as $i => $c) {
            $f = $filas->get((int) $c['id']);
            $monto = round((float) $c['monto'], 2);
            if (! $f || ! in_array($f->estado, ['pendiente', 'parcial'], true)) {
                throw ValidationException::withMessages(["cargos.$i.id" => ['Ese cargo ya no tiene saldo por cobrar.']]);
            }
            if ($monto <= 0 || $monto > round((float) $f->saldo, 2) + 0.001) {
                throw ValidationException::withMessages(["cargos.$i.monto" => ["{$f->descripcion}: el monto debe ser mayor que cero y no superar el saldo (S/ ".number_format((float) $f->saldo, 2).').']]);
            }
            $total += $monto;
        }
        $total = round($total, 2);
        if ($medios) {
            $suma = round(array_sum(array_map(fn ($m) => (float) $m['monto'], $medios)), 2);
            if (abs($suma - $total) > 0.001) {
                throw ValidationException::withMessages(['medios' => ['Los medios de pago suman S/ '.number_format($suma, 2).' y el cobro es de S/ '.number_format($total, 2).'.']]);
            }
        }
        $medio = $medios ? (count($medios) === 1 ? $medios[0]['medio'] : 'mixto') : ($origen['medio'] ?? 'efectivo');
        $inst = ContextoInstitucion::requerido();

        $pago = (int) DB::table('pagos')->insertGetId([
            'institucion_id' => $inst, 'apoderado_id' => $origen['apoderado_id'] ?? null, 'medio' => $medio, 'monto' => $total,
            'referencia' => $origen['referencia'] ?? null, 'caja_sesion_id' => $origen['caja_sesion_id'] ?? null,
            'pasarela_transaccion_id' => $origen['pasarela_transaccion_id'] ?? null, 'registrado_por' => $usuarioId,
        ]);
        foreach ($medios as $m) {
            DB::table('pago_medios')->insert(['institucion_id' => $inst, 'pago_id' => $pago, 'medio' => $m['medio'], 'monto' => round((float) $m['monto'], 2), 'referencia' => $m['referencia'] ?? null]);
        }
        foreach ($cargos as $c) {
            $monto = round((float) $c['monto'], 2);
            DB::table('pago_aplicaciones')->insert(['institucion_id' => $inst, 'pago_id' => $pago, 'cargo_id' => (int) $c['id'], 'monto' => $monto]);
            DB::table('cargos')->where('id', (int) $c['id'])->update([
                'pagado' => DB::raw("pagado + $monto"),
                'estado' => DB::raw("CASE WHEN pagado + $monto >= monto - descuento + mora - 0.001 THEN 'pagado' ELSE 'parcial' END"),
            ]);
        }
        $this->auditoria->registrar('pago_registrado', 'pagos', $pago, null, ['monto' => $total, 'medio' => $medio, 'cargos' => $ids]);
        // EDUN-55 · Pagar la matrícula de una ratificación la confirma.
        Ratificacion::alPagar($ids);

        return $pago;
    }

    /** Revierte lo aplicado por un pago y lo deja anulado. */
    public function anular(int $pagoId, string $motivo, ?int $usuarioId): void
    {
        $pago = DB::table('pagos')->lockForUpdate()->find($pagoId);
        abort_if(! $pago, 404);
        if ($pago->estado === 'anulado') {
            throw ValidationException::withMessages(['pago' => ['El pago ya estaba anulado.']]);
        }
        foreach (DB::table('pago_aplicaciones')->where('pago_id', $pagoId)->get() as $a) {
            $m = (float) $a->monto;
            DB::table('cargos')->where('id', $a->cargo_id)->update([
                'pagado' => DB::raw("greatest(pagado - $m, 0)"),
                'estado' => DB::raw("CASE WHEN estado IN ('anulado','exonerado') THEN estado WHEN pagado - $m <= 0.001 THEN 'pendiente' ELSE 'parcial' END"),
            ]);
        }
        DB::table('pagos')->where('id', $pagoId)->update(['estado' => 'anulado', 'anulado_at' => now(), 'anulado_por' => $usuarioId, 'motivo_anulacion' => $motivo]);
        $this->auditoria->registrar('pago_anulado', 'pagos', $pagoId, ['estado' => 'valido'], ['estado' => 'anulado', 'motivo' => $motivo]);
    }
}
