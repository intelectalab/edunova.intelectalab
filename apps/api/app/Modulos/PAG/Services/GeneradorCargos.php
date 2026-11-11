<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-71 · PAG-H03 Cargos al matricular.
 *
 * Al pasar a «matriculado» se crean los cargos del cronograma del año (el del nivel o, si no hay,
 * el general): matrícula y cuota de ingreso siempre, pensiones desde el mes de la matrícula.
 * Se aplican los beneficios aprobados del alumno y cada cargo recibe su código de recaudación.
 * Es idempotente: una cuota ya cargada a la matrícula no se vuelve a crear.
 */
class GeneradorCargos
{
    /** Código de recaudación: id del cargo en 9 dígitos y un dígito verificador (módulo 10). */
    public static function codigo(int $cargoId): string
    {
        $base = str_pad((string) $cargoId, 9, '0', STR_PAD_LEFT);
        $suma = 0;
        foreach (array_reverse(str_split($base)) as $i => $d) {
            $n = (int) $d * ($i % 2 === 0 ? 2 : 1);
            $suma += intdiv($n, 10) + $n % 10;
        }

        return $base.((10 - $suma % 10) % 10);
    }

    /** @return array{creados: int, cronograma: bool} */
    public function generar(int $matriculaId): array
    {
        $m = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')
            ->where('m.id', $matriculaId)->first(['m.id', 'm.alumno_id', 'm.anio_escolar_id', 'm.fecha_matricula', 'm.estado', 'g.nivel']);
        if (! $m || $m->estado !== 'matriculado') {
            return ['creados' => 0, 'cronograma' => false];
        }
        $cronograma = self::cronogramaDe((int) $m->anio_escolar_id, $m->nivel);
        if (! $cronograma) {
            return ['creados' => 0, 'cronograma' => false];
        }
        $beneficios = $this->beneficios((int) $m->alumno_id, (int) $m->anio_escolar_id);
        $mesMatricula = substr((string) $m->fecha_matricula, 0, 7);
        $creados = 0;
        foreach (DB::table('cronograma_cuotas as cc')->join('conceptos_cobro as cp', 'cp.id', '=', 'cc.concepto_cobro_id')
            ->where('cc.cronograma_id', $cronograma)->orderBy('cc.numero')
            ->get(['cc.id', 'cc.concepto_cobro_id', 'cc.mes', 'cc.emision', 'cc.vencimiento', 'cc.monto', 'cp.nombre', 'cp.tipo']) as $c) {
            if ($c->tipo === 'pension' && $c->mes < $mesMatricula) {
                continue; // Ingresó a mitad de año: no se cobran las pensiones anteriores.
            }
            if (DB::table('cargos')->where('matricula_id', $matriculaId)->where('cronograma_cuota_id', $c->id)->exists()) {
                continue;
            }
            $id = DB::table('cargos')->insertGetId([
                'institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $m->alumno_id, 'matricula_id' => $matriculaId,
                'concepto_cobro_id' => $c->concepto_cobro_id, 'cronograma_cuota_id' => $c->id,
                'descripcion' => $c->tipo === 'pension' ? "{$c->nombre} ".Carbon::parse($c->mes.'-01')->locale('es')->translatedFormat('F Y') : $c->nombre,
                'emision' => $c->emision, 'vencimiento' => $c->vencimiento, 'monto' => $c->monto,
                'descuento' => self::descuento($beneficios, $c->tipo, (float) $c->monto),
            ]);
            DB::table('cargos')->where('id', $id)->update(['codigo_recaudacion' => self::codigo($id)]);
            $creados++;
        }

        return ['creados' => $creados, 'cronograma' => true];
    }

    /**
     * EDUN-72 · Recalcula el descuento de los cargos sin pagos del alumno en ese año.
     * Los cargos con pagos (parciales o totales), anulados o exonerados no se tocan.
     */
    public function recalcular(int $alumnoId, int $anioId): int
    {
        $beneficios = $this->beneficios($alumnoId, $anioId);
        $cambiados = 0;
        $cargos = DB::table('cargos as c')->join('matriculas as m', 'm.id', '=', 'c.matricula_id')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')
            ->where('c.alumno_id', $alumnoId)->where('m.anio_escolar_id', $anioId)->where('c.estado', 'pendiente')->where('c.pagado', 0)
            ->get(['c.id', 'c.monto', 'c.descuento', 'cp.tipo']);
        foreach ($cargos as $c) {
            $nuevo = self::descuento($beneficios, $c->tipo, (float) $c->monto);
            if (abs($nuevo - (float) $c->descuento) > 0.001) {
                DB::table('cargos')->where('id', $c->id)->update(['descuento' => $nuevo]);
                $cambiados++;
            }
        }

        return $cambiados;
    }

    public static function cronogramaDe(int $anioId, ?string $nivel): ?int
    {
        $id = DB::table('cronogramas')->where('anio_escolar_id', $anioId)
            ->where(fn ($q) => $q->where('nivel', $nivel)->orWhereNull('nivel'))->orderByRaw('nivel is null')->value('id');

        return $id ? (int) $id : null;
    }

    private function beneficios(int $alumnoId, int $anioId): Collection
    {
        return DB::table('alumno_beneficios as ab')->join('beneficios as b', 'b.id', '=', 'ab.beneficio_id')
            ->where('ab.alumno_id', $alumnoId)->where('ab.anio_escolar_id', $anioId)->where('ab.estado', 'aprobado')->where('b.activo', true)
            ->get(['b.tipo', 'b.valor', 'b.aplica_a']);
    }

    private static function descuento(Collection $beneficios, string $tipoConcepto, float $monto): float
    {
        $total = 0.0;
        foreach ($beneficios as $b) {
            if ($b->aplica_a === $tipoConcepto || $b->aplica_a === 'todos') {
                $total += $b->tipo === 'porcentaje' ? round($monto * (float) $b->valor / 100, 2) : (float) $b->valor;
            }
        }

        return round(min($total, $monto), 2);
    }
}
