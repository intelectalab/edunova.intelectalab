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

  -- 5. Demo solo en días hábiles (2030-01-05 es sábado)
  BEGIN
    INSERT INTO cms.leads (source, email, demo_fecha, demo_hora, consentimiento_version) VALUES ('demo','a@b.pe','2030-01-05','09:00','privacidad-v1.0');
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

  -- 18. Demo desde mañana y con fecha y hora (EDUN-267, 03_cms_conversion.sql)
  BEGIN
    INSERT INTO cms.leads (source, email, demo_fecha, demo_hora, consentimiento_version) VALUES ('demo','a@b.pe',(now() AT TIME ZONE 'America/Lima')::date,'09:00','privacidad-v1.0');
    RAISE EXCEPTION 'FALLO 18: aceptó una demo para hoy';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 18 · demo desde mañana'; END;
  BEGIN
    INSERT INTO cms.leads (source, email, demo_fecha, consentimiento_version) VALUES ('demo','a@b.pe','2030-01-07','privacidad-v1.0');
    RAISE EXCEPTION 'FALLO 19: aceptó una demo sin hora';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 19 · demo con fecha y hora'; END;

  -- 20. Cookies: siempre «esenciales» y solo categorías conocidas (EDUN-306)
  BEGIN
    INSERT INTO cms.consentimientos (tipo, version_politica, categorias) VALUES ('cookies','cookies-v1.0',ARRAY['analitica']);
    RAISE EXCEPTION 'FALLO 20: aceptó cookies sin esenciales';
  EXCEPTION WHEN check_violation THEN NULL; END;
  BEGIN
    INSERT INTO cms.consentimientos (tipo, version_politica, categorias) VALUES ('cookies','cookies-v1.0',ARRAY['esenciales','rastreo']);
    RAISE EXCEPTION 'FALLO 20: aceptó una categoría desconocida';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 20 · categorías de cookies válidas'; END;

  -- 22. Plazo del reclamo: 15 días hábiles sin fines de semana ni feriados (EDUN-294)
  IF cms.sumar_dias_habiles('2026-12-01', 15) <> '2026-12-24' THEN RAISE EXCEPTION 'FALLO 22: no saltó los feriados del 8 y 9 de diciembre'; END IF;
  RAISE NOTICE 'OK 22 · vencimiento en días hábiles';

  -- 23. Menor de edad exige apoderado (EDUN-294)
  BEGIN
    INSERT INTO cms.reclamaciones (numero_expediente,tipo,nombre_reclamante,tipo_doc,numero_doc,email,producto_descripcion,descripcion,pedido,vence_en,menor_edad)
    VALUES ('REC-2026-0002','queja','Alumno','DNI','12345678','a@x.pe','Plan Inicial',repeat('x',60),'Respuesta',current_date + 21,true);
    RAISE EXCEPTION 'FALLO 23: aceptó un menor sin apoderado';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 23 · menor de edad con apoderado'; END;

  -- 25. El lead del popup de salida nunca trae demo (EDUN-271, 05_cms_crm_y_reclamaciones.sql)
  BEGIN
    INSERT INTO cms.leads (source, email, demo_fecha, demo_hora, consentimiento_version) VALUES ('popup','p@x.pe','2030-01-07','09:00','privacidad-v1.0');
    RAISE EXCEPTION 'FALLO 25: aceptó un lead de popup con demo';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 25 · popup sin demo'; END;

  -- 26. Reclamos: no se saltan estados y la respuesta enviada no cambia (EDUN-302)
  INSERT INTO cms.reclamaciones (numero_expediente,tipo,nombre_reclamante,tipo_doc,numero_doc,email,producto_descripcion,descripcion,pedido,vence_en)
  VALUES ('REC-2026-0026','reclamo','Cliente','DNI','12345678','c@x.pe','Plan Inicial',repeat('x',60),'Respuesta',current_date + 21);
  BEGIN
    UPDATE cms.reclamaciones SET estado = 'cerrado' WHERE numero_expediente = 'REC-2026-0026';
    RAISE EXCEPTION 'FALLO 26: cerró un reclamo sin responderlo';
  EXCEPTION WHEN check_violation THEN NULL; END;
  UPDATE cms.reclamaciones SET estado = 'respondido', respuesta_cliente = 'Respuesta enviada al cliente.', respondido_en = now() WHERE numero_expediente = 'REC-2026-0026';
  BEGIN
    UPDATE cms.reclamaciones SET respuesta_cliente = 'Otra respuesta' WHERE numero_expediente = 'REC-2026-0026';
    RAISE EXCEPTION 'FALLO 26: cambió una respuesta ya enviada';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 26 · estados del reclamo y respuesta inmutable'; END;

  -- 27. La sección de logos no se muestra sin logos visibles (EDUN-338, 06_cms_panel_completo.sql)
  BEGIN
    SET CONSTRAINTS cms.secciones_logos IMMEDIATE;
    UPDATE cms.secciones SET visible = true WHERE clave = 'logos';
    RAISE EXCEPTION 'FALLO 27: mostró la sección de logos vacía';
  EXCEPTION WHEN check_violation THEN RAISE NOTICE 'OK 27 · sección de logos solo con logos visibles'; END;
  SET CONSTRAINTS cms.secciones_logos DEFERRED;

  -- 28. Se conservan 5 versiones por elemento (EDUN-342)
  FOR i IN 1..7 LOOP
    INSERT INTO cms.versiones (seccion, entidad, entidad_id, contenido) VALUES ('prueba', 'secciones', 'prueba', jsonb_build_object('n', i));
  END LOOP;
  IF (SELECT count(*) FROM cms.versiones WHERE seccion = 'prueba') <> 5 THEN RAISE EXCEPTION 'FALLO 28: no podó las versiones'; END IF;
  IF (SELECT min((contenido->>'n')::int) FROM cms.versiones WHERE seccion = 'prueba') <> 3 THEN RAISE EXCEPTION 'FALLO 28: borró versiones recientes'; END IF;
  RAISE NOTICE 'OK 28 · 5 versiones por elemento';

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
GRANT EXECUTE ON FUNCTION cms.seguimiento_reclamo(text, text) TO edunova_web;
SET ROLE edunova_web;
DO $$ DECLARE n int; BEGIN
  PERFORM set_config('app.rol_cms', 'publico', true);
  UPDATE cms.planes_sitio SET precio_mensual = 1 WHERE clave = 'inicial'; GET DIAGNOSTICS n = ROW_COUNT;
  IF n <> 0 THEN RAISE EXCEPTION 'FALLO 11: el público editó un precio'; END IF;
  SELECT count(*) INTO n FROM cms.leads; IF n <> 0 THEN RAISE EXCEPTION 'FALLO 12: el público leyó leads'; END IF;
  INSERT INTO cms.leads (source, email, consentimiento_version) VALUES ('popup', 'visitante@colegio.pe', 'privacidad-v1.0');
  RAISE NOTICE 'OK 11-13 · público: lee contenido, no edita, no lee leads, sí envía leads';

  -- 21. El público registra consentimientos pero no los lee (EDUN-291)
  INSERT INTO cms.consentimientos (tipo, version_politica, categorias, ip) VALUES ('cookies', 'cookies-v1.0', ARRAY['esenciales'], '203.0.113.5');
  SELECT count(*) INTO n FROM cms.consentimientos; IF n <> 0 THEN RAISE EXCEPTION 'FALLO 21: el público leyó consentimientos'; END IF;
  SELECT count(*) INTO n FROM cms.politicas; IF n < 4 THEN RAISE EXCEPTION 'FALLO 21: el público no lee las políticas'; END IF;
  RAISE NOTICE 'OK 21 · público: registra consentimientos sin leerlos y lee las políticas';

  -- 24. Reclamos: el público inserta, no lee, y el seguimiento exige el documento (EDUN-294, EDUN-298)
  INSERT INTO cms.reclamaciones (numero_expediente,tipo,nombre_reclamante,tipo_doc,numero_doc,email,producto_descripcion,descripcion,pedido,vence_en)
  VALUES ('REC-2026-0003','reclamo','Ana Pérez','DNI','87654321','ana@x.pe','Plan Inicial',repeat('x',60),'Devolución',current_date + 21);
  SELECT count(*) INTO n FROM cms.reclamaciones; IF n <> 0 THEN RAISE EXCEPTION 'FALLO 24: el público leyó reclamaciones'; END IF;
  SELECT count(*) INTO n FROM cms.seguimiento_reclamo('REC-2026-0003', '11111111'); IF n <> 0 THEN RAISE EXCEPTION 'FALLO 24: seguimiento sin el documento correcto'; END IF;
  SELECT count(*) INTO n FROM cms.seguimiento_reclamo('rec-2026-0003', '87654321'); IF n <> 1 THEN RAISE EXCEPTION 'FALLO 24: el titular no ve su estado'; END IF;
  RAISE NOTICE 'OK 24 · reclamos: el público envía y consulta el suyo, sin leer los demás';

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
