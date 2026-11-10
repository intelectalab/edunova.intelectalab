<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 8 · Envío de leads al CRM (EDUN-275), exportar y sincronizar (EDUN-282), popup de
 * salida (EDUN-271) y gestión de reclamaciones (EDUN-302).
 * Carga database/sql/cms/05_cms_crm_y_reclamaciones.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/05_cms_crm_y_reclamaciones.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS cms.reclamaciones_estado_vence;
            DROP TRIGGER IF EXISTS reclamaciones_estado ON cms.reclamaciones;
            DROP FUNCTION IF EXISTS cms.validar_estado_reclamo();
            ALTER TABLE cms.leads DROP CONSTRAINT IF EXISTS leads_popup_sin_demo;
            DROP INDEX IF EXISTS cms.leads_pendientes_proximo;
            ALTER TABLE cms.leads_pendientes DROP CONSTRAINT IF EXISTS leads_pendientes_lead_unico;
            ALTER TABLE cms.leads DROP COLUMN IF EXISTS crm_ref, DROP COLUMN IF EXISTS crm_enviado_en;
            SQL);
    }
};
