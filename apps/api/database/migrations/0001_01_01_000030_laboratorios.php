<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S35 · Laboratorios y talleres (EDUN-191 a 194).
 *
 * - Reactivos peligrosos: ubicación con llave (además de la hoja de seguridad).
 * - Préstamo de equipos: revisión al devolver.
 * - Incidencias: respuesta, quién la atiende y cierre.
 * - Reservas: una cancelada libera su horario (índice único solo de las vigentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE lab_materiales ADD COLUMN con_llave boolean NOT NULL DEFAULT false;
            ALTER TABLE lab_prestamos ADD COLUMN revision varchar(200), ADD COLUMN registrado_por bigint;
            ALTER TABLE lab_incidencias ADD COLUMN respuesta text, ADD COLUMN atendido_por bigint, ADD COLUMN cerrada_at timestamptz;
            CREATE INDEX ON lab_reservas (institucion_id, lab_espacio_id, fecha);
            -- Una reserva cancelada libera su horario: la regla «no dos veces a la misma hora» solo cuenta las vigentes.
            ALTER TABLE lab_reservas DROP CONSTRAINT IF EXISTS lab_reservas_lab_espacio_id_fecha_hora_inicio_key;
            CREATE UNIQUE INDEX lab_reservas_vigente_unica ON lab_reservas (lab_espacio_id, fecha, hora_inicio) WHERE estado <> 'cancelada';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS lab_reservas_vigente_unica;
            ALTER TABLE lab_reservas ADD CONSTRAINT lab_reservas_lab_espacio_id_fecha_hora_inicio_key UNIQUE (lab_espacio_id, fecha, hora_inicio);
            ALTER TABLE lab_incidencias DROP COLUMN IF EXISTS respuesta, DROP COLUMN IF EXISTS atendido_por, DROP COLUMN IF EXISTS cerrada_at;
            ALTER TABLE lab_prestamos DROP COLUMN IF EXISTS revision, DROP COLUMN IF EXISTS registrado_por;
            ALTER TABLE lab_materiales DROP COLUMN IF EXISTS con_llave;
        SQL);
    }
};
