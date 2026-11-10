<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 12 · Base de la gestión del colegio.
 *
 * - EDUN-38 · Segundo factor (TOTP): estado en users y marca de sesión verificada en el token.
 * - EDUN-36 / EDUN-24 · Invitaciones de un solo uso (correo o WhatsApp). La consulta y la
 *   aceptación sin sesión pasan por funciones SECURITY DEFINER que solo ven una invitación por
 *   el hash de su enlace.
 * - EDUN-42 · Permiso para archivos confidenciales.
 * - EDUN-43 · El colegio solo edita sus datos MINEDU, de contacto y de marca: no su estado,
 *   slug ni subdominio (privilegios por columna).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- EDUN-38 · Segundo factor.
            ALTER TABLE users
              ADD COLUMN dos_pasos_activo_at timestamptz,
              ADD COLUMN dos_pasos_respaldo jsonb NOT NULL DEFAULT '[]'::jsonb;
            ALTER TABLE personal_access_tokens ADD COLUMN dos_pasos_ok boolean NOT NULL DEFAULT false;

            -- EDUN-36 · Invitaciones.
            CREATE TABLE invitaciones (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              token_hash char(64) NOT NULL UNIQUE,
              canal varchar(10) NOT NULL CHECK (canal in ('correo','whatsapp')),
              roles jsonb NOT NULL DEFAULT '[]'::jsonb,
              invitado_por bigint REFERENCES users(id),
              expira_at timestamptz NOT NULL,
              usada_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (institucion_id, id)
            );
            CREATE INDEX ON invitaciones (institucion_id, user_id);
            CREATE TRIGGER trg_invitaciones_upd BEFORE UPDATE ON invitaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            ALTER TABLE invitaciones ENABLE ROW LEVEL SECURITY;
            ALTER TABLE invitaciones FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON invitaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON invitaciones TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT, UPDATE ON invitaciones TO edunova_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON invitaciones TO edunova_plataforma;

            -- Pantalla pública de la invitación: solo la invitación de ese enlace.
            CREATE OR REPLACE FUNCTION fn_invitacion_por_token(p_hash char(64))
            RETURNS TABLE (id bigint, colegio varchar, nombres varchar, apellidos varchar, email varchar,
                           tiene_password boolean, expira_at timestamptz, usada_at timestamptz)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
              SELECT iv.id, i.nombre, u.nombres, u.apellidos, u.email, u.password IS NOT NULL, iv.expira_at, iv.usada_at
              FROM invitaciones iv JOIN instituciones i ON i.id = iv.institucion_id JOIN users u ON u.id = iv.user_id
              WHERE iv.token_hash = p_hash AND i.estado IN ('implementacion','activo')
            $f$;
            REVOKE ALL ON FUNCTION fn_invitacion_por_token(char) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION fn_invitacion_por_token(char) TO edunova_app;

            -- Acepta la invitación una sola vez. Con contraseña nueva solo si el usuario aún no
            -- tiene una (si ya usa Edunova en otro colegio, conserva la suya).
            CREATE OR REPLACE FUNCTION fn_aceptar_invitacion(p_hash char(64), p_password varchar)
            RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $f$
            DECLARE v invitaciones%ROWTYPE;
            BEGIN
              SELECT * INTO v FROM invitaciones WHERE token_hash = p_hash FOR UPDATE;
              IF NOT FOUND OR v.usada_at IS NOT NULL OR v.expira_at < now() THEN
                RETURN NULL;
              END IF;
              UPDATE invitaciones SET usada_at = now() WHERE id = v.id;
              UPDATE users SET
                password = CASE WHEN password IS NULL THEN p_password ELSE password END,
                email_verificado_at = coalesce(email_verificado_at, now()),
                activo = true
              WHERE id = v.user_id;
              RETURN v.user_id;
            END $f$;
            REVOKE ALL ON FUNCTION fn_aceptar_invitacion(char, varchar) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION fn_aceptar_invitacion(char, varchar) TO edunova_app;

            -- Un mismo usuario puede tener el mismo rol en varios colegios (SEG regla 1): la
            -- unicidad es por colegio.
            ALTER TABLE membresias DROP CONSTRAINT IF EXISTS membresias_user_id_rol_id_key;
            ALTER TABLE membresias ADD CONSTRAINT membresias_colegio_usuario_rol_key UNIQUE (institucion_id, user_id, rol_id);

            -- EDUN-43 · Columnas que el colegio puede cambiar de su propia fila.
            REVOKE UPDATE ON instituciones FROM edunova_app;
            GRANT UPDATE (nombre, razon_social, ruc, codigo_modular, codigo_local, ugel, dre, nivel_educativo,
                          ubigeo, direccion, telefono, correo, logo_archivo_id, color_primario, configuracion)
              ON instituciones TO edunova_app;
            SQL);

        // EDUN-42 · Permiso de archivos confidenciales (en bases nuevas lo vuelve a cargar el seeder).
        DB::unprepared(file_get_contents(database_path('sql/04_permisos_adicionales.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            GRANT UPDATE ON instituciones TO edunova_app;
            ALTER TABLE membresias DROP CONSTRAINT IF EXISTS membresias_colegio_usuario_rol_key;
            ALTER TABLE membresias DROP CONSTRAINT IF EXISTS membresias_user_id_rol_id_key;
            ALTER TABLE membresias ADD CONSTRAINT membresias_user_id_rol_id_key UNIQUE (user_id, rol_id);
            DELETE FROM rol_permiso WHERE permiso_id IN (SELECT id FROM permisos WHERE codigo = 'seg.archivos.confidenciales');
            DELETE FROM permisos WHERE codigo = 'seg.archivos.confidenciales';
            DROP FUNCTION IF EXISTS fn_aceptar_invitacion(char, varchar);
            DROP FUNCTION IF EXISTS fn_invitacion_por_token(char);
            DROP TABLE IF EXISTS invitaciones;
            ALTER TABLE personal_access_tokens DROP COLUMN IF EXISTS dos_pasos_ok;
            ALTER TABLE users DROP COLUMN IF EXISTS dos_pasos_respaldo, DROP COLUMN IF EXISTS dos_pasos_activo_at;
            SQL);
    }
};
