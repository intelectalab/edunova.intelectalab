<?php

namespace App\Modulos\Compartido;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Colegio activo de la transacción en curso. Lo lee de app.institucion_id, que es lo
 * mismo que usa el RLS de PostgreSQL: así el código y la base nunca discrepan.
 * Lo fija el middleware de colegio activo (EDUN-35) al inicio de cada petición.
 */
class ContextoInstitucion
{
    public static function fijar(?int $institucionId): void
    {
        DB::select("select set_config('app.institucion_id', ?, true)", [$institucionId === null ? '' : (string) $institucionId]);
    }

    public static function actual(): ?int
    {
        $valor = DB::selectOne("select nullif(current_setting('app.institucion_id', true), '') as id")->id;

        return $valor === null ? null : (int) $valor;
    }

    public static function requerido(): int
    {
        return self::actual() ?? throw new RuntimeException('No hay colegio activo en esta transacción.');
    }
}
