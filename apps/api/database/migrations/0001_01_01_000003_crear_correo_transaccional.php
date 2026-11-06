<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-208 · Correo transaccional: bitácora de envíos y lista de supresión.
 *
 * - correos_bitacora: cada correo queda registrado desde que entra a la cola hasta que sale
 *   (o falla). institucion_id es NULL en los correos de Edunova (sitio, reclamaciones, leads).
 *   El colegio solo lee lo suyo (RLS forzado); escribe el job de correo con la conexión de
 *   plataforma, como manda docs/rls-tablas-globales.md para los jobs de plataforma.
 * - correos_suprimidos: direcciones con rebote duro o queja. No se les vuelve a enviar.
 *   Es de plataforma: edunova_app no la lee ni la escribe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE correos_bitacora (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint REFERENCES instituciones(id) ON DELETE CASCADE,
              destinatario varchar(254) NOT NULL,
              plantilla varchar(60) NOT NULL,
              asunto varchar(200) NOT NULL,
              estado varchar(12) NOT NULL DEFAULT 'en_cola'
                CHECK (estado IN ('en_cola', 'enviado', 'fallido', 'suprimido', 'rebotado', 'queja')),
              mensaje_id varchar(200),
              intentos smallint NOT NULL DEFAULT 0,
              error text,
              enviado_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER trg_correos_bitacora_upd BEFORE UPDATE ON correos_bitacora FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            CREATE INDEX ON correos_bitacora (institucion_id, created_at DESC);
            CREATE INDEX ON correos_bitacora (lower(destinatario));

            ALTER TABLE correos_bitacora ENABLE ROW LEVEL SECURITY;
            ALTER TABLE correos_bitacora FORCE ROW LEVEL SECURITY;
            CREATE POLICY correos_bitacora_colegio ON correos_bitacora FOR SELECT USING (institucion_id = app_institucion());
            GRANT SELECT ON correos_bitacora TO edunova_app;
            GRANT SELECT, INSERT, UPDATE ON correos_bitacora TO edunova_plataforma;

            CREATE TABLE correos_suprimidos (
              email varchar(254) PRIMARY KEY CHECK (email = lower(email)),
              motivo varchar(10) NOT NULL CHECK (motivo IN ('rebote', 'queja', 'manual')),
              detalle text,
              created_at timestamptz NOT NULL DEFAULT now()
            );
            ALTER TABLE correos_suprimidos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE correos_suprimidos FORCE ROW LEVEL SECURITY;
            GRANT SELECT, INSERT, UPDATE, DELETE ON correos_suprimidos TO edunova_plataforma;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS correos_suprimidos; DROP TABLE IF EXISTS correos_bitacora;');
    }
};
