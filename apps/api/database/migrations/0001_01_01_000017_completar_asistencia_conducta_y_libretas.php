<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 19 · Asistencia, conducta, tutoría, alertas, libretas, actas y recuperación.
 *
 * - EDUN-94 / EDUN-95 · Una asistencia diaria por alumno; la hora del registro en el teléfono
 *   decide los conflictos al sincronizar (gana la más reciente) y se marca el aviso al padre.
 * - EDUN-103 · Seguimiento de la alerta: quién la atiende y cuándo se cierra (y el estado
 *   «en_seguimiento» cabe en la columna).
 * - EDUN-105 · La nota de recuperación usa la escala oficial y guarda quién la registró.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE asistencias
              ADD COLUMN registrado_at timestamptz NOT NULL DEFAULT now(),
              ADD COLUMN notificado_at timestamptz;
            CREATE UNIQUE INDEX asistencias_diaria ON asistencias (alumno_id, fecha) WHERE horario_sesion_id IS NULL;

            -- El esquema del kit declara 'en_seguimiento' (14 letras) en un varchar(12).
            ALTER TABLE alertas_riesgo ALTER COLUMN estado TYPE varchar(15);
            ALTER TABLE alertas_riesgo
              ADD COLUMN atendida_por bigint,
              ADD COLUMN cerrada_at timestamptz;
            CREATE UNIQUE INDEX alertas_riesgo_abierta ON alertas_riesgo (alumno_id, motivo, coalesce(periodo_id, 0)) WHERE estado <> 'cerrada';

            ALTER TABLE evaluaciones_recuperacion
              ADD COLUMN registrado_por bigint,
              ADD CONSTRAINT evaluaciones_recuperacion_valor CHECK (valor is null or valor in ('AD','A','B','C') or valor ~ '^(0[0-9]|1[0-9]|20)$');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE evaluaciones_recuperacion DROP CONSTRAINT IF EXISTS evaluaciones_recuperacion_valor, DROP COLUMN IF EXISTS registrado_por;
            DROP INDEX IF EXISTS alertas_riesgo_abierta;
            ALTER TABLE alertas_riesgo DROP COLUMN IF EXISTS atendida_por, DROP COLUMN IF EXISTS cerrada_at;
            DROP INDEX IF EXISTS asistencias_diaria;
            ALTER TABLE asistencias DROP COLUMN IF EXISTS registrado_at, DROP COLUMN IF EXISTS notificado_at;
            SQL);
    }
};
