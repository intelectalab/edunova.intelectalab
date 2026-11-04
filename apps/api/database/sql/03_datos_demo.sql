-- =====================================================================
-- Edunova · Datos de demostración (colegio ficticio, igual al prototipo)
-- Requiere schema.sql y semillas.sql. Ejecutar como dueño de la base.
-- Todos los nombres, DNI y montos son de ejemplo.
-- =====================================================================
BEGIN;
DO $$
DECLARE
  v_inst bigint; v_srv bigint; v_anio bigint; v_grado bigint; v_sec bigint; v_sede bigint; v_amb bigint;
  v_doc_mat bigint; v_doc_com bigint; v_carga bigint; v_per1 bigint; v_per2 bigint;
  v_cuota_ing bigint; v_matr bigint; v_pens bigint; v_cron bigint; v_alu bigint; v_apo bigint; v_mat bigint;
  v_prov bigint; v_user bigint; v_area bigint; v_cg bigint;
  nombres text[] := array['Valeria|Quispe|Huamán|Rosa Huamán', 'Mateo|Condori|Paredes|Luis Condori', 'Diego|Apaza|Chávez|Marco Apaza',
                          'Sofía|Huanca|Ríos|Rubén Huanca', 'Bruno|Pari|Ticona|Nora Ticona', 'Ariana|Yupanqui|Mamani|Edith Mamani',
                          'Gael|Choque|Flores|Nora Choque', 'Camila|Rojas|Vilca|Elena Vilca'];
  notas text[] := array['AD','B','A','A','C','A','B','A'];
  p text[]; k int; m int;
BEGIN
  INSERT INTO instituciones (slug, nombre, razon_social, ruc, codigo_modular, ugel, dre, nivel_educativo, ubigeo, direccion, estado)
  VALUES ('smp', 'IEP San Martín de Porres', 'Asociación Educativa San Martín SAC', '20600000001', '0784512', 'UGEL Arequipa Norte', 'GRE Arequipa', 'multinivel', '040103', 'Av. Ejemplo 123, Cayma', 'activo')
  RETURNING id INTO v_inst;
  PERFORM set_config('app.institucion_id', v_inst::text, true);

  INSERT INTO suscripciones (institucion_id, plan_id, fecha_inicio, aula_opcion, meses_gratis, precio_congelado_hasta)
  SELECT v_inst, id, '2027-01-01', 'medida', 2, '2028-12-31' FROM planes WHERE codigo = 'P500';
  INSERT INTO suscripcion_modulos (institucion_id, suscripcion_id, modulo_id)
  SELECT v_inst, s.id, m.id FROM suscripciones s, modulos_catalogo m WHERE s.institucion_id = v_inst AND m.codigo IN ('ADM','COM','PAG','CAL','SIA','DOC','AUL','MOV','COD');

  INSERT INTO sedes (institucion_id, nombre, principal) VALUES (v_inst, 'Local principal', true) RETURNING id INTO v_sede;
  INSERT INTO ambientes (institucion_id, sede_id, nombre, capacidad) VALUES (v_inst, v_sede, 'Aula 302', 32) RETURNING id INTO v_amb;
  INSERT INTO servicios_educativos (institucion_id, codigo_modular, nivel) VALUES (v_inst, '0784512', 'secundaria') RETURNING id INTO v_srv;
  INSERT INTO servicios_educativos (institucion_id, codigo_modular, nivel) VALUES (v_inst, '0784520', 'primaria');
  INSERT INTO servicios_educativos (institucion_id, codigo_modular, nivel) VALUES (v_inst, '0784538', 'inicial');

  INSERT INTO anios_escolares (institucion_id, anio, tipo_periodo, fecha_inicio, fecha_fin, estado)
  VALUES (v_inst, 2027, 'bimestre', '2027-03-08', '2027-12-18', 'abierto') RETURNING id INTO v_anio;
  INSERT INTO periodos (institucion_id, anio_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado, fecha_limite_notas) VALUES
    (v_inst, v_anio, 1, 'I Bimestre', '2027-03-08', '2027-05-09', 'abierto', '2027-05-16') RETURNING id INTO v_per1;
  INSERT INTO periodos (institucion_id, anio_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado, fecha_limite_notas) VALUES
    (v_inst, v_anio, 2, 'II Bimestre', '2027-05-19', '2027-07-18', 'abierto', '2027-07-25') RETURNING id INTO v_per2;
  INSERT INTO periodos (institucion_id, anio_escolar_id, numero, nombre, fecha_inicio, fecha_fin) VALUES
    (v_inst, v_anio, 3, 'III Bimestre', '2027-08-04', '2027-10-03'), (v_inst, v_anio, 4, 'IV Bimestre', '2027-10-13', '2027-12-18');

  INSERT INTO grados (institucion_id, servicio_educativo_id, nivel, numero, nombre) VALUES (v_inst, v_srv, 'secundaria', 3, '3.° de Secundaria') RETURNING id INTO v_grado;
  INSERT INTO area_curricular_grado (institucion_id, grado_id, area_curricular_id, orden)
  SELECT v_inst, v_grado, id, orden FROM areas_curriculares WHERE nivel = 'secundaria';

  INSERT INTO personal (institucion_id, numero_documento, apellidos, nombres, cargo, especialidad) VALUES
    (v_inst, '41000001', 'Torres', 'Ana', 'Docente', 'Matemática') RETURNING id INTO v_doc_mat;
  INSERT INTO personal (institucion_id, numero_documento, apellidos, nombres, cargo, especialidad) VALUES
    (v_inst, '41000002', 'Ramos', 'Julio', 'Docente', 'Comunicación') RETURNING id INTO v_doc_com;
  INSERT INTO secciones (institucion_id, anio_escolar_id, grado_id, nombre, ambiente_id, tutor_personal_id, vacantes)
  VALUES (v_inst, v_anio, v_grado, 'A', v_amb, v_doc_mat, 32) RETURNING id INTO v_sec;

  SELECT id INTO v_area FROM areas_curriculares WHERE codigo = 'SEC-MAT';
  INSERT INTO cargas_docentes (institucion_id, seccion_id, area_curricular_id, personal_id, horas_semanales) VALUES (v_inst, v_sec, v_area, v_doc_mat, 6) RETURNING id INTO v_carga;
  INSERT INTO cargas_docentes (institucion_id, seccion_id, area_curricular_id, personal_id, horas_semanales)
  SELECT v_inst, v_sec, id, v_doc_com, 5 FROM areas_curriculares WHERE codigo = 'SEC-COM';
  INSERT INTO aula_cursos (institucion_id, carga_docente_id, titulo) VALUES (v_inst, v_carga, 'Matemática 3.° A');

  INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, obligatorio, monto) VALUES (v_inst, 'ING', 'Cuota de ingreso', 'cuota_ingreso', true, 800) RETURNING id INTO v_cuota_ing;
  INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, obligatorio, monto) VALUES (v_inst, 'MAT', 'Matrícula 2027', 'matricula', true, 350) RETURNING id INTO v_matr;
  INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, obligatorio, monto) VALUES (v_inst, 'PEN', 'Pensión mensual', 'pension', true, 350) RETURNING id INTO v_pens;
  INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, monto) VALUES (v_inst, 'ROB', 'Taller de robótica', 'servicio', 120);
  INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, monto) VALUES (v_inst, 'CON', 'Constancia de estudios', 'tramite', 25);

  INSERT INTO cronogramas (institucion_id, anio_escolar_id, nombre) VALUES (v_inst, v_anio, 'Pensiones 2027') RETURNING id INTO v_cron;
  FOR m IN 3..12 LOOP
    INSERT INTO cronograma_cuotas (institucion_id, cronograma_id, concepto_cobro_id, numero, mes, emision, vencimiento, monto)
    VALUES (v_inst, v_cron, v_pens, m - 2, '2027-' || lpad(m::text, 2, '0'), make_date(2027, m, 1),
            (make_date(2027, m, 1) + interval '1 month - 1 day')::date, 350);
  END LOOP;

  INSERT INTO beneficios (institucion_id, nombre, tipo, valor, motivo) VALUES (v_inst, 'Descuento hermanos', 'porcentaje', 10, 'hermanos'),
    (v_inst, 'Pronto pago', 'monto', 15, 'pronto_pago'), (v_inst, 'Beca por orfandad', 'porcentaje', 100, 'orfandad');

  INSERT INTO proveedores_facturacion (institucion_id, proveedor, ambiente, credenciales_cifradas, serie_boleta, serie_factura, serie_nota_credito, predeterminado)
  VALUES (v_inst, 'proveedor_a', 'beta', 'CIFRADO-DEMO', 'B001', 'F001', 'BC01', true) RETURNING id INTO v_prov;
  INSERT INTO cajas (institucion_id, nombre, sede_id) VALUES (v_inst, 'Caja principal', v_sede);

  FOR k IN 1..array_length(nombres, 1) LOOP
    p := string_to_array(nombres[k], '|');
    INSERT INTO alumnos (institucion_id, codigo_estudiante_siagie, numero_documento, apellido_paterno, apellido_materno, nombres, sexo, fecha_nacimiento)
    VALUES (v_inst, '000000784512' || lpad(k::text, 2, '0'), (78451230 + k)::text, p[2], p[3], p[1], CASE WHEN k IN (1,4,6,8) THEN 'M' ELSE 'H' END, make_date(2012, 1 + k, 10))
    RETURNING id INTO v_alu;
    INSERT INTO apoderados (institucion_id, numero_documento, apellidos, nombres, celular)
    VALUES (v_inst, (40000000 + k)::text, split_part(p[4], ' ', 2), split_part(p[4], ' ', 1), '9' || lpad((58000000 + k)::text, 8, '0'))
    RETURNING id INTO v_apo;
    INSERT INTO alumno_apoderado (institucion_id, alumno_id, apoderado_id, parentesco, es_responsable_pago) VALUES (v_inst, v_alu, v_apo, 'madre/padre', true);
    INSERT INTO matriculas (institucion_id, alumno_id, anio_escolar_id, seccion_id, origen) VALUES (v_inst, v_alu, v_anio, v_sec, 'siagie') RETURNING id INTO v_mat;
    INSERT INTO consentimientos (institucion_id, titular_tipo, titular_id, finalidad, version_texto, otorgado) VALUES (v_inst, 'alumno', v_alu, 'datos_e_imagen', '2027.1', true);

    INSERT INTO cargos (institucion_id, alumno_id, matricula_id, concepto_cobro_id, cronograma_cuota_id, descripcion, emision, vencimiento, monto, pagado, estado)
    SELECT v_inst, v_alu, v_mat, v_pens, c.id, 'Pensión ' || c.mes, c.emision, c.vencimiento, c.monto,
           CASE WHEN c.numero <= 7 OR k IN (1,3,4,6) THEN c.monto ELSE 0 END,
           CASE WHEN c.numero <= 7 OR k IN (1,3,4,6) THEN 'pagado' ELSE 'pendiente' END
    FROM cronograma_cuotas c WHERE c.cronograma_id = v_cron AND c.numero <= 8;

    INSERT INTO calificaciones (institucion_id, alumno_id, seccion_id, competencia_id, periodo_id, valor, conclusion_descriptiva)
    SELECT v_inst, v_alu, v_sec, c.id, v_per2, notas[k],
           CASE WHEN notas[k] = 'C' THEN 'Requiere acompañamiento en la resolución de problemas; se propone refuerzo semanal.' END
    FROM competencias c JOIN areas_curriculares a ON a.id = c.area_curricular_id WHERE a.codigo = 'SEC-MAT';
  END LOOP;

  INSERT INTO users (nombres, apellidos, numero_documento, email) VALUES ('María', 'Cárdenas', '29000001', 'director@demo.edunova.pe') RETURNING id INTO v_user;
  INSERT INTO membresias (institucion_id, user_id, rol_id) SELECT v_inst, v_user, id FROM roles WHERE codigo IN ('director', 'promotor');

  INSERT INTO comunicados (institucion_id, titulo, contenido, alcance, canales, autor_id, estado, enviado_at)
  VALUES (v_inst, 'Reunión de entrega de libretas', 'Estimados padres: el viernes 6 de noviembre a las 6 p. m. entregaremos las libretas.',
          '{"tipo":"institucion"}', 'app,whatsapp,email', v_user, 'enviado', now());
  INSERT INTO tramite_tipos (institucion_id, nombre, concepto_cobro_id, plazo_dias) SELECT v_inst, 'Constancia de estudios', id, 2 FROM conceptos_cobro WHERE institucion_id = v_inst AND codigo = 'CON';
  INSERT INTO tramite_tipos (institucion_id, nombre, plazo_dias, requiere_sin_deuda) VALUES (v_inst, 'Constancia de no adeudo', 1, true);
END $$;
COMMIT;
