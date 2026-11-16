<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-214 / EDUN-176 · Salidas verificadas: quién recogió al alumno (nombre y documento), también
 * cuando es el apoderado (por_autorizado_id solo apunta a las personas autorizadas). El índice
 * evita registrar dos veces la misma salida enviada sin conexión.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE por_salidas
              ADD COLUMN recogido_por varchar(200),
              ADD COLUMN recogido_documento varchar(15);
            CREATE INDEX IF NOT EXISTS por_salidas_alumno_salio ON por_salidas (alumno_id, salio_at);
            CREATE INDEX IF NOT EXISTS por_salidas_fecha ON por_salidas (institucion_id, fecha);

            -- EDUN-165 · Posiciones guardadas sin señal: llegan con el recorrido ya finalizado. Se
            -- aceptan solo si se tomaron mientras estaba en curso (entre el inicio y el fin); nunca
            -- después del fin (privacidad de la ubicación).
            CREATE OR REPLACE FUNCTION tg_posicion_recorrido_en_curso() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM mov_recorridos r
                 WHERE r.id = NEW.mov_recorrido_id
                   AND (r.estado = 'en_curso'
                        OR (r.estado = 'finalizado' AND r.iniciado_at IS NOT NULL AND r.finalizado_at IS NOT NULL
                            AND NEW.registrado_at >= r.iniciado_at - interval '1 minute' AND NEW.registrado_at <= r.finalizado_at))
              ) THEN
                RAISE EXCEPTION 'Solo se registran posiciones de un recorrido en curso' USING ERRCODE = 'check_violation';
              END IF;
              UPDATE mov_recorridos SET ultima_latitud = NEW.latitud, ultima_longitud = NEW.longitud, ultima_posicion_at = NEW.registrado_at
               WHERE id = NEW.mov_recorrido_id AND estado = 'en_curso' AND (ultima_posicion_at IS NULL OR ultima_posicion_at < NEW.registrado_at);
              RETURN NEW;
            END $f$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tg_posicion_recorrido_en_curso() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM mov_recorridos r WHERE r.id = NEW.mov_recorrido_id AND r.estado = 'en_curso') THEN
                RAISE EXCEPTION 'Solo se registran posiciones de un recorrido en curso' USING ERRCODE = 'check_violation';
              END IF;
              UPDATE mov_recorridos SET ultima_latitud = NEW.latitud, ultima_longitud = NEW.longitud, ultima_posicion_at = NEW.registrado_at
               WHERE id = NEW.mov_recorrido_id AND (ultima_posicion_at IS NULL OR ultima_posicion_at < NEW.registrado_at);
              RETURN NEW;
            END $f$;
            DROP INDEX IF EXISTS por_salidas_fecha;
            DROP INDEX IF EXISTS por_salidas_alumno_salio;
            ALTER TABLE por_salidas DROP COLUMN IF EXISTS recogido_documento, DROP COLUMN IF EXISTS recogido_por;
        SQL);
    }
};
