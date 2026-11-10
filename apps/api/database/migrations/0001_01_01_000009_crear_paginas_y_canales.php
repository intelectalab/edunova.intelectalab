<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 11 · Páginas públicas y canales (funcionalidades, demo largo, blog, FAQ, chatbot e
 * integraciones). Carga database/sql/cms/07_cms_paginas_y_canales.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/07_cms_paginas_y_canales.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS cms.chatbot_un_flujo_activo;
            DELETE FROM cms.secciones WHERE clave IN ('_faq', '_chatbot');
            ALTER TABLE cms.blog_wp_cache DROP COLUMN IF EXISTS actualizado_wp, DROP COLUMN IF EXISTS contenido;
            ALTER TABLE cms.leads DROP CONSTRAINT IF EXISTS leads_urgencia, DROP COLUMN IF EXISTS urgencia, DROP COLUMN IF EXISTS sistema_actual;
            ALTER TABLE cms.videos DROP COLUMN IF EXISTS modulo_clave;
            ALTER TABLE cms.modulos_web DROP COLUMN IF EXISTS beneficios, DROP COLUMN IF EXISTS descripcion_larga;
            SQL);
    }
};
