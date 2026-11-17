<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S33 · Biblioteca (EDUN-151 a 160).
 *
 * - Ejemplar: estante e inventario (última toma).
 * - Préstamo y reserva: marca del último aviso (vencido / disponible) para no repetirlo el mismo día.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bib_ejemplares ADD COLUMN estante varchar(40), ADD COLUMN inventariado_at timestamptz, ADD COLUMN baja_motivo varchar(200);
            ALTER TABLE bib_prestamos ADD COLUMN avisado_at date;
            ALTER TABLE bib_reservas ADD COLUMN avisado_at timestamptz, ADD COLUMN bib_ejemplar_id bigint;
            CREATE INDEX ON bib_prestamos (institucion_id, devuelto_at, vence);
            CREATE INDEX ON bib_ejemplares (institucion_id, codigo);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bib_reservas DROP COLUMN IF EXISTS avisado_at, DROP COLUMN IF EXISTS bib_ejemplar_id;
            ALTER TABLE bib_prestamos DROP COLUMN IF EXISTS avisado_at;
            ALTER TABLE bib_ejemplares DROP COLUMN IF EXISTS estante, DROP COLUMN IF EXISTS inventariado_at, DROP COLUMN IF EXISTS baja_motivo;
        SQL);
    }
};
