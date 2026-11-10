-- =====================================================================
-- Sprint 10 · Panel CMS completo: editor de secciones (EDUN-387 / SIT-020), popup (EDUN-391 /
-- SIT-025), configuración general y mantenimiento (EDUN-399 / SIT-062), logos de clientes
-- (EDUN-338 / SIT-060), auditoría y restauración (EDUN-342 / SIT-061) y usuarios con acceso
-- (EDUN-407 / SIT-065). Idempotente: lo cargan la migración 0001_01_01_000008 e infra/pruebas-cms.sh.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Secciones internas del panel: guardan el borrador de pantallas que no son una sección de la
-- landing (mismo flujo Guardar borrador / Publicar, versiones y auditoría). Siempre ocultas:
-- la landing solo lee secciones visibles. El editor de secciones no las lista.
-- ---------------------------------------------------------------------
INSERT INTO cms.secciones (clave, nombre, orden, visible, config) VALUES
 ('_estructura', 'Orden de la landing', 0, false, '{}'),
 ('_popup', 'Popup de salida', 0, false, '{}'),
 ('_config', 'Configuración general', 0, false, '{}')
ON CONFLICT (clave) DO NOTHING;

-- Logos de clientes («Confían en Edunova»): oculta hasta que haya logos autorizados.
INSERT INTO cms.secciones (clave, nombre, orden, visible, config) VALUES
 ('logos', 'Logos de clientes', 11, false, '{"titulo":"Confían en Edunova","velocidad":30,"columnas":5}')
ON CONFLICT (clave) DO NOTHING;

-- Una sección de logos sin logos visibles no se muestra (lo exige también la API al publicar).
CREATE OR REPLACE FUNCTION cms.validar_seccion_logos() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.clave = 'logos' AND NEW.visible AND NOT EXISTS (SELECT 1 FROM cms.clientes WHERE visible) THEN
    RAISE EXCEPTION 'La sección de logos necesita al menos un logo autorizado y visible' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS secciones_logos ON cms.secciones;
CREATE CONSTRAINT TRIGGER secciones_logos AFTER INSERT OR UPDATE ON cms.secciones
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cms.validar_seccion_logos();

-- ---------------------------------------------------------------------
-- Versiones: se conservan las 5 más recientes por elemento (SIT-061).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cms.podar_versiones() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM cms.versiones v
  WHERE v.seccion = NEW.seccion AND v.entidad = NEW.entidad AND v.entidad_id = NEW.entidad_id
    AND v.id NOT IN (
      SELECT id FROM cms.versiones
      WHERE seccion = NEW.seccion AND entidad = NEW.entidad AND entidad_id = NEW.entidad_id
      ORDER BY publicado_en DESC, id DESC LIMIT 5);
  RETURN NULL;
END $$;
DROP TRIGGER IF EXISTS versiones_podar ON cms.versiones;
CREATE TRIGGER versiones_podar AFTER INSERT ON cms.versiones FOR EACH ROW EXECUTE FUNCTION cms.podar_versiones();

-- ---------------------------------------------------------------------
-- Usuarios con acceso al CMS (SIT-065): los superadmin activos de la plataforma. Solo lectura;
-- el alta y la baja se hacen en la consola de plataforma. La función solo responde al panel.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cms.usuarios_cms()
RETURNS TABLE (id bigint, nombres varchar, apellidos varchar, email varchar, ultimo_acceso_at timestamptz, desde timestamptz, dos_pasos boolean)
-- plpgsql: el cuerpo se valida al usarse (infra/pruebas-cms.sh carga el esquema cms sin las tablas de la plataforma).
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
BEGIN
  RETURN QUERY SELECT u.id, u.nombres, u.apellidos, u.email, u.ultimo_acceso_at, rp.created_at, u.dos_pasos_secreto IS NOT NULL
  FROM roles_plataforma rp
  JOIN roles r ON r.id = rp.rol_id
  JOIN users u ON u.id = rp.user_id
  WHERE r.codigo = 'superadmin' AND rp.activo AND u.activo AND cms.rol_actual() = 'superadmin'
  ORDER BY u.apellidos, u.nombres;
END $$;
REVOKE ALL ON FUNCTION cms.usuarios_cms() FROM PUBLIC;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_app') THEN
    GRANT EXECUTE ON FUNCTION cms.usuarios_cms() TO edunova_app;
  END IF;
END $$;
