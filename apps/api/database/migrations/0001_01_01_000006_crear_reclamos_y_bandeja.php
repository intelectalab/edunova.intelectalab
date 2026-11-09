<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7 · Bandeja de solicitudes (EDUN-278), Libro de Reclamaciones (EDUN-294) y su
 * seguimiento (EDUN-298). Carga database/sql/cms/04_cms_reclamos_y_bandeja.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/04_cms_reclamos_y_bandeja.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS leads_primer_contacto ON cms.leads;
            DROP FUNCTION IF EXISTS cms.marcar_primer_contacto();
            ALTER TABLE cms.leads DROP COLUMN IF EXISTS primer_contacto_en;
            DROP FUNCTION IF EXISTS cms.seguimiento_reclamo(text, text);
            ALTER TABLE cms.reclamaciones DROP CONSTRAINT IF EXISTS reclamaciones_apoderado, DROP CONSTRAINT IF EXISTS reclamaciones_expediente_formato,
              DROP COLUMN IF EXISTS menor_edad, DROP COLUMN IF EXISTS apoderado_nombre, DROP COLUMN IF EXISTS apoderado_doc, DROP COLUMN IF EXISTS ip;
            DROP FUNCTION IF EXISTS cms.sumar_dias_habiles(date, integer);
            DROP TABLE IF EXISTS cms.feriados_nacionales;
            SQL);
    }
};
