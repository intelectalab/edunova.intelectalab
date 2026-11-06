-- Pruebas de reglas del esquema cms. Ejecutar después de cms_schema.sql.
-- Cada bloque debe terminar en OK; si una regla falla, el script se detiene con el mensaje.
\set ON_ERROR_STOP on
DO $$ BEGIN
  -- 1. Máximo 6 módulos en el grid principal
  BEGIN
    UPDATE cms.modulos_web SET en_grid = true WHERE clave = 'biblioteca';
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'FALLO 1: permitió 7 módulos en el grid';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 1 · grid principal limitado a 6'; END;

  -- 2. Un solo plan recomendado
  BEGIN
    UPDATE cms.planes_sitio SET recomendado = true WHERE clave = 'integral';
    RAISE EXCEPTION 'FALLO 2: permitió dos planes recomendados';
  EXCEPTION WHEN unique_violation THEN RAISE NOTICE 'OK 2 · un solo plan recomendado'; END;

  -- 3. Testimonio real visible exige consentimiento + fecha + documento
  BEGIN
    INSERT INTO cms.testimonios (cargo, institucion, texto, calificacion, visible) VALUES ('Directora','Colegio particular · Cusco','Texto de prueba con más de veinte caracteres.',5,true);
    RAISE EXCEPTION 'FALLO 3: publicó un testimonio sin consentimiento';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 3 · testimonio sin consentimiento no se publica'; END;

  -- 4. Lead sin email ni teléfono
  BEGIN
    INSERT INTO cms.leads (source, consentimiento_version) VALUES ('popup','privacidad-v1.0');
    RAISE EXCEPTION 'FALLO 4: aceptó lead sin contacto';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 4 · lead exige email o teléfono'; END;

  -- 5. Demo solo en días hábiles (2026-11-07 es sábado)
  BEGIN
    INSERT INTO cms.leads (source, email, demo_fecha, consentimiento_version) VALUES ('demo','a@b.pe','2026-11-07','privacidad-v1.0');
    RAISE EXCEPTION 'FALLO 5: aceptó demo en sábado';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 5 · demo solo lunes a viernes'; END;

  -- 6. Reclamo con descripción menor a 50 caracteres
  BEGIN
    INSERT INTO cms.reclamaciones (numero_expediente,tipo,nombre_reclamante,tipo_doc,numero_doc,email,producto_descripcion,descripcion,pedido,vence_en)
    VALUES ('REC-2026-0001','reclamo','Ana Pérez','DNI','12345678','ana@x.pe','Plan Inicial','Muy corto','Devolución', current_date + 21);
    RAISE EXCEPTION 'FALLO 6: aceptó descripción corta';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 6 · reclamo exige 50 caracteres'; END;

  -- 7. Auditoría inmutable
  INSERT INTO cms.auditoria (usuario_rol, seccion, accion, entidad, entidad_id, detalle) VALUES ('superadmin','planes','editado','planes_sitio','2','Plan Crecimiento · precio mensual');
  BEGIN
    UPDATE cms.auditoria SET detalle = 'x';
    RAISE EXCEPTION 'FALLO 7: permitió editar la auditoría';
  EXCEPTION WHEN raise_exception THEN
    IF SQLERRM LIKE 'FALLO%' THEN RAISE; END IF;
    RAISE NOTICE 'OK 7 · auditoría de solo inserción';
  END;

  -- 8. Solo se conservan 5 versiones por entidad
  FOR i IN 1..7 LOOP
    INSERT INTO cms.versiones (seccion, entidad, entidad_id, contenido, publicado_en) VALUES ('planes','planes_sitio','2', jsonb_build_object('v', i), now() + i * interval '1 second');
  END LOOP;
  IF (SELECT count(*) FROM cms.versiones WHERE entidad='planes_sitio' AND entidad_id='2') <> 5 THEN RAISE EXCEPTION 'FALLO 8: no podó versiones'; END IF;
  RAISE NOTICE 'OK 8 · se conservan 5 versiones';

  -- 9. Logo visible exige autorización
  BEGIN
    INSERT INTO cms.medios (tipo,nombre,url,mime,bytes) VALUES ('svg','logo.svg','s3://x/logo.svg','image/svg+xml',1200);
    INSERT INTO cms.clientes (nombre, logo_id, visible) VALUES ('Colegio X', currval('cms.medios_id_seq'), true);
    RAISE EXCEPTION 'FALLO 9: publicó logo sin autorización';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 9 · logo exige autorización de uso'; END;

  -- 10. Enlaces legales del footer no ocultables
  BEGIN
    INSERT INTO cms.menu_items (ubicacion, label, url, orden, fijo, visible) VALUES ('footer','Libro de Reclamaciones','/libro-de-reclamaciones',1,true,false);
    RAISE EXCEPTION 'FALLO 10: ocultó un enlace legal';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 10 · enlaces legales siempre visibles'; END;

  RAISE NOTICE 'TODAS LAS PRUEBAS PASARON';
END $$;

-- ---------------------------------------------------------------------
-- Permisos por rol (RLS). Se prueban con un rol sin privilegios de superusuario.
-- ---------------------------------------------------------------------
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_web') THEN CREATE ROLE edunova_web NOLOGIN; END IF;
END $$;
GRANT USAGE ON SCHEMA cms TO edunova_web;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA cms TO edunova_web;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA cms TO edunova_web;
SET ROLE edunova_web;
DO $$ DECLARE n int; BEGIN
  PERFORM set_config('app.rol_cms', 'publico', true);
  UPDATE cms.planes_sitio SET precio_mensual = 1 WHERE clave = 'inicial'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 11: el público editó un precio'; END IF;
  SELECT count(*) INTO n FROM cms.leads; IF n <> 0 THEN RAISE EXCEPTION 'FALLO 12: el público leyó leads'; END IF;
  INSERT INTO cms.leads (source, email, consentimiento_version) VALUES ('popup', 'visitante@colegio.pe', 'privacidad-v1.0');
  RAISE NOTICE 'OK 11-13 · público: lee contenido, no edita, no lee leads, sí envía leads';

  -- Un usuario de colegio (por ejemplo, el promotor o propietario) no tiene CMS
  PERFORM set_config('app.rol_cms', 'promotor', true);
  UPDATE cms.planes_sitio SET precio_mensual = 1 WHERE clave = 'inicial'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 14: un usuario de colegio editó un precio del sitio'; END IF;
  SELECT count(*) INTO n FROM cms.leads; IF n <> 0 THEN RAISE EXCEPTION 'FALLO 15: un usuario de colegio leyó leads del sitio'; END IF;
  UPDATE cms.config SET valor = '"x"' WHERE clave = 'crm_endpoint'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 16: un usuario de colegio cambió la configuración'; END IF;
  RAISE NOTICE 'OK 14-16 · usuario de colegio (promotor): sin acceso al CMS';

  PERFORM set_config('app.rol_cms', 'superadmin', true);
  UPDATE cms.planes_sitio SET precio_mensual = 395 WHERE clave = 'inicial'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 17: el superadmin no pudo editar un precio'; END IF;
  UPDATE cms.config SET valor = '"/api/crm/leads"' WHERE clave = 'crm_endpoint'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 1 THEN RAISE EXCEPTION 'FALLO 17: el superadmin no pudo editar la configuración'; END IF;
  SELECT count(*) INTO n FROM cms.leads; IF n < 1 THEN RAISE EXCEPTION 'FALLO 17: el superadmin no ve leads'; END IF;
  RAISE NOTICE 'OK 17 · superadmin del SaaS: acceso total al CMS';
  RAISE NOTICE 'TODAS LAS PRUEBAS DE PERMISOS PASARON';
END $$;
RESET ROLE;
