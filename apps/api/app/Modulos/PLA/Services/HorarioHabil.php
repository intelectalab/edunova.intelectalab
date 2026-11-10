<?php

namespace App\Modulos\PLA\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-31 · Horario hábil de la mesa de ayuda: lunes a viernes de 08:00 a 18:00 (hora de Lima),
 * sin feriados nacionales (cms.feriados_nacionales). Con él se calculan los vencimientos del SLA
 * y el tiempo que estuvo en pausa un ticket.
 */
class HorarioHabil
{
    public const ZONA = 'America/Lima';

    public const INICIO = 8;

    public const FIN = 18;

    /** @var array<string, true>|null */
    private ?array $feriados = null;

    public function __construct(private ?string $conexion = null) {}

    /** Fecha y hora que resulta de sumar minutos hábiles a «desde». */
    public function sumar(Carbon $desde, int $minutos): Carbon
    {
        $t = $desde->copy()->timezone(self::ZONA);
        $t = $this->alInicioHabil($t);
        while ($minutos > 0) {
            $finDia = $t->copy()->setTime(self::FIN, 0);
            $disponibles = (int) $t->diffInMinutes($finDia);
            if ($minutos <= $disponibles) {
                return $t->addMinutes($minutos)->timezone(config('app.timezone'));
            }
            $minutos -= $disponibles;
            $t = $this->alInicioHabil($t->copy()->addDay()->setTime(self::INICIO, 0));
        }

        return $t->timezone(config('app.timezone'));
    }

    /** Minutos hábiles entre dos momentos (0 si «hasta» es anterior). */
    public function minutosEntre(Carbon $desde, Carbon $hasta): int
    {
        $a = $desde->copy()->timezone(self::ZONA);
        $b = $hasta->copy()->timezone(self::ZONA);
        if ($b->lessThanOrEqualTo($a)) {
            return 0;
        }
        $total = 0;
        $dia = $a->copy()->startOfDay();
        while ($dia->lessThanOrEqualTo($b)) {
            if ($this->esHabil($dia)) {
                $ini = $dia->copy()->setTime(self::INICIO, 0)->max($a);
                $fin = $dia->copy()->setTime(self::FIN, 0)->min($b);
                if ($fin->greaterThan($ini)) {
                    $total += (int) $ini->diffInMinutes($fin);
                }
            }
            $dia->addDay();
        }

        return $total;
    }

    public function esHabil(Carbon $dia): bool
    {
        return $dia->isWeekday() && ! isset($this->feriados()[$dia->toDateString()]);
    }

    /** Primer momento hábil desde «t» (si está fuera de horario, el siguiente inicio de jornada). */
    private function alInicioHabil(Carbon $t): Carbon
    {
        while (true) {
            if ($this->esHabil($t)) {
                if ($t->hour < self::INICIO) {
                    return $t->setTime(self::INICIO, 0);
                }
                if ($t->hour < self::FIN) {
                    return $t;
                }
            }
            $t = $t->addDay()->setTime(self::INICIO, 0);
        }
    }

    /** @return array<string, true> */
    private function feriados(): array
    {
        return $this->feriados ??= DB::connection($this->conexion)->table('cms.feriados_nacionales')->pluck('fecha')
            ->mapWithKeys(fn ($f) => [substr((string) $f, 0, 10) => true])->all();
    }
}
