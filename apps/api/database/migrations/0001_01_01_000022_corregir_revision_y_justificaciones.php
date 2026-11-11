<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revisión S17–S24 y EDUN-96.
 *
 * - Pago en línea con excedente (dos órdenes del mismo cargo, o cobro en caja mientras la familia
 *   pagaba): lo que no se pudo aplicar queda anotado para devolverlo o aplicarlo después.
 * - EDUN-96 · Justificación de inasistencias: la familia la pide con el sustento (foto) y el
 *   tutor la acepta (F → J) o la rechaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE pasarela_transacciones
              ADD COLUMN excedente numeric(10,2) NOT NULL DEFAULT 0 CHECK (excedente >= 0),
              ADD COLUMN excedente_resuelto_at timestamptz,
              ADD COLUMN excedente_resuelto_por bigint,
              ADD COLUMN excedente_nota varchar(300);

            CREATE TABLE justificaciones_inasistencia (
              id              bigserial PRIMARY KEY,
              institucion_id  bigint      NOT NULL REFERENCES instituciones(id),
              alumno_id       bigint      NOT NULL REFERENCES alumnos(id),
              fecha_desde     date        NOT NULL,
              fecha_hasta     date        NOT NULL CHECK (fecha_hasta >= fecha_desde),
              motivo          varchar(500) NOT NULL,
              sustento_archivo_id bigint,
              solicitado_por  bigint      NOT NULL,
              estado          varchar(10) NOT NULL DEFAULT 'pendiente' CHECK (estado IN ('pendiente', 'aceptada', 'rechazada')),
              respuesta       varchar(300),
              resuelto_por    bigint,
              resuelto_at     timestamptz,
              dias_justificados integer   NOT NULL DEFAULT 0,
              created_at      timestamptz NOT NULL DEFAULT now(),
              CHECK (fecha_hasta - fecha_desde <= 31)
            );
            CREATE INDEX justificaciones_pendientes ON justificaciones_inasistencia (institucion_id, estado, created_at DESC);
            CREATE INDEX justificaciones_alumno ON justificaciones_inasistencia (alumno_id, fecha_desde);
            ALTER TABLE justificaciones_inasistencia ENABLE ROW LEVEL SECURITY;
            ALTER TABLE justificaciones_inasistencia FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON justificaciones_inasistencia USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON justificaciones_inasistencia TO edunova_plataforma USING (true) WITH CHECK (true);
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_app') THEN
                GRANT SELECT, INSERT, UPDATE ON justificaciones_inasistencia TO edunova_app;
                GRANT USAGE, SELECT ON SEQUENCE justificaciones_inasistencia_id_seq TO edunova_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_plataforma') THEN
                GRANT SELECT, INSERT, UPDATE ON justificaciones_inasistencia TO edunova_plataforma;
                GRANT USAGE, SELECT ON SEQUENCE justificaciones_inasistencia_id_seq TO edunova_plataforma;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS justificaciones_inasistencia;
            ALTER TABLE pasarela_transacciones DROP COLUMN excedente, DROP COLUMN excedente_resuelto_at, DROP COLUMN excedente_resuelto_por, DROP COLUMN excedente_nota;
            SQL);
    }
};
