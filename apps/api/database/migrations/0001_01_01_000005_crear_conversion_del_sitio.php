<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 · Demo con calendario (EDUN-267), políticas (EDUN-287) y consentimientos de
 * formularios y cookies (EDUN-291, EDUN-306). Carga database/sql/cms/03_cms_conversion.sql,
 * que es idempotente: se puede aplicar sobre una base que ya tiene el esquema cms.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/03_cms_conversion.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS leads_fecha_demo ON cms.leads;
            DROP FUNCTION IF EXISTS cms.validar_fecha_demo();
            ALTER TABLE cms.consentimientos DROP CONSTRAINT IF EXISTS consentimientos_categorias;
            DROP INDEX IF EXISTS cms.politicas_vigencia;
            ALTER TABLE cms.politicas DROP COLUMN IF EXISTS borrador;
            SQL);
    }
};
