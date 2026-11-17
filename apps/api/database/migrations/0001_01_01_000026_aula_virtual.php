<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S27–S29 · Aula virtual a medida (EDUN-123 a 142).
 *
 * - Tareas y cuestionarios en borrador hasta publicarlos; la tarea puede llevar un archivo.
 * - Clases en vivo: marca del recordatorio enviado (aul:recordatorios).
 * - Avisos y clases: aviso a la familia y a los alumnos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE aula_tareas ADD COLUMN archivo_id bigint, ADD COLUMN creado_por bigint;
            ALTER TABLE aula_cuestionarios ADD COLUMN publicado_at timestamptz, ADD COLUMN creado_por bigint;
            ALTER TABLE aula_clases_vivo ADD COLUMN recordado_at timestamptz, ADD COLUMN creado_por bigint;
            ALTER TABLE aula_entregas ADD COLUMN archivo_nombre varchar(255);
            CREATE INDEX ON aula_accesos (institucion_id, aula_curso_id, user_id);
            CREATE INDEX ON aula_progreso (institucion_id, alumno_id);
            CREATE INDEX ON aula_intentos (institucion_id, aula_cuestionario_id, alumno_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE aula_entregas DROP COLUMN IF EXISTS archivo_nombre;
            ALTER TABLE aula_clases_vivo DROP COLUMN IF EXISTS recordado_at, DROP COLUMN IF EXISTS creado_por;
            ALTER TABLE aula_cuestionarios DROP COLUMN IF EXISTS publicado_at, DROP COLUMN IF EXISTS creado_por;
            ALTER TABLE aula_tareas DROP COLUMN IF EXISTS archivo_id, DROP COLUMN IF EXISTS creado_por;
        SQL);
    }
};
