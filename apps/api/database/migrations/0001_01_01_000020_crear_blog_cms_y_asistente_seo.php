<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 23 · Previsualización del blog en el CMS y asistente SEO con IA.
 * Carga database/sql/cms/08_cms_blog_y_asistente_seo.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/08_cms_blog_y_asistente_seo.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM cms.config WHERE clave = 'asistente_seo';
            DROP TABLE IF EXISTS cms.seo_sugerencias;
            ALTER TABLE cms.blog_wp_cache DROP COLUMN IF EXISTS revisado_por, DROP COLUMN IF EXISTS revisado_at, DROP COLUMN IF EXISTS oculto;
            SQL);
    }
};
