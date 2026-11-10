<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 13 · Estructura académica del año.
 *
 * - EDUN-45 · El plan de estudios es de un año: area_curricular_grado.anio_escolar_id (lo
 *   completa un trigger con el año más reciente si no viene, para los datos del kit) y la
 *   unicidad pasa a ser por grado, año y área. Así se puede copiar el plan del año anterior.
 * - EDUN-49 · El horario es de un año: horario_sesiones.anio_escolar_id (sale de la sección).
 *   Ni el docente ni el ambiente pueden estar en dos secciones en el mismo bloque del año.
 * - EDUN-47 · Contacto del personal (para invitarlo por correo o WhatsApp).
 * - EDUN-48 · Curso de Moodle por crear: moodle_cursos.moodle_course_id queda vacío hasta que
 *   el conector lo cree (estado pendiente → creado | error).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- EDUN-45 · Plan de estudios por año.
            ALTER TABLE area_curricular_grado ADD COLUMN anio_escolar_id bigint;
            UPDATE area_curricular_grado acg SET anio_escolar_id = (
              SELECT a.id FROM anios_escolares a WHERE a.institucion_id = acg.institucion_id ORDER BY a.anio DESC LIMIT 1);
            ALTER TABLE area_curricular_grado ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id) ON DELETE CASCADE;
            ALTER TABLE area_curricular_grado DROP CONSTRAINT IF EXISTS area_curricular_grado_grado_id_area_curricular_id_key;
            ALTER TABLE area_curricular_grado ADD CONSTRAINT area_curricular_grado_grado_anio_area_key UNIQUE (grado_id, anio_escolar_id, area_curricular_id);
            CREATE INDEX ON area_curricular_grado (institucion_id, anio_escolar_id);

            CREATE OR REPLACE FUNCTION tg_area_grado_anio() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
              IF NEW.anio_escolar_id IS NULL THEN
                SELECT a.id INTO NEW.anio_escolar_id FROM anios_escolares a
                 WHERE a.institucion_id = NEW.institucion_id ORDER BY a.anio DESC LIMIT 1;
              END IF;
              RETURN NEW;
            END $f$;
            CREATE TRIGGER trg_area_curricular_grado_anio BEFORE INSERT ON area_curricular_grado
              FOR EACH ROW EXECUTE FUNCTION tg_area_grado_anio();

            -- EDUN-49 · Horario por año, sin cruces de docente ni de ambiente.
            ALTER TABLE horario_sesiones ADD COLUMN anio_escolar_id bigint;
            UPDATE horario_sesiones h SET anio_escolar_id = s.anio_escolar_id FROM secciones s WHERE s.id = h.seccion_id;
            CREATE OR REPLACE FUNCTION tg_horario_anio() RETURNS trigger LANGUAGE plpgsql AS $f$
            BEGIN
              SELECT s.anio_escolar_id INTO NEW.anio_escolar_id FROM secciones s WHERE s.id = NEW.seccion_id;
              RETURN NEW;
            END $f$;
            CREATE TRIGGER trg_horario_sesiones_anio BEFORE INSERT OR UPDATE ON horario_sesiones
              FOR EACH ROW EXECUTE FUNCTION tg_horario_anio();
            ALTER TABLE horario_sesiones ALTER COLUMN anio_escolar_id SET NOT NULL;
            ALTER TABLE horario_sesiones DROP CONSTRAINT IF EXISTS horario_sesiones_personal_id_dia_semana_bloque_horario_id_key;
            ALTER TABLE horario_sesiones ADD CONSTRAINT horario_sin_cruce_docente UNIQUE (anio_escolar_id, personal_id, dia_semana, bloque_horario_id);
            CREATE UNIQUE INDEX horario_sin_cruce_ambiente ON horario_sesiones (anio_escolar_id, ambiente_id, dia_semana, bloque_horario_id)
              WHERE ambiente_id IS NOT NULL;

            -- EDUN-47 · Contacto del personal.
            ALTER TABLE personal ADD COLUMN email varchar(150), ADD COLUMN celular varchar(15);

            -- EDUN-48 · Curso de Moodle pendiente de crear por el conector.
            ALTER TABLE moodle_cursos ALTER COLUMN moodle_course_id DROP NOT NULL;
            ALTER TABLE moodle_cursos ALTER COLUMN shortname DROP NOT NULL;
            ALTER TABLE moodle_cursos ADD COLUMN estado varchar(10) NOT NULL DEFAULT 'pendiente'
              CHECK (estado in ('pendiente','creado','error'));
            UPDATE moodle_cursos SET estado = 'creado' WHERE moodle_course_id IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE personal DROP COLUMN IF EXISTS email, DROP COLUMN IF EXISTS celular;
            DELETE FROM moodle_cursos WHERE moodle_course_id IS NULL;
            ALTER TABLE moodle_cursos DROP COLUMN IF EXISTS estado;
            ALTER TABLE moodle_cursos ALTER COLUMN moodle_course_id SET NOT NULL;
            ALTER TABLE moodle_cursos ALTER COLUMN shortname SET NOT NULL;

            DROP INDEX IF EXISTS horario_sin_cruce_ambiente;
            ALTER TABLE horario_sesiones DROP CONSTRAINT IF EXISTS horario_sin_cruce_docente;
            DROP TRIGGER IF EXISTS trg_horario_sesiones_anio ON horario_sesiones;
            DROP FUNCTION IF EXISTS tg_horario_anio();
            ALTER TABLE horario_sesiones DROP COLUMN IF EXISTS anio_escolar_id;
            ALTER TABLE horario_sesiones ADD CONSTRAINT horario_sesiones_personal_id_dia_semana_bloque_horario_id_key UNIQUE (personal_id, dia_semana, bloque_horario_id);

            DROP TRIGGER IF EXISTS trg_area_curricular_grado_anio ON area_curricular_grado;
            DROP FUNCTION IF EXISTS tg_area_grado_anio();
            ALTER TABLE area_curricular_grado DROP CONSTRAINT IF EXISTS area_curricular_grado_grado_anio_area_key;
            DELETE FROM area_curricular_grado a USING area_curricular_grado b
             WHERE a.grado_id = b.grado_id AND a.area_curricular_id = b.area_curricular_id AND a.id > b.id;
            ALTER TABLE area_curricular_grado DROP COLUMN IF EXISTS anio_escolar_id;
            ALTER TABLE area_curricular_grado ADD CONSTRAINT area_curricular_grado_grado_id_area_curricular_id_key UNIQUE (grado_id, area_curricular_id);
            SQL);
    }
};
