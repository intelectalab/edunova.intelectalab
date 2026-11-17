<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S25 · Trámites en línea (EDUN-113 a 118).
 *
 * - Plantillas versionadas: cada versión es una fila; plantilla_origen_id agrupa las versiones y
 *   los documentos ya emitidos siguen apuntando a la versión con la que se emitieron.
 * - Comentario de la familia al pedir o al responder una observación.
 * - Documento emitido: trámite de origen y motivo de anulación (anular no borra).
 * - Firma del director: imagen opcional en su ficha de personal, se imprime al firmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE plantillas_documento ADD COLUMN plantilla_origen_id bigint, ADD COLUMN editado_por bigint;
            UPDATE plantillas_documento SET plantilla_origen_id = id WHERE plantilla_origen_id IS NULL;
            CREATE INDEX ON plantillas_documento (institucion_id, plantilla_origen_id);

            ALTER TABLE tramites ADD COLUMN comentario varchar(500), ADD COLUMN atendido_por bigint;
            CREATE INDEX ON tramites (institucion_id, estado);

            ALTER TABLE documentos_emitidos ADD COLUMN tramite_id bigint, ADD COLUMN anulado_motivo varchar(300), ADD COLUMN anulado_por bigint;
            CREATE INDEX ON documentos_emitidos (institucion_id, tramite_id);

            ALTER TABLE personal ADD COLUMN firma_archivo_id bigint;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE personal DROP COLUMN IF EXISTS firma_archivo_id;
            ALTER TABLE documentos_emitidos DROP COLUMN IF EXISTS tramite_id, DROP COLUMN IF EXISTS anulado_motivo, DROP COLUMN IF EXISTS anulado_por;
            ALTER TABLE tramites DROP COLUMN IF EXISTS comentario, DROP COLUMN IF EXISTS atendido_por;
            ALTER TABLE plantillas_documento DROP COLUMN IF EXISTS plantilla_origen_id, DROP COLUMN IF EXISTS editado_por;
        SQL);
    }
};
