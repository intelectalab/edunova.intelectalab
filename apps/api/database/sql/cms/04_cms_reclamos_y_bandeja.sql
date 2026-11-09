SET client_min_messages = warning;
-- Sprint 7 · Bandeja de solicitudes y Libro de Reclamaciones.
--   EDUN-278 (SIT-026): primer contacto de cada lead, para el tiempo de respuesta de la bandeja.
--   EDUN-294 (SIT-010): feriados nacionales y vencimiento a 15 días hábiles; datos del apoderado.
--   EDUN-298 (SIT-011): consulta del estado solo con expediente + documento, sin datos personales.
-- Idempotente. Se carga después de 03_cms_conversion.sql (migración 0006 y infra/pruebas-cms.sh).

-- ---------------------------------------------------------------------
-- Feriados nacionales del Perú (calendario del plazo legal de reclamos).
-- Los colegios tienen sus propios feriados en public.feriados; estos son del SaaS.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cms.feriados_nacionales (
  fecha   date PRIMARY KEY,
  motivo  varchar(120) NOT NULL
);
ALTER TABLE cms.feriados_nacionales ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS feriados_nacionales_lectura ON cms.feriados_nacionales;
DROP POLICY IF EXISTS feriados_nacionales_edicion ON cms.feriados_nacionales;
CREATE POLICY feriados_nacionales_lectura ON cms.feriados_nacionales FOR SELECT USING (true);
CREATE POLICY feriados_nacionales_edicion ON cms.feriados_nacionales FOR ALL USING (cms.rol_actual() = 'superadmin') WITH CHECK (cms.rol_actual() = 'superadmin');

INSERT INTO cms.feriados_nacionales (fecha, motivo) VALUES
 ('2026-01-01', 'Año Nuevo'), ('2026-04-02', 'Jueves Santo'), ('2026-04-03', 'Viernes Santo'),
 ('2026-05-01', 'Día del Trabajo'), ('2026-06-07', 'Batalla de Arica y Día de la Bandera'),
 ('2026-06-29', 'San Pedro y San Pablo'), ('2026-07-23', 'Día de la Fuerza Aérea del Perú'),
 ('2026-07-28', 'Fiestas Patrias'), ('2026-07-29', 'Fiestas Patrias'), ('2026-08-06', 'Batalla de Junín'),
 ('2026-08-30', 'Santa Rosa de Lima'), ('2026-10-08', 'Combate de Angamos'), ('2026-11-01', 'Todos los Santos'),
 ('2026-12-08', 'Inmaculada Concepción'), ('2026-12-09', 'Batalla de Ayacucho'), ('2026-12-25', 'Navidad'),
 ('2027-01-01', 'Año Nuevo'), ('2027-03-25', 'Jueves Santo'), ('2027-03-26', 'Viernes Santo'),
 ('2027-05-01', 'Día del Trabajo'), ('2027-06-07', 'Batalla de Arica y Día de la Bandera'),
 ('2027-06-29', 'San Pedro y San Pablo'), ('2027-07-23', 'Día de la Fuerza Aérea del Perú'),
 ('2027-07-28', 'Fiestas Patrias'), ('2027-07-29', 'Fiestas Patrias'), ('2027-08-06', 'Batalla de Junín'),
 ('2027-08-30', 'Santa Rosa de Lima'), ('2027-10-08', 'Combate de Angamos'), ('2027-11-01', 'Todos los Santos'),
 ('2027-12-08', 'Inmaculada Concepción'), ('2027-12-09', 'Batalla de Ayacucho'), ('2027-12-25', 'Navidad')
ON CONFLICT (fecha) DO NOTHING;

-- n días hábiles después de «desde» (sin sábados, domingos ni feriados nacionales).
CREATE OR REPLACE FUNCTION cms.sumar_dias_habiles(desde date, n integer) RETURNS date
LANGUAGE plpgsql STABLE AS $$
DECLARE
  d date := desde;
  contados integer := 0;
BEGIN
  WHILE contados < n LOOP
    d := d + 1;
    IF extract(isodow FROM d) < 6 AND NOT EXISTS (SELECT 1 FROM cms.feriados_nacionales f WHERE f.fecha = d) THEN
      contados := contados + 1;
    END IF;
  END LOOP;
  RETURN d;
END $$;

-- ---------------------------------------------------------------------
-- Reclamaciones: apoderado (si el reclamante es menor de edad) e IP del envío.
-- Los adjuntos van al almacenamiento privado (nunca a la Biblioteca de medios, que es pública):
-- adjuntos = [{ruta, nombre, mime, bytes}].
-- ---------------------------------------------------------------------
ALTER TABLE cms.reclamaciones ADD COLUMN IF NOT EXISTS menor_edad boolean NOT NULL DEFAULT false;
ALTER TABLE cms.reclamaciones ADD COLUMN IF NOT EXISTS apoderado_nombre varchar(160);
ALTER TABLE cms.reclamaciones ADD COLUMN IF NOT EXISTS apoderado_doc varchar(15);
ALTER TABLE cms.reclamaciones ADD COLUMN IF NOT EXISTS ip inet;
ALTER TABLE cms.reclamaciones DROP CONSTRAINT IF EXISTS reclamaciones_apoderado;
ALTER TABLE cms.reclamaciones ADD CONSTRAINT reclamaciones_apoderado CHECK (NOT menor_edad OR (apoderado_nombre IS NOT NULL AND apoderado_doc IS NOT NULL));
ALTER TABLE cms.reclamaciones DROP CONSTRAINT IF EXISTS reclamaciones_expediente_formato;
ALTER TABLE cms.reclamaciones ADD CONSTRAINT reclamaciones_expediente_formato CHECK (numero_expediente ~ '^REC-[0-9]{4}-[0-9]{4,}$');

-- Seguimiento público (EDUN-298): el público no lee cms.reclamaciones (RLS); esta función solo
-- devuelve el estado cuando coinciden el expediente y el documento del titular.
CREATE OR REPLACE FUNCTION cms.seguimiento_reclamo(p_expediente text, p_documento text)
RETURNS TABLE (numero_expediente varchar, tipo varchar, estado varchar, actualizado_en timestamptz, vence_en date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = cms, pg_temp AS $$
  SELECT r.numero_expediente, r.tipo, r.estado, r.updated_at, r.vence_en
  FROM cms.reclamaciones r
  WHERE r.numero_expediente = upper(trim(p_expediente))
    AND upper(regexp_replace(r.numero_doc, '[^0-9A-Za-z]', '', 'g')) = upper(regexp_replace(p_documento, '[^0-9A-Za-z]', '', 'g'))
$$;
REVOKE ALL ON FUNCTION cms.seguimiento_reclamo(text, text) FROM PUBLIC;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_app') THEN
    GRANT EXECUTE ON FUNCTION cms.seguimiento_reclamo(text, text) TO edunova_app;
    GRANT SELECT, INSERT, UPDATE, DELETE ON cms.feriados_nacionales TO edunova_app;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_plataforma') THEN
    GRANT SELECT, INSERT, UPDATE, DELETE ON cms.feriados_nacionales TO edunova_plataforma;
  END IF;
END $$;

-- ---------------------------------------------------------------------
-- Leads: momento del primer contacto (al dejar el estado «nuevo»), para el tiempo de respuesta.
-- ---------------------------------------------------------------------
ALTER TABLE cms.leads ADD COLUMN IF NOT EXISTS primer_contacto_en timestamptz;
CREATE OR REPLACE FUNCTION cms.marcar_primer_contacto() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.estado = 'nuevo' AND NEW.estado <> 'nuevo' AND NEW.primer_contacto_en IS NULL THEN
    NEW.primer_contacto_en := now();
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS leads_primer_contacto ON cms.leads;
CREATE TRIGGER leads_primer_contacto BEFORE UPDATE OF estado ON cms.leads FOR EACH ROW EXECUTE FUNCTION cms.marcar_primer_contacto();
CREATE INDEX IF NOT EXISTS leads_plan ON cms.leads (plan_interes);
