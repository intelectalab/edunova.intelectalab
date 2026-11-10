<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 14 · Familias, consentimientos y movimientos de matrícula.
 *
 * - EDUN-41 · Textos de consentimiento (Ley 29733) versionados por colegio y finalidad
 *   (datos personales, imagen). Cada consentimiento guarda la versión que aceptó el padre.
 * - EDUN-57 / EDUN-58 · Pendientes para la conciliación con el SIAGIE: cada retiro, traslado o
 *   cambio de sección deja un aviso hasta que la secretaría lo registra también en el SIAGIE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE consentimiento_textos (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              finalidad varchar(20) NOT NULL CHECK (finalidad in ('datos','imagen')),
              version varchar(20) NOT NULL,
              contenido text NOT NULL,
              publicado_at timestamptz NOT NULL DEFAULT now(),
              publicado_por bigint REFERENCES users(id),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (institucion_id, id),
              UNIQUE (institucion_id, finalidad, version)
            );
            CREATE TRIGGER trg_consentimiento_textos_upd BEFORE UPDATE ON consentimiento_textos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            ALTER TABLE consentimiento_textos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE consentimiento_textos FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON consentimiento_textos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON consentimiento_textos TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT ON consentimiento_textos TO edunova_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON consentimiento_textos TO edunova_plataforma;
            CREATE INDEX ON consentimientos (institucion_id, titular_tipo, titular_id, finalidad);

            CREATE TABLE siagie_pendientes (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              alumno_id bigint NOT NULL,
              matricula_id bigint NOT NULL,
              movimiento_id bigint,
              tipo varchar(20) NOT NULL CHECK (tipo in ('retiro','traslado_salida','cambio_seccion')),
              detalle varchar(250) NOT NULL,
              atendido_at timestamptz,
              atendido_por bigint REFERENCES users(id),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (institucion_id, id),
              FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id),
              FOREIGN KEY (institucion_id, matricula_id) REFERENCES matriculas (institucion_id, id) ON DELETE CASCADE,
              FOREIGN KEY (institucion_id, movimiento_id) REFERENCES movimientos_matricula (institucion_id, id)
            );
            CREATE INDEX ON siagie_pendientes (institucion_id) WHERE atendido_at IS NULL;
            CREATE TRIGGER trg_siagie_pendientes_upd BEFORE UPDATE ON siagie_pendientes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            ALTER TABLE siagie_pendientes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE siagie_pendientes FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON siagie_pendientes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON siagie_pendientes TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT, UPDATE ON siagie_pendientes TO edunova_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON siagie_pendientes TO edunova_plataforma;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS siagie_pendientes;
            DROP INDEX IF EXISTS consentimientos_institucion_id_titular_tipo_titular_id_finalidad_idx;
            DROP TABLE IF EXISTS consentimiento_textos;
            SQL);
    }
};
