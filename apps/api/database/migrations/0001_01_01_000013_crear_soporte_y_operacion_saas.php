<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 15 · Soporte con SLA y operación del SaaS (fin de la fase 1).
 *
 * - EDUN-29 / EDUN-31 / EDUN-33 · Tickets: pausa del SLA mientras se espera al colegio, alerta
 *   al 75 % del plazo, escalamiento al vencer, cierre y adjuntos.
 * - EDUN-28 · Suspensión programada por falta de pago con avisos al promotor 7 y 3 días antes.
 * - EDUN-34 · Exportaciones completas de un colegio (ZIP con JSON, Excel y archivos).
 * - El director y el promotor piden soporte desde el colegio (pla.tickets.ver / crear).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tickets_soporte
              ADD COLUMN pausado_desde timestamptz,
              ADD COLUMN alerta_75_at timestamptz,
              ADD COLUMN escalado_at timestamptz,
              ADD COLUMN cerrado_at timestamptz;

            CREATE TABLE ticket_adjuntos (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              ticket_id bigint NOT NULL,
              archivo_id bigint NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (institucion_id, id),
              FOREIGN KEY (institucion_id, ticket_id) REFERENCES tickets_soporte (institucion_id, id) ON DELETE CASCADE,
              FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id)
            );
            ALTER TABLE ticket_adjuntos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE ticket_adjuntos FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON ticket_adjuntos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON ticket_adjuntos TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT ON ticket_adjuntos TO edunova_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_adjuntos TO edunova_plataforma;

            ALTER TABLE instituciones
              ADD COLUMN suspension_programada date,
              ADD COLUMN suspension_motivo varchar(250),
              ADD COLUMN avisos_suspension jsonb NOT NULL DEFAULT '{}'::jsonb;

            CREATE TABLE exportaciones (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','procesando','lista','fallida')),
              motivo varchar(20) NOT NULL DEFAULT 'portabilidad' CHECK (motivo in ('portabilidad','baja','respaldo')),
              ruta varchar(300),
              bytes bigint,
              resumen jsonb,
              error text,
              solicitado_por bigint REFERENCES users(id),
              completado_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER trg_exportaciones_upd BEFORE UPDATE ON exportaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            -- Solo la consola de la plataforma las usa.
            ALTER TABLE exportaciones ENABLE ROW LEVEL SECURITY;
            ALTER TABLE exportaciones FORCE ROW LEVEL SECURITY;
            CREATE POLICY plataforma ON exportaciones TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT, UPDATE, DELETE ON exportaciones TO edunova_plataforma;
            SQL);

        // Permisos de soporte para el colegio (en bases nuevas los vuelve a cargar el seeder).
        DB::unprepared(file_get_contents(database_path('sql/04_permisos_adicionales.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM rol_permiso WHERE rol_id IN (SELECT id FROM roles WHERE codigo IN ('promotor','director'))
              AND permiso_id IN (SELECT id FROM permisos WHERE codigo IN ('pla.tickets.ver','pla.tickets.crear'));
            DROP TABLE IF EXISTS exportaciones;
            ALTER TABLE instituciones DROP COLUMN IF EXISTS avisos_suspension, DROP COLUMN IF EXISTS suspension_motivo, DROP COLUMN IF EXISTS suspension_programada;
            DROP TABLE IF EXISTS ticket_adjuntos;
            ALTER TABLE tickets_soporte DROP COLUMN IF EXISTS cerrado_at, DROP COLUMN IF EXISTS escalado_at, DROP COLUMN IF EXISTS alerta_75_at, DROP COLUMN IF EXISTS pausado_desde;
            SQL);
    }
};
