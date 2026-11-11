<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 18 · Cobro mensual del SaaS y registro de notas.
 *
 * - EDUN-27 · El cobro mensual guarda su detalle (líneas), el comprobante de Edunova y su respuesta.
 * - EDUN-93 · La nota de una actividad usa la misma escala que la nota oficial.
 * - EDUN-98 · La reapertura de un periodo guarda quién, cuándo y por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE facturas_saas
              ADD COLUMN detalle jsonb NOT NULL DEFAULT '[]'::jsonb,
              ADD COLUMN comprobante_tipo char(2),
              ADD COLUMN respuesta jsonb,
              ADD COLUMN emitida_at timestamptz,
              ADD COLUMN pagada_at timestamptz;

            ALTER TABLE notas_actividad ADD CONSTRAINT notas_actividad_valor
              CHECK (valor in ('AD','A','B','C') or valor ~ '^(0[0-9]|1[0-9]|20)$');

            ALTER TABLE cierres_periodo
              ADD COLUMN reabierto_por bigint,
              ADD COLUMN reabierto_at timestamptz,
              ADD COLUMN motivo_reapertura varchar(500),
              ADD COLUMN recordatorio_at timestamptz;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cierres_periodo DROP COLUMN IF EXISTS reabierto_por, DROP COLUMN IF EXISTS reabierto_at,
              DROP COLUMN IF EXISTS motivo_reapertura, DROP COLUMN IF EXISTS recordatorio_at;
            ALTER TABLE notas_actividad DROP CONSTRAINT IF EXISTS notas_actividad_valor;
            ALTER TABLE facturas_saas DROP COLUMN IF EXISTS detalle, DROP COLUMN IF EXISTS comprobante_tipo, DROP COLUMN IF EXISTS respuesta,
              DROP COLUMN IF EXISTS emitida_at, DROP COLUMN IF EXISTS pagada_at;
            SQL);
    }
};
