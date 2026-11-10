<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 10 · Panel CMS completo (editor de secciones, popup, configuración, logos, auditoría
 * y usuarios). Carga database/sql/cms/06_cms_panel_completo.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/06_cms_panel_completo.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS cms.usuarios_cms();
            DROP TRIGGER IF EXISTS versiones_podar ON cms.versiones;
            DROP FUNCTION IF EXISTS cms.podar_versiones();
            DROP TRIGGER IF EXISTS secciones_logos ON cms.secciones;
            DROP FUNCTION IF EXISTS cms.validar_seccion_logos();
            DELETE FROM cms.secciones WHERE clave IN ('_estructura', '_popup', '_config');
            SQL);
    }
};
