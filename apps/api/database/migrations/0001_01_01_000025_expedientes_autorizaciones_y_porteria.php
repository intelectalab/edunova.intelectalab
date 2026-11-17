<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S26 · Expedientes, documentos institucionales, autorizaciones firmadas y portería.
 *
 * - Salida anticipada pedida por la familia (EDUN-189): quién la pidió, quién la autorizó, la
 *   respuesta del colegio y quién recoge (un apoderado o una persona autorizada).
 * - Secretaría y dirección autorizan salidas anticipadas (POR-03); dirección también ve las visitas.
 * - Documentos institucionales: quién publicó cada versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE por_salidas
              ADD COLUMN solicitado_por bigint,
              ADD COLUMN autorizado_por bigint,
              ADD COLUMN respuesta varchar(300),
              ADD COLUMN apoderado_id bigint;
            CREATE INDEX ON por_salidas (institucion_id, fecha, estado);

            ALTER TABLE documentos_institucionales ADD COLUMN publicado_por bigint, ADD COLUMN vigente boolean NOT NULL DEFAULT true;
            ALTER TABLE autorizaciones ADD COLUMN cerrada_at timestamptz;
        SQL);
        // Permisos nuevos (en bases nuevas los vuelve a cargar el seeder, cuando los roles ya existen).
        DB::unprepared(file_get_contents(database_path('sql/04_permisos_adicionales.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DELETE FROM rol_permiso rp USING roles r, permisos p
              WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
                AND ((r.codigo = 'secretaria' AND p.codigo IN ('por.salidas.ver', 'por.salidas.autorizar'))
                  OR (r.codigo = 'director' AND p.codigo IN ('por.salidas.autorizar', 'por.visitas.ver')));
            ALTER TABLE autorizaciones DROP COLUMN IF EXISTS cerrada_at;
            ALTER TABLE documentos_institucionales DROP COLUMN IF EXISTS publicado_por, DROP COLUMN IF EXISTS vigente;
            ALTER TABLE por_salidas DROP COLUMN IF EXISTS solicitado_por, DROP COLUMN IF EXISTS autorizado_por, DROP COLUMN IF EXISTS respuesta, DROP COLUMN IF EXISTS apoderado_id;
        SQL);
    }
};
