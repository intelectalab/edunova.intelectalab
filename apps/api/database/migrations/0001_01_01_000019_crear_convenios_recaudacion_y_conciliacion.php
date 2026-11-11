<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 22 · Convenios, recaudación bancaria, conciliación y app del personal.
 *
 * - EDUN-84/85/86 · Firma del convenio (quién, cuándo y desde dónde) y recordatorio de cuota.
 * - EDUN-80 · La línea del banco guarda la operación y el pago que generó.
 * - EDUN-89 · Resultado de la conciliación de cada pago en línea con el reporte de abonos.
 * - EDUN-199 · La atención rápida de enfermería avisa al padre (ya existe la columna).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE convenios_fraccionamiento
              ADD COLUMN firmado_por bigint,
              ADD COLUMN firma_canal varchar(10) CHECK (firma_canal in ('app','papel')),
              ADD COLUMN firma_ip inet,
              ADD COLUMN incumplido_at timestamptz;
            ALTER TABLE convenio_cuotas ADD COLUMN recordatorio_at timestamptz;

            -- El esquema del kit declara 'sin_coincidencia' (16 letras) en un varchar(15).
            ALTER TABLE recaudacion_lineas ALTER COLUMN resultado TYPE varchar(20);
            ALTER TABLE recaudacion_lineas
              ADD COLUMN operacion varchar(30),
              ADD COLUMN pago_id bigint;

            ALTER TABLE pasarela_transacciones
              ADD COLUMN conciliacion varchar(20) CHECK (conciliacion in ('conciliado','monto_distinto','comision_distinta','no_abonado','sin_registro')),
              ADD COLUMN conciliado_at timestamptz,
              ADD COLUMN conciliacion_detalle varchar(300);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE pasarela_transacciones DROP COLUMN IF EXISTS conciliacion, DROP COLUMN IF EXISTS conciliado_at, DROP COLUMN IF EXISTS conciliacion_detalle;
            ALTER TABLE recaudacion_lineas DROP COLUMN IF EXISTS operacion, DROP COLUMN IF EXISTS pago_id;
            ALTER TABLE convenio_cuotas DROP COLUMN IF EXISTS recordatorio_at;
            ALTER TABLE convenios_fraccionamiento DROP COLUMN IF EXISTS firmado_por, DROP COLUMN IF EXISTS firma_canal, DROP COLUMN IF EXISTS firma_ip, DROP COLUMN IF EXISTS incumplido_at;
            SQL);
    }
};
