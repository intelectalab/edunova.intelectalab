<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-223 · Esquema «cms» del sitio público y del Panel CMS (28 tablas, reglas y RLS por rol).
 *
 * Carga database/sql/cms/01_cms_esquema.sql, que es la parte de estructura de
 * database/cms/cms_schema.sql del kit. Las semillas van aparte (CmsSeeder).
 * El contenido es de Edunova, no de cada colegio: sin institucion_id. La API fija
 * app.rol_cms y app.usuario_id en cada transacción; RLS decide el resto.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // migrate:fresh solo borra las tablas de «public».
        DB::unprepared('DROP SCHEMA IF EXISTS cms CASCADE;');
        DB::unprepared(file_get_contents(database_path('sql/cms/01_cms_esquema.sql')));

        // La app (edunova_app, sin bypass de RLS) y la consola interna usan el esquema.
        DB::unprepared(<<<'SQL'
            GRANT USAGE ON SCHEMA cms TO edunova_app, edunova_plataforma;
            GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA cms TO edunova_app, edunova_plataforma;
            GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA cms TO edunova_app, edunova_plataforma;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP SCHEMA IF EXISTS cms CASCADE;');
    }
};
