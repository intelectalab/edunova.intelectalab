<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S31 · Seguimiento de la movilidad y salud (EDUN-164, 166, 167, 177, 178).
 *
 * - Aviso de cercanía: una sola vez por recorrido y paradero.
 * - Alerta por pérdida de señal (más de 3 minutos) una vez por corte; se limpia al volver la señal.
 * - Campañas de salud: descripción y secciones; la autorización se firma como cualquier autorización.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE mov_recorridos ADD COLUMN alerta_senal_at timestamptz;

            CREATE TABLE mov_avisos_cercania (
              id              bigserial PRIMARY KEY,
              institucion_id  bigint NOT NULL REFERENCES instituciones(id),
              mov_recorrido_id bigint NOT NULL REFERENCES mov_recorridos(id) ON DELETE CASCADE,
              mov_paradero_id bigint NOT NULL REFERENCES mov_paraderos(id) ON DELETE CASCADE,
              avisado_at      timestamptz NOT NULL DEFAULT now(),
              UNIQUE (mov_recorrido_id, mov_paradero_id)
            );
            ALTER TABLE mov_avisos_cercania ENABLE ROW LEVEL SECURITY;
            ALTER TABLE mov_avisos_cercania FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON mov_avisos_cercania USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON mov_avisos_cercania TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT, UPDATE, DELETE ON mov_avisos_cercania TO edunova_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON mov_avisos_cercania TO edunova_plataforma;
            GRANT USAGE ON SEQUENCE mov_avisos_cercania_id_seq TO edunova_app, edunova_plataforma;

            ALTER TABLE salud.campanas ADD COLUMN descripcion text, ADD COLUMN creado_por bigint;

            -- Dos posiciones en el mismo segundo (lote sin señal): gana la última que llega.
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
               WHERE id = NEW.mov_recorrido_id AND estado = 'en_curso' AND (ultima_posicion_at IS NULL OR ultima_posicion_at <= NEW.registrado_at);
              RETURN NEW;
            END $f$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE salud.campanas DROP COLUMN IF EXISTS descripcion, DROP COLUMN IF EXISTS creado_por;
            DROP TABLE IF EXISTS mov_avisos_cercania;
            ALTER TABLE mov_recorridos DROP COLUMN IF EXISTS alerta_senal_at;
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
};
