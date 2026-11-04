-- =====================================================================
-- Edunova · Pruebas de aislamiento y reglas de negocio en la base
-- Ejecutar sobre una base recién creada con schema.sql + semillas.sql:
--   psql -v ON_ERROR_STOP=1 -d edunova_test -f database/pruebas_aislamiento.sql
-- Si alguna prueba falla, el script se detiene con el número de prueba.
-- =====================================================================
\set QUIET on
\o /dev/null
BEGIN;

CREATE TEMP TABLE resultados (n int, prueba text, ok boolean);
GRANT ALL ON resultados TO edunova_app, edunova_plataforma;

-- Ejecuta SQL y verifica que falle.
CREATE OR REPLACE FUNCTION pg_temp.debe_fallar(p_sql text) RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN
  EXECUTE p_sql;
  RETURN false;
EXCEPTION WHEN OTHERS THEN
  RETURN true;
END $$;

-- ---------------------------------------------------------------- Datos de dos colegios (como dueño de la base)
INSERT INTO instituciones (slug, nombre, codigo_modular, ugel, nivel_educativo, estado)
VALUES ('smp', 'IEP San Martín de Porres', '0784512', 'UGEL Arequipa Norte', 'secundaria', 'activo'),
       ('andes', 'IEP Los Andes', '0911223', 'UGEL Huancayo', 'secundaria', 'activo');

DO $$
DECLARE i record; v_srv bigint; v_anio bigint; v_grado bigint; v_sec bigint; v_alu bigint; v_per bigint; v_area bigint; v_comp bigint; v_user bigint;
BEGIN
  SELECT id INTO v_area FROM areas_curriculares WHERE codigo = 'SEC-MAT';
  SELECT id INTO v_comp FROM competencias WHERE codigo = 'SEC-MAT-C1';
  FOR i IN SELECT * FROM instituciones ORDER BY id LOOP
    INSERT INTO servicios_educativos (institucion_id, codigo_modular, nivel) VALUES (i.id, i.codigo_modular, 'secundaria') RETURNING id INTO v_srv;
    INSERT INTO anios_escolares (institucion_id, anio, tipo_periodo, fecha_inicio, fecha_fin, estado) VALUES (i.id, 2027, 'bimestre', '2027-03-08', '2027-12-18', 'abierto') RETURNING id INTO v_anio;
    INSERT INTO periodos (institucion_id, anio_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado) VALUES (i.id, v_anio, 1, 'I Bimestre', '2027-03-08', '2027-05-09', 'abierto') RETURNING id INTO v_per;
    INSERT INTO periodos (institucion_id, anio_escolar_id, numero, nombre, fecha_inicio, fecha_fin, estado) VALUES (i.id, v_anio, 2, 'II Bimestre', '2027-05-19', '2027-07-18', 'cerrado');
    INSERT INTO grados (institucion_id, servicio_educativo_id, nivel, numero, nombre) VALUES (i.id, v_srv, 'secundaria', 3, '3.° de Secundaria') RETURNING id INTO v_grado;
    INSERT INTO area_curricular_grado (institucion_id, grado_id, area_curricular_id) VALUES (i.id, v_grado, v_area);
    INSERT INTO secciones (institucion_id, anio_escolar_id, grado_id, nombre) VALUES (i.id, v_anio, v_grado, 'A') RETURNING id INTO v_sec;
    INSERT INTO alumnos (institucion_id, codigo_estudiante_siagie, numero_documento, apellido_paterno, nombres)
      VALUES (i.id, lpad(i.id::text, 14, '0'), lpad((70000000 + i.id)::text, 8, '0'), 'Alumno', 'De ' || i.slug) RETURNING id INTO v_alu;
    INSERT INTO matriculas (institucion_id, alumno_id, anio_escolar_id, seccion_id) VALUES (i.id, v_alu, v_anio, v_sec);
    INSERT INTO calificaciones (institucion_id, alumno_id, seccion_id, competencia_id, periodo_id, valor) VALUES (i.id, v_alu, v_sec, v_comp, v_per, 'A');
    INSERT INTO salud.fichas (institucion_id, alumno_id, alergias) VALUES (i.id, v_alu, 'Registrada');
    INSERT INTO auditoria (institucion_id, accion, tabla, registro_id) VALUES (i.id, 'crear', 'alumnos', v_alu);
    INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, obligatorio, monto) VALUES (i.id, 'PEN', 'Pensión', 'pension', true, 350);
    INSERT INTO mov_rutas (institucion_id, nombre, turno) VALUES (i.id, 'Ruta 1', 'manana');
    INSERT INTO mov_recorridos (institucion_id, mov_ruta_id, fecha, sentido, estado)
      SELECT i.id, r.id, current_date, 'ida', 'en_curso' FROM mov_rutas r WHERE r.institucion_id = i.id;
    INSERT INTO mov_recorridos (institucion_id, mov_ruta_id, fecha, sentido, estado)
      SELECT i.id, r.id, current_date, 'vuelta', 'programado' FROM mov_rutas r WHERE r.institucion_id = i.id;
    INSERT INTO mov_posiciones (institucion_id, mov_recorrido_id, latitud, longitud, registrado_at)
      SELECT i.id, r.id, -16.39, -71.53, now() FROM mov_recorridos r WHERE r.institucion_id = i.id AND r.sentido = 'ida';
    UPDATE alumnos SET codigo_carne = 'CARNE-001' WHERE institucion_id = i.id;
  END LOOP;
  INSERT INTO users (nombres, apellidos, numero_documento, email) VALUES ('Rosa', 'Huamán', '40111222', 'rosa@example.com') RETURNING id INTO v_user;
  INSERT INTO membresias (institucion_id, user_id, rol_id)
    SELECT (SELECT id FROM instituciones WHERE slug = 'smp'), v_user, id FROM roles WHERE codigo = 'apoderado';
END $$;

-- Ids útiles
SELECT id AS a FROM instituciones WHERE slug = 'smp' \gset
SELECT id AS b FROM instituciones WHERE slug = 'andes' \gset
SELECT id AS sec_b FROM secciones WHERE institucion_id = :b \gset
SELECT id AS alu_a FROM alumnos WHERE institucion_id = :a \gset
SELECT id AS anio_a FROM anios_escolares WHERE institucion_id = :a \gset

-- ---------------------------------------------------------------- Como aplicación, colegio A
SET ROLE edunova_app;
SELECT set_config('app.institucion_id', :'a', true);

INSERT INTO resultados SELECT 1, 'Solo ve alumnos de su colegio', (SELECT count(*) FROM alumnos) = 1 AND (SELECT bool_and(institucion_id = :a) FROM alumnos);
INSERT INTO resultados SELECT 2, 'Solo ve su propia institución', (SELECT count(*) FROM instituciones) = 1;
INSERT INTO resultados SELECT 3, 'No puede insertar datos con otra institución',
  pg_temp.debe_fallar(format('INSERT INTO alumnos (institucion_id, apellido_paterno, nombres) VALUES (%s, %L, %L)', :b, 'X', 'Y'));
INSERT INTO resultados SELECT 4, 'No puede matricular en una sección de otro colegio (FK compuesta)',
  pg_temp.debe_fallar(format('INSERT INTO matriculas (institucion_id, alumno_id, anio_escolar_id, seccion_id) VALUES (%s, %s, %s, %s)', :a, :alu_a, :anio_a, :sec_b));
UPDATE alumnos SET nombres = 'Hackeado' WHERE institucion_id = :b;
INSERT INTO resultados SELECT 5, 'No puede modificar datos de otro colegio', NOT EXISTS (SELECT 1 FROM alumnos WHERE nombres = 'Hackeado');
INSERT INTO resultados SELECT 6, 'No puede mover un registro a otra institución',
  pg_temp.debe_fallar(format('UPDATE conceptos_cobro SET institucion_id = %s', :b));
INSERT INTO resultados SELECT 7, 'Salud oculta sin permiso', (SELECT count(*) FROM salud.fichas) = 0;
SELECT set_config('app.acceso_salud', 'on', true);
INSERT INTO resultados SELECT 8, 'Salud visible con permiso y solo del colegio', (SELECT count(*) FROM salud.fichas) = 1;
SELECT set_config('app.acceso_salud', 'off', true);
INSERT INTO resultados SELECT 9, 'Auditoría inalterable', pg_temp.debe_fallar('UPDATE auditoria SET accion = ''x''');
INSERT INTO resultados SELECT 10, 'Nota en minúsculas rechazada',
  pg_temp.debe_fallar('UPDATE calificaciones SET valor = ''ad''');
INSERT INTO resultados SELECT 11, 'Nota vigesimal de un dígito rechazada',
  pg_temp.debe_fallar('UPDATE calificaciones SET valor = ''5''');
INSERT INTO resultados SELECT 12, 'No se registran notas en periodo cerrado',
  pg_temp.debe_fallar(format('INSERT INTO calificaciones (institucion_id, alumno_id, seccion_id, competencia_id, periodo_id, valor)
     SELECT %s, %s, s.id, (SELECT id FROM competencias WHERE codigo = %L), p.id, %L FROM secciones s, periodos p WHERE p.numero = 2', :a, :alu_a, 'SEC-MAT-C2', 'A'));
INSERT INTO resultados SELECT 13, 'Concepto opcional no puede marcarse obligatorio',
  pg_temp.debe_fallar(format('INSERT INTO conceptos_cobro (institucion_id, codigo, nombre, tipo, obligatorio, monto) VALUES (%s, %L, %L, %L, true, 50)', :a, 'TAL', 'Taller', 'servicio'));
INSERT INTO resultados SELECT 14, 'Pensión no vence antes de su mes (sin cobro adelantado)',
  pg_temp.debe_fallar(format('WITH c AS (INSERT INTO cronogramas (institucion_id, anio_escolar_id, nombre) VALUES (%s, %s, %L) RETURNING id)
     INSERT INTO cronograma_cuotas (institucion_id, cronograma_id, concepto_cobro_id, numero, mes, emision, vencimiento, monto)
     SELECT %s, c.id, (SELECT id FROM conceptos_cobro LIMIT 1), 1, %L, %L, %L, 350 FROM c', :a, :anio_a, 'General', :a, '2027-04', '2027-03-01', '2027-03-31'));
INSERT INTO resultados SELECT 15, 'La app no puede crear colegios',
  pg_temp.debe_fallar('INSERT INTO instituciones (slug, nombre, codigo_modular, ugel, nivel_educativo) VALUES (''x'', ''x'', ''1234567'', ''x'', ''primaria'')');
INSERT INTO resultados SELECT 16, 'La app no puede alterar el catálogo curricular',
  pg_temp.debe_fallar('UPDATE competencias SET nombre = ''x''');
INSERT INTO resultados SELECT 17, 'Login lista las instituciones del usuario',
  (SELECT count(*) FROM fn_instituciones_de_usuario((SELECT id FROM users WHERE email = 'rosa@example.com'))) = 1;
INSERT INTO resultados SELECT 18, 'DNI con letras rechazado',
  pg_temp.debe_fallar(format('INSERT INTO alumnos (institucion_id, numero_documento, apellido_paterno, nombres) VALUES (%s, %L, %L, %L)', :a, '1234ABCD', 'X', 'Y'));

INSERT INTO resultados SELECT 22, 'GPS: solo ve posiciones de su colegio', (SELECT count(*) FROM mov_posiciones) = 1 AND (SELECT bool_and(institucion_id = :a) FROM mov_posiciones);
INSERT INTO resultados SELECT 23, 'GPS: rechaza posiciones de un recorrido no iniciado',
  pg_temp.debe_fallar(format('INSERT INTO mov_posiciones (institucion_id, mov_recorrido_id, latitud, longitud, registrado_at)
     SELECT %s, id, -16.4, -71.5, now() FROM mov_recorridos WHERE sentido = %L', :a, 'vuelta'));
INSERT INTO resultados SELECT 24, 'GPS: la última posición se copia al recorrido', (SELECT ultima_latitud = -16.39 FROM mov_recorridos WHERE sentido = 'ida');
INSERT INTO resultados SELECT 25, 'Carné QR único por colegio',
  pg_temp.debe_fallar(format('INSERT INTO alumnos (institucion_id, apellido_paterno, nombres, codigo_carne) VALUES (%s, %L, %L, %L)', :a, 'X', 'Y', 'CARNE-001'));
INSERT INTO resultados SELECT 26, 'Comedor: un solo consumo por alumno y día',
  pg_temp.debe_fallar(format('INSERT INTO com_consumos (institucion_id, alumno_id, fecha, origen) VALUES (%s, %s, current_date, %L), (%s, %s, current_date, %L)', :a, :alu_a, 'qr', :a, :alu_a, 'qr'));
INSERT INTO resultados SELECT 27, 'Convenio: mínimo 2 cuotas',
  pg_temp.debe_fallar(format('INSERT INTO convenios_fraccionamiento (institucion_id, numero, apoderado_id, alumno_id, monto_total, numero_cuotas)
     VALUES (%s, %L, (SELECT id FROM apoderados LIMIT 1), %s, 700, 1)', :a, 'CF-1', :alu_a));
INSERT INTO resultados SELECT 28, 'Convenio: las cuotas no superan el monto',
  pg_temp.debe_fallar(format('DO $x$ DECLARE v bigint; BEGIN
     INSERT INTO apoderados (institucion_id, numero_documento, apellidos, nombres) VALUES (%1$s, %2$L, %3$L, %3$L);
     INSERT INTO convenios_fraccionamiento (institucion_id, numero, apoderado_id, alumno_id, monto_total, numero_cuotas)
       VALUES (%1$s, %4$L, (SELECT max(id) FROM apoderados), %5$s, 700, 2) RETURNING id INTO v;
     INSERT INTO convenio_cuotas (institucion_id, convenio_fraccionamiento_id, numero, vencimiento, monto)
       VALUES (%1$s, v, 1, current_date, 400), (%1$s, v, 2, current_date + 30, 400);
     SET CONSTRAINTS ALL IMMEDIATE; END $x$', :a, '41111111', 'Prueba', 'CF-2', :alu_a));

INSERT INTO resultados SELECT 29, 'Tienda: precio negativo rechazado',
  pg_temp.debe_fallar(format('INSERT INTO tienda_productos (institucion_id, codigo, nombre, categoria, precio) VALUES (%s, %L, %L, %L, -1)', :a, 'P-1', 'Cuaderno', 'util'));
INSERT INTO resultados SELECT 30, 'Tienda: no puede crear productos en otro colegio',
  pg_temp.debe_fallar(format('INSERT INTO tienda_productos (institucion_id, codigo, nombre, categoria, precio) VALUES (%s, %L, %L, %L, 10)', :b, 'P-2', 'Cuaderno', 'util'));
INSERT INTO resultados SELECT 31, 'Portería: un solo ingreso por alumno y día',
  pg_temp.debe_fallar(format('INSERT INTO por_ingresos (institucion_id, alumno_id, fecha, hora, metodo, estado) VALUES (%1$s, %2$s, current_date, %3$L, %4$L, %5$L), (%1$s, %2$s, current_date, %3$L, %4$L, %5$L)', :a, :alu_a, '07:40', 'qr', 'a_tiempo'));
INSERT INTO resultados SELECT 32, 'Laboratorio: un espacio no se reserva dos veces a la misma hora',
  pg_temp.debe_fallar(format('WITH e AS (INSERT INTO lab_espacios (institucion_id, nombre, tipo) VALUES (%1$s, %2$L, %3$L) RETURNING id)
     INSERT INTO lab_reservas (institucion_id, lab_espacio_id, fecha, hora_inicio, hora_fin, docente_id)
     SELECT %1$s, e.id, current_date, %4$L, %5$L, (SELECT id FROM users LIMIT 1) FROM e, generate_series(1, 2)', :a, 'Lab. de ciencias', 'laboratorio', '08:30', '09:15'));
SELECT set_config('app.acceso_salud', 'on', true);
INSERT INTO salud.restricciones_alimentarias (institucion_id, alumno_id, tipo, detalle) VALUES (:a, :alu_a, 'alergia', 'Maní');
SELECT set_config('app.acceso_salud', 'off', true);
INSERT INTO resultados SELECT 33, 'Restricciones alimentarias ocultas sin permiso de salud', (SELECT count(*) FROM salud.restricciones_alimentarias) = 0;
INSERT INTO resultados SELECT 34, 'Aula: la encuesta solo acepta valores de 1 a 4',
  EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'aula_encuestas'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%valor >= 1%' AND pg_get_constraintdef(oid) LIKE '%valor <= 4%');

-- Sin institución activa no se ve nada.
SELECT set_config('app.institucion_id', '', true);
INSERT INTO resultados SELECT 19, 'Sin institución activa no ve datos', (SELECT count(*) FROM alumnos) = 0 AND (SELECT count(*) FROM calificaciones) = 0;
RESET ROLE;

-- Consola de plataforma ve todo.
SET ROLE edunova_plataforma;
INSERT INTO resultados SELECT 20, 'Plataforma ve ambos colegios', (SELECT count(*) FROM alumnos) = 2;
RESET ROLE;

-- Matrícula con sección de otro año.
INSERT INTO anios_escolares (institucion_id, anio, tipo_periodo, fecha_inicio, fecha_fin) VALUES (:a, 2028, 'bimestre', '2028-03-06', '2028-12-15');
INSERT INTO resultados SELECT 21, 'Matrícula exige sección del mismo año',
  pg_temp.debe_fallar(format('INSERT INTO matriculas (institucion_id, alumno_id, anio_escolar_id, seccion_id)
     SELECT %s, %s, (SELECT id FROM anios_escolares WHERE institucion_id = %s AND anio = 2028), (SELECT id FROM secciones WHERE institucion_id = %s)', :a, :alu_a, :a, :a));

\set QUIET off
\o
SELECT n AS "N.°", prueba AS "Prueba", CASE WHEN ok THEN 'OK' ELSE 'FALLA' END AS "Resultado" FROM resultados ORDER BY n;
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM resultados WHERE NOT ok) THEN
    RAISE EXCEPTION 'Fallaron pruebas: %', (SELECT string_agg(n::text, ', ') FROM resultados WHERE NOT ok);
  END IF;
END $$;
ROLLBACK;
