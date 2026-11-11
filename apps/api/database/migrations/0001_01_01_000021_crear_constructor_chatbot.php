<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 24 · Constructor visual de flujos del chatbot e historial de sesiones.
 * Carga database/sql/cms/09_cms_constructor_chatbot.sql (idempotente).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/cms/09_cms_constructor_chatbot.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS cms.chatbot_sesiones_fecha;
            ALTER TABLE cms.chatbot_sesiones DROP CONSTRAINT IF EXISTS chatbot_sesiones_resultado;
            ALTER TABLE cms.chatbot_sesiones DROP COLUMN IF EXISTS pagina, DROP COLUMN IF EXISTS resultado;
            SQL);
    }
};
