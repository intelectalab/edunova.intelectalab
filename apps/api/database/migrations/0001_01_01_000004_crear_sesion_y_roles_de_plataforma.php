<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-35 · Sesión con colegio activo y roles de plataforma.
 *
 * - personal_access_tokens.institucion_id: el colegio que el usuario eligió para esa sesión.
 *   El middleware «colegio» lo usa para fijar app.institucion_id en cada petición.
 * - roles_plataforma: roles de ámbito plataforma (superadmin, soporte). No dependen de un
 *   colegio, así que no caben en membresias (institucion_id NOT NULL). La app solo lee la
 *   fila del propio usuario por medio de fn_roles_plataforma(); se administra desde plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE personal_access_tokens ADD COLUMN institucion_id bigint REFERENCES instituciones(id) ON DELETE SET NULL;

            CREATE TABLE roles_plataforma (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              rol_id bigint NOT NULL REFERENCES roles(id),
              activo boolean NOT NULL DEFAULT true,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (user_id, rol_id)
            );
            CREATE TRIGGER trg_roles_plataforma_upd BEFORE UPDATE ON roles_plataforma FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

            -- Solo roles de ámbito plataforma.
            CREATE OR REPLACE FUNCTION tg_roles_plataforma_ambito() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
              IF (SELECT ambito FROM roles WHERE id = NEW.rol_id) <> 'plataforma' THEN
                RAISE EXCEPTION 'roles_plataforma solo admite roles de ámbito plataforma' USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $f$;
            CREATE TRIGGER trg_roles_plataforma_ambito BEFORE INSERT OR UPDATE ON roles_plataforma FOR EACH ROW EXECUTE FUNCTION tg_roles_plataforma_ambito();

            ALTER TABLE roles_plataforma ENABLE ROW LEVEL SECURITY;
            ALTER TABLE roles_plataforma FORCE ROW LEVEL SECURITY;
            GRANT SELECT, INSERT, UPDATE, DELETE ON roles_plataforma TO edunova_plataforma;

            -- La app pregunta solo por un usuario concreto (el de la sesión).
            CREATE OR REPLACE FUNCTION fn_roles_plataforma(p_user bigint)
            RETURNS TABLE (rol varchar)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
              SELECT r.codigo FROM roles_plataforma rp JOIN roles r ON r.id = rp.rol_id
              JOIN users u ON u.id = rp.user_id
              WHERE rp.user_id = p_user AND rp.activo AND u.activo
            $f$;
            REVOKE ALL ON FUNCTION fn_roles_plataforma(bigint) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION fn_roles_plataforma(bigint) TO edunova_app;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fn_roles_plataforma(bigint);
            DROP TABLE IF EXISTS roles_plataforma;
            DROP FUNCTION IF EXISTS tg_roles_plataforma_ambito();
            ALTER TABLE personal_access_tokens DROP COLUMN IF EXISTS institucion_id;
            SQL);
    }
};
