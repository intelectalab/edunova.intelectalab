-- =====================================================================
-- Edunova · Esquema PostgreSQL 16 (GENERADO por tools/gen.py: no editar a mano)
-- 171 tablas · aislamiento por institución con RLS forzado
-- =====================================================================
BEGIN;
CREATE SCHEMA IF NOT EXISTS salud;

DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_app') THEN
    CREATE ROLE edunova_app LOGIN NOBYPASSRLS;           -- la aplicación Laravel
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_plataforma') THEN
    CREATE ROLE edunova_plataforma LOGIN BYPASSRLS;      -- consola interna de Edunova
  END IF;
END $$;

-- Institución activa de la sesión. Laravel la fija en cada petición y en cada job:
--   SET LOCAL app.institucion_id = '<id>';  (dentro de la transacción)
CREATE OR REPLACE FUNCTION app_institucion() RETURNS bigint
LANGUAGE sql STABLE AS $f$ SELECT nullif(current_setting('app.institucion_id', true), '')::bigint $f$;

-- Acceso a datos de salud (solo roles enfermería, tutor de la sección y dirección).
CREATE OR REPLACE FUNCTION app_acceso_salud() RETURNS boolean
LANGUAGE sql STABLE AS $f$ SELECT coalesce(current_setting('app.acceso_salud', true), 'off') = 'on' $f$;

CREATE OR REPLACE FUNCTION tg_updated_at() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN NEW.updated_at := now(); RETURN NEW; END $f$;


-- [PLA] Tenant: colegio que contrata Edunova. RLS especial: cada sesión solo ve su propia fila.
CREATE TABLE instituciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug varchar(60) NOT NULL UNIQUE,
  nombre varchar(200) NOT NULL,
  razon_social varchar(200),
  ruc char(11) UNIQUE CHECK (ruc ~ '^[0-9]{11}$'),
  codigo_modular char(7) NOT NULL UNIQUE CHECK (codigo_modular ~ '^[0-9]{7}$'),
  codigo_local char(6),
  ugel varchar(120) NOT NULL,
  dre varchar(120),
  nivel_educativo varchar(20) NOT NULL CHECK (nivel_educativo in ('inicial','primaria','secundaria','multinivel')),
  ubigeo char(6),
  direccion varchar(250),
  telefono varchar(20),
  correo varchar(150),
  logo_archivo_id bigint,
  color_primario char(7),
  subdominio varchar(60) UNIQUE,
  zona_horaria varchar(40) NOT NULL DEFAULT 'America/Lima',
  estado varchar(20) NOT NULL DEFAULT 'implementacion' CHECK (estado in ('implementacion','activo','suspendido','baja')),
  configuracion jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_instituciones_upd BEFORE UPDATE ON instituciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Planes comerciales por tamaño (precios por definir; ver docs/04-plataforma-saas.md).
CREATE TABLE planes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(30) NOT NULL UNIQUE,
  nombre varchar(80) NOT NULL,
  alumnos_min int NOT NULL DEFAULT 0,
  alumnos_max int,
  precio_mensual numeric(10,2),
  moneda char(3) NOT NULL DEFAULT 'PEN',
  precio_comprobante numeric(8,4),
  comprobantes_incluidos int NOT NULL DEFAULT 0,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_planes_upd BEFORE UPDATE ON planes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Módulos activables por institución.
CREATE TABLE modulos_catalogo (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(10) NOT NULL UNIQUE,
  nombre varchar(80) NOT NULL,
  tipo varchar(15) NOT NULL CHECK (tipo in ('base','extra','aula','integracion')),
  precio_mensual numeric(10,2),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_modulos_catalogo_upd BEFORE UPDATE ON modulos_catalogo FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Contrato vigente de la institución con Edunova.
CREATE TABLE suscripciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  plan_id bigint NOT NULL,
  fecha_inicio date NOT NULL,
  fecha_fin date,
  precio_pactado numeric(10,2),
  meses_gratis smallint NOT NULL DEFAULT 0,
  precio_congelado_hasta date,
  aula_opcion varchar(10) NOT NULL DEFAULT 'medida' CHECK (aula_opcion in ('medida','moodle','ambas','ninguna')),
  estado varchar(15) NOT NULL DEFAULT 'activa' CHECK (estado in ('prueba','activa','vencida','cancelada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_suscripciones_upd BEFORE UPDATE ON suscripciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Módulos encendidos para la institución.
CREATE TABLE suscripcion_modulos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  suscripcion_id bigint NOT NULL,
  modulo_id bigint NOT NULL,
  activo boolean NOT NULL DEFAULT true,
  activado_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (suscripcion_id, modulo_id)
);
CREATE TRIGGER trg_suscripcion_modulos_upd BEFORE UPDATE ON suscripcion_modulos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Cobro mensual de Edunova a la institución.
CREATE TABLE facturas_saas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  suscripcion_id bigint NOT NULL,
  periodo char(7) NOT NULL CHECK (periodo ~ '^[0-9]{4}-[0-9]{2}$'),
  alumnos_activos int NOT NULL,
  monto numeric(10,2) NOT NULL,
  comprobante_serie_numero varchar(20),
  monto_suscripcion numeric(10,2) NOT NULL DEFAULT 0,
  comprobantes_emitidos int NOT NULL DEFAULT 0,
  comprobantes_cobrados int NOT NULL DEFAULT 0,
  monto_comprobantes numeric(10,2) NOT NULL DEFAULT 0,
  estado varchar(15) NOT NULL DEFAULT 'emitida' CHECK (estado in ('emitida','pagada','vencida','anulada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (suscripcion_id, periodo)
);
CREATE TRIGGER trg_facturas_saas_upd BEFORE UPDATE ON facturas_saas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Tiempos de respuesta y resolución comprometidos por prioridad (horas hábiles). Valores sugeridos, por confirmar.
CREATE TABLE sla_politicas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  prioridad varchar(10) NOT NULL UNIQUE CHECK (prioridad in ('baja','media','alta','critica')),
  horas_primera_respuesta smallint NOT NULL CHECK (horas_primera_respuesta > 0),
  horas_resolucion smallint NOT NULL CHECK (horas_resolucion > 0),
  solo_horario_habil boolean NOT NULL DEFAULT true,
  descripcion varchar(250),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CHECK (horas_resolucion >= horas_primera_respuesta)
);
CREATE TRIGGER trg_sla_politicas_upd BEFORE UPDATE ON sla_politicas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Solicitudes de soporte de la institución, con control de SLA.
CREATE TABLE tickets_soporte (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  numero varchar(15) NOT NULL,
  asunto varchar(200) NOT NULL,
  descripcion text,
  categoria varchar(20) NOT NULL DEFAULT 'consulta' CHECK (categoria in ('consulta','incidente','error','solicitud','capacitacion','facturacion')),
  prioridad varchar(10) NOT NULL DEFAULT 'media' CHECK (prioridad in ('baja','media','alta','critica')),
  estado varchar(15) NOT NULL DEFAULT 'abierto' CHECK (estado in ('abierto','en_curso','esperando','resuelto','cerrado')),
  creado_por bigint NOT NULL,
  asignado_a bigint,
  sla_politica_id bigint,
  vence_primera_respuesta_at timestamptz,
  primera_respuesta_at timestamptz,
  vence_resolucion_at timestamptz,
  resuelto_at timestamptz,
  pausado_minutos int NOT NULL DEFAULT 0,
  calificacion smallint CHECK (calificacion between 1 and 5),
  comentario_calificacion varchar(500),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, numero)
);
CREATE TRIGGER trg_tickets_soporte_upd BEFORE UPDATE ON tickets_soporte FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Conversación del ticket.
CREATE TABLE ticket_mensajes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  ticket_id bigint NOT NULL,
  autor_id bigint NOT NULL,
  mensaje text NOT NULL,
  interno boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_ticket_mensajes_upd BEFORE UPDATE ON ticket_mensajes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PLA] Valores normativos que cambian por año (UIT, plazos). Cada uno con fuente.
CREATE TABLE parametros_legales (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(60) NOT NULL,
  valor varchar(200) NOT NULL,
  vigente_desde date NOT NULL,
  fuente varchar(250) NOT NULL,
  confirmado boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (codigo, vigente_desde)
);
CREATE TRIGGER trg_parametros_legales_upd BEFORE UPDATE ON parametros_legales FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Persona con acceso (global: un mismo padre o docente puede estar en varios colegios).
CREATE TABLE users (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nombres varchar(120) NOT NULL,
  apellidos varchar(160) NOT NULL,
  tipo_documento varchar(15) NOT NULL DEFAULT 'DNI',
  numero_documento varchar(15),
  email varchar(150) UNIQUE,
  celular varchar(15),
  password varchar(255),
  dos_pasos_secreto text,
  email_verificado_at timestamptz,
  es_staff_plataforma boolean NOT NULL DEFAULT false,
  ultimo_acceso_at timestamptz,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tipo_documento, numero_documento)
);
CREATE TRIGGER trg_users_upd BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Roles del sistema (ver docs/03-roles-y-permisos.md).
CREATE TABLE roles (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(30) NOT NULL UNIQUE,
  nombre varchar(60) NOT NULL,
  ambito varchar(15) NOT NULL CHECK (ambito in ('plataforma','institucion')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_roles_upd BEFORE UPDATE ON roles FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Permisos atómicos modulo.recurso.accion.
CREATE TABLE permisos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(80) NOT NULL UNIQUE,
  modulo varchar(5) NOT NULL,
  descripcion varchar(200) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_permisos_upd BEFORE UPDATE ON permisos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Permisos por rol.
CREATE TABLE rol_permiso (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  rol_id bigint NOT NULL,
  permiso_id bigint NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (rol_id, permiso_id)
);
CREATE TRIGGER trg_rol_permiso_upd BEFORE UPDATE ON rol_permiso FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Un usuario dentro de una institución con un rol.
CREATE TABLE membresias (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint NOT NULL,
  rol_id bigint NOT NULL,
  activo boolean NOT NULL DEFAULT true,
  desde date NOT NULL DEFAULT current_date,
  hasta date,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (user_id, rol_id)
);
CREATE TRIGGER trg_membresias_upd BEFORE UPDATE ON membresias FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Token de notificaciones push de la app móvil por usuario.
CREATE TABLE dispositivos_moviles (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id bigint NOT NULL,
  plataforma varchar(10) NOT NULL CHECK (plataforma in ('ios','android')),
  token_push text NOT NULL UNIQUE,
  app_version varchar(20),
  ultimo_uso_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_dispositivos_moviles_upd BEFORE UPDATE ON dispositivos_moviles FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Registro inalterable de cambios relevantes (solo INSERT).
CREATE TABLE auditoria (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint,
  accion varchar(40) NOT NULL,
  tabla varchar(60) NOT NULL,
  registro_id bigint,
  antes jsonb,
  despues jsonb,
  ip inet,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);

-- [SEG] Consentimientos de tratamiento de datos e imagen (Ley 29733).
CREATE TABLE consentimientos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titular_tipo varchar(15) NOT NULL CHECK (titular_tipo in ('alumno','apoderado','personal')),
  titular_id bigint NOT NULL,
  otorgado_por bigint,
  finalidad varchar(40) NOT NULL,
  version_texto varchar(20) NOT NULL,
  otorgado boolean NOT NULL,
  fecha timestamptz NOT NULL DEFAULT now(),
  revocado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_consentimientos_upd BEFORE UPDATE ON consentimientos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SEG] Metadatos de archivos en almacenamiento de objetos (compatible con S3).
CREATE TABLE archivos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  disco varchar(20) NOT NULL DEFAULT 'objetos',
  ruta varchar(400) NOT NULL,
  nombre_original varchar(250) NOT NULL,
  mime varchar(100) NOT NULL,
  bytes bigint NOT NULL,
  sha256 char(64) NOT NULL,
  subido_por bigint,
  confidencial boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_archivos_upd BEFORE UPDATE ON archivos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CUR] Áreas del Currículo Nacional por nivel.
CREATE TABLE areas_curriculares (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo varchar(20) NOT NULL UNIQUE,
  nombre varchar(120) NOT NULL,
  nivel varchar(20) NOT NULL CHECK (nivel in ('inicial','primaria','secundaria')),
  es_transversal boolean NOT NULL DEFAULT false,
  orden smallint NOT NULL DEFAULT 0,
  vigente boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (nombre, nivel)
);
CREATE TRIGGER trg_areas_curriculares_upd BEFORE UPDATE ON areas_curriculares FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CUR] Competencias oficiales del CNEB.
CREATE TABLE competencias (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  area_curricular_id bigint NOT NULL,
  codigo varchar(20) NOT NULL UNIQUE,
  numero smallint NOT NULL,
  nombre varchar(300) NOT NULL,
  nombre_corto varchar(80),
  orden smallint NOT NULL DEFAULT 0,
  vigente boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (area_curricular_id, numero)
);
CREATE TRIGGER trg_competencias_upd BEFORE UPDATE ON competencias FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Un nivel del colegio con su código modular MINEDU.
CREATE TABLE servicios_educativos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  codigo_modular char(7) NOT NULL UNIQUE CHECK (codigo_modular ~ '^[0-9]{7}$'),
  anexo char(1) NOT NULL DEFAULT '0',
  nivel varchar(20) NOT NULL CHECK (nivel in ('inicial','primaria','secundaria')),
  modalidad varchar(10) NOT NULL DEFAULT 'EBR',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, nivel, modalidad)
);
CREATE TRIGGER trg_servicios_educativos_upd BEFORE UPDATE ON servicios_educativos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Locales del colegio.
CREATE TABLE sedes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(120) NOT NULL,
  codigo_local char(6),
  direccion varchar(250),
  principal boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_sedes_upd BEFORE UPDATE ON sedes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Aulas físicas, laboratorios, losas.
CREATE TABLE ambientes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  sede_id bigint NOT NULL,
  nombre varchar(60) NOT NULL,
  tipo varchar(20) NOT NULL DEFAULT 'aula',
  capacidad smallint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_ambientes_upd BEFORE UPDATE ON ambientes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Año lectivo.
CREATE TABLE anios_escolares (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio smallint NOT NULL CHECK (anio between 2020 and 2100),
  tipo_periodo varchar(10) NOT NULL CHECK (tipo_periodo in ('bimestre','trimestre')),
  fecha_inicio date NOT NULL,
  fecha_fin date NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'planificado' CHECK (estado in ('planificado','abierto','cerrado')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, anio),
  CHECK (fecha_fin > fecha_inicio)
);
CREATE TRIGGER trg_anios_escolares_upd BEFORE UPDATE ON anios_escolares FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Bimestres o trimestres.
CREATE TABLE periodos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  numero smallint NOT NULL CHECK (numero between 1 and 4),
  nombre varchar(40) NOT NULL,
  fecha_inicio date NOT NULL,
  fecha_fin date NOT NULL,
  fecha_limite_notas date,
  estado varchar(12) NOT NULL DEFAULT 'programado' CHECK (estado in ('programado','abierto','cerrado')),
  cerrado_at timestamptz,
  cerrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (anio_escolar_id, numero)
);
CREATE TRIGGER trg_periodos_upd BEFORE UPDATE ON periodos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Días no lectivos.
CREATE TABLE feriados (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  fecha date NOT NULL,
  motivo varchar(120) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (anio_escolar_id, fecha)
);
CREATE TRIGGER trg_feriados_upd BEFORE UPDATE ON feriados FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Grados por nivel.
CREATE TABLE grados (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  servicio_educativo_id bigint NOT NULL,
  nivel varchar(20) NOT NULL,
  numero smallint NOT NULL,
  nombre varchar(40) NOT NULL,
  escala_calificacion varchar(10) NOT NULL DEFAULT 'literal' CHECK (escala_calificacion in ('literal','vigesimal')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (servicio_educativo_id, numero)
);
CREATE TRIGGER trg_grados_upd BEFORE UPDATE ON grados FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Plan de estudios: áreas que se califican en el grado.
CREATE TABLE area_curricular_grado (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  area_curricular_id bigint NOT NULL,
  horas_semanales smallint,
  orden smallint NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (grado_id, area_curricular_id)
);
CREATE TRIGGER trg_area_curricular_grado_upd BEFORE UPDATE ON area_curricular_grado FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Sección de un grado en un año.
CREATE TABLE secciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  nombre varchar(10) NOT NULL,
  turno varchar(10) NOT NULL DEFAULT 'manana' CHECK (turno in ('manana','tarde','noche')),
  ambiente_id bigint,
  tutor_personal_id bigint,
  vacantes smallint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (anio_escolar_id, grado_id, nombre)
);
CREATE TRIGGER trg_secciones_upd BEFORE UPDATE ON secciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Docentes y administrativos.
CREATE TABLE personal (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint,
  tipo_documento varchar(15) NOT NULL DEFAULT 'DNI',
  numero_documento varchar(15) NOT NULL,
  apellidos varchar(160) NOT NULL,
  nombres varchar(120) NOT NULL,
  cargo varchar(60) NOT NULL,
  especialidad varchar(80),
  tipo_contrato varchar(20),
  contrato_fin date,
  colegiatura varchar(30),
  estado varchar(10) NOT NULL DEFAULT 'activo' CHECK (estado in ('activo','cesado')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, tipo_documento, numero_documento)
);
CREATE TRIGGER trg_personal_upd BEFORE UPDATE ON personal FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Qué docente dicta qué área en qué sección.
CREATE TABLE cargas_docentes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  area_curricular_id bigint NOT NULL,
  personal_id bigint NOT NULL,
  horas_semanales smallint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (seccion_id, area_curricular_id)
);
CREATE TRIGGER trg_cargas_docentes_upd BEFORE UPDATE ON cargas_docentes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Bloques de hora del turno.
CREATE TABLE bloques_horarios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  turno varchar(10) NOT NULL,
  orden smallint NOT NULL,
  hora_inicio time NOT NULL,
  hora_fin time NOT NULL,
  es_recreo boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, turno, orden)
);
CREATE TRIGGER trg_bloques_horarios_upd BEFORE UPDATE ON bloques_horarios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Celda del horario. El índice único impide cruces de docente.
CREATE TABLE horario_sesiones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  dia_semana smallint NOT NULL CHECK (dia_semana between 1 and 6),
  bloque_horario_id bigint NOT NULL,
  carga_docente_id bigint NOT NULL,
  personal_id bigint NOT NULL,
  ambiente_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (seccion_id, dia_semana, bloque_horario_id),
  UNIQUE (personal_id, dia_semana, bloque_horario_id)
);
CREATE TRIGGER trg_horario_sesiones_upd BEFORE UPDATE ON horario_sesiones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Estudiante. La sección vive en matriculas (una por año).
CREATE TABLE alumnos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  codigo_estudiante_siagie char(14) CHECK (codigo_estudiante_siagie ~ '^[0-9]{14}$'),
  tipo_documento varchar(15) NOT NULL DEFAULT 'DNI',
  numero_documento varchar(15),
  apellido_paterno varchar(80) NOT NULL,
  apellido_materno varchar(80),
  nombres varchar(120) NOT NULL,
  sexo char(1) CHECK (sexo in ('H','M')),
  fecha_nacimiento date,
  foto_archivo_id bigint,
  codigo_carne varchar(24),
  user_id bigint,
  estado varchar(12) NOT NULL DEFAULT 'activo' CHECK (estado in ('activo','retirado','egresado')),
  eliminado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, codigo_estudiante_siagie),
  UNIQUE (institucion_id, tipo_documento, numero_documento),
  UNIQUE (institucion_id, codigo_carne),
  CHECK (tipo_documento <> 'DNI' or numero_documento is null or numero_documento ~ '^[0-9]{8}$')
);
CREATE TRIGGER trg_alumnos_upd BEFORE UPDATE ON alumnos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Padres o apoderados.
CREATE TABLE apoderados (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint,
  tipo_documento varchar(15) NOT NULL DEFAULT 'DNI',
  numero_documento varchar(15) NOT NULL,
  apellidos varchar(160) NOT NULL,
  nombres varchar(120) NOT NULL,
  celular varchar(15),
  email varchar(150),
  direccion varchar(250),
  ocupacion varchar(80),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, tipo_documento, numero_documento)
);
CREATE TRIGGER trg_apoderados_upd BEFORE UPDATE ON apoderados FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Vínculo alumno–apoderado.
CREATE TABLE alumno_apoderado (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  parentesco varchar(20) NOT NULL,
  es_responsable_pago boolean NOT NULL DEFAULT false,
  puede_recoger boolean NOT NULL DEFAULT true,
  recibe_notificaciones boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, apoderado_id)
);
CREATE TRIGGER trg_alumno_apoderado_upd BEFORE UPDATE ON alumno_apoderado FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Campaña de admisión para un año.
CREATE TABLE admision_procesos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  nombre varchar(80) NOT NULL,
  fecha_inicio date NOT NULL,
  fecha_fin date NOT NULL,
  estado varchar(10) NOT NULL DEFAULT 'abierto',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_admision_procesos_upd BEFORE UPDATE ON admision_procesos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Vacantes por grado y año.
CREATE TABLE vacantes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  cantidad smallint NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (anio_escolar_id, grado_id)
);
CREATE TRIGGER trg_vacantes_upd BEFORE UPDATE ON vacantes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Embudo de admisión.
CREATE TABLE postulantes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  admision_proceso_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  apellidos varchar(160) NOT NULL,
  nombres varchar(120) NOT NULL,
  numero_documento varchar(15),
  contacto_nombre varchar(160) NOT NULL,
  contacto_celular varchar(15) NOT NULL,
  contacto_email varchar(150),
  etapa varchar(15) NOT NULL DEFAULT 'interesado' CHECK (etapa in ('interesado','entrevista','evaluacion','vacante','matriculado','desistio')),
  alumno_id bigint,
  origen varchar(30),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_postulantes_upd BEFORE UPDATE ON postulantes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Entrevistas, evaluaciones y cambios de etapa.
CREATE TABLE postulante_eventos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  postulante_id bigint NOT NULL,
  tipo varchar(20) NOT NULL,
  fecha timestamptz NOT NULL,
  resultado text,
  responsable_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_postulante_eventos_upd BEFORE UPDATE ON postulante_eventos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Alumno en una sección para un año (espejo de la nómina SIAGIE).
CREATE TABLE matriculas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  fecha_matricula date NOT NULL DEFAULT current_date,
  tipo varchar(12) NOT NULL DEFAULT 'ratificacion' CHECK (tipo in ('nuevo','ratificacion','traslado')),
  estado varchar(12) NOT NULL DEFAULT 'matriculado' CHECK (estado in ('reservado','matriculado','retirado','trasladado')),
  origen varchar(10) NOT NULL DEFAULT 'manual' CHECK (origen in ('manual','siagie','admision')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, anio_escolar_id)
);
CREATE TRIGGER trg_matriculas_upd BEFORE UPDATE ON matriculas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Texto de condiciones económicas por año (Ley 26549).
CREATE TABLE condiciones_economicas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  version smallint NOT NULL,
  contenido text NOT NULL,
  publicado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (anio_escolar_id, version)
);
CREATE TRIGGER trg_condiciones_economicas_upd BEFORE UPDATE ON condiciones_economicas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Aceptación del apoderado antes de matricular.
CREATE TABLE condiciones_aceptaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  condiciones_economicas_id bigint NOT NULL,
  matricula_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  aceptado_at timestamptz NOT NULL DEFAULT now(),
  ip inet,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (matricula_id)
);
CREATE TRIGGER trg_condiciones_aceptaciones_upd BEFORE UPDATE ON condiciones_aceptaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [ADM] Retiros, traslados y cambios de sección.
CREATE TABLE movimientos_matricula (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  matricula_id bigint NOT NULL,
  tipo varchar(15) NOT NULL CHECK (tipo in ('retiro','traslado_salida','traslado_ingreso','cambio_seccion')),
  fecha date NOT NULL,
  motivo text,
  ie_destino varchar(200),
  seccion_destino_id bigint,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_movimientos_matricula_upd BEFORE UPDATE ON movimientos_matricula FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Comunicado masivo con alcance (institución, nivel, sección, filtro).
CREATE TABLE comunicados (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titulo varchar(200) NOT NULL,
  contenido text NOT NULL,
  alcance jsonb NOT NULL,
  canales varchar(60) NOT NULL DEFAULT 'app',
  requiere_confirmacion boolean NOT NULL DEFAULT false,
  programado_para timestamptz,
  enviado_at timestamptz,
  autor_id bigint NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'borrador' CHECK (estado in ('borrador','programado','enviado','anulado')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_comunicados_upd BEFORE UPDATE ON comunicados FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Entrega y lectura por apoderado.
CREATE TABLE comunicado_destinatarios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  comunicado_id bigint NOT NULL,
  user_id bigint NOT NULL,
  alumno_id bigint,
  entregado_at timestamptz,
  leido_at timestamptz,
  confirmado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (comunicado_id, user_id, alumno_id)
);
CREATE TRIGGER trg_comunicado_destinatarios_upd BEFORE UPDATE ON comunicado_destinatarios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Mensajería 1 a 1 (padre–tutor, padre–dirección).
CREATE TABLE conversaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  asunto varchar(200) NOT NULL,
  alumno_id bigint,
  cerrada boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_conversaciones_upd BEFORE UPDATE ON conversaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Participantes de la conversación.
CREATE TABLE conversacion_participantes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  conversacion_id bigint NOT NULL,
  user_id bigint NOT NULL,
  ultimo_leido_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (conversacion_id, user_id)
);
CREATE TRIGGER trg_conversacion_participantes_upd BEFORE UPDATE ON conversacion_participantes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Mensajes de una conversación.
CREATE TABLE mensajes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  conversacion_id bigint NOT NULL,
  autor_id bigint NOT NULL,
  contenido text NOT NULL,
  archivo_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mensajes_upd BEFORE UPDATE ON mensajes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Agenda institucional.
CREATE TABLE eventos_calendario (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titulo varchar(200) NOT NULL,
  descripcion text,
  inicio timestamptz NOT NULL,
  fin timestamptz,
  alcance jsonb NOT NULL DEFAULT '{}'::jsonb,
  tipo varchar(20) NOT NULL DEFAULT 'evento',
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_eventos_calendario_upd BEFORE UPDATE ON eventos_calendario FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Citas de padres con tutor, psicología o dirección.
CREATE TABLE citas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  personal_id bigint NOT NULL,
  inicio timestamptz NOT NULL,
  motivo varchar(200) NOT NULL,
  acuerdos text,
  estado varchar(12) NOT NULL DEFAULT 'solicitada' CHECK (estado in ('solicitada','confirmada','realizada','cancelada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_citas_upd BEFORE UPDATE ON citas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Libro de reclamaciones virtual (Indecopi).
CREATE TABLE reclamaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  numero varchar(15) NOT NULL,
  tipo varchar(10) NOT NULL CHECK (tipo in ('reclamo','queja')),
  consumidor_nombre varchar(200) NOT NULL,
  consumidor_documento varchar(15) NOT NULL,
  consumidor_email varchar(150) NOT NULL,
  consumidor_celular varchar(15),
  detalle text NOT NULL,
  pedido text NOT NULL,
  monto_reclamado numeric(10,2),
  fecha_limite date NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'abierto' CHECK (estado in ('abierto','respondido')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, numero)
);
CREATE TRIGGER trg_reclamaciones_upd BEFORE UPDATE ON reclamaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Respuesta del proveedor.
CREATE TABLE reclamacion_respuestas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  reclamacion_id bigint NOT NULL,
  respuesta text NOT NULL,
  respondido_por bigint NOT NULL,
  enviado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (reclamacion_id)
);
CREATE TRIGGER trg_reclamacion_respuestas_upd BEFORE UPDATE ON reclamacion_respuestas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COM] Bitácora de envíos (push, WhatsApp, correo).
CREATE TABLE notificaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint NOT NULL,
  canal varchar(10) NOT NULL CHECK (canal in ('push','whatsapp','email','sms')),
  plantilla varchar(60) NOT NULL,
  datos jsonb NOT NULL DEFAULT '{}'::jsonb,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','enviado','fallido','leido')),
  costo numeric(8,4),
  enviado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_notificaciones_upd BEFORE UPDATE ON notificaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Conceptos. Indecopi: obligatorios solo cuota de ingreso, matrícula y pensión.
CREATE TABLE conceptos_cobro (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  codigo varchar(20) NOT NULL,
  nombre varchar(120) NOT NULL,
  tipo varchar(15) NOT NULL CHECK (tipo in ('cuota_ingreso','matricula','pension','servicio','tramite','otro')),
  obligatorio boolean NOT NULL DEFAULT false,
  monto numeric(10,2) NOT NULL CHECK (monto >= 0),
  afecto_igv boolean NOT NULL DEFAULT false,
  tipo_comprobante varchar(10) NOT NULL DEFAULT 'boleta',
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, codigo),
  CHECK (not obligatorio or tipo in ('cuota_ingreso','matricula','pension'))
);
CREATE TRIGGER trg_conceptos_cobro_upd BEFORE UPDATE ON conceptos_cobro FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cronograma de pensiones por año y nivel.
CREATE TABLE cronogramas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  nivel varchar(20),
  nombre varchar(80) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_cronogramas_upd BEFORE UPDATE ON cronogramas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cuotas del cronograma (mes y vencimiento).
CREATE TABLE cronograma_cuotas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  cronograma_id bigint NOT NULL,
  concepto_cobro_id bigint NOT NULL,
  numero smallint NOT NULL,
  mes char(7) NOT NULL,
  emision date NOT NULL,
  vencimiento date NOT NULL,
  monto numeric(10,2) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (cronograma_id, numero),
  CHECK (vencimiento >= emision)
);
CREATE TRIGGER trg_cronograma_cuotas_upd BEFORE UPDATE ON cronograma_cuotas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Tipos de descuento o beca.
CREATE TABLE beneficios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(80) NOT NULL,
  tipo varchar(15) NOT NULL CHECK (tipo in ('porcentaje','monto')),
  valor numeric(10,2) NOT NULL,
  motivo varchar(20) NOT NULL CHECK (motivo in ('hermanos','pronto_pago','academica','orfandad','convenio','otro')),
  aplica_a varchar(15) NOT NULL DEFAULT 'pension',
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_beneficios_upd BEFORE UPDATE ON beneficios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Beneficio asignado a un alumno en un año.
CREATE TABLE alumno_beneficios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  beneficio_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  sustento_archivo_id bigint,
  aprobado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, beneficio_id, anio_escolar_id)
);
CREATE TRIGGER trg_alumno_beneficios_upd BEFORE UPDATE ON alumno_beneficios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Deuda por alumno y concepto. No se emiten pensiones antes de su mes.
CREATE TABLE cargos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  matricula_id bigint,
  concepto_cobro_id bigint NOT NULL,
  cronograma_cuota_id bigint,
  descripcion varchar(200) NOT NULL,
  emision date NOT NULL,
  vencimiento date NOT NULL,
  monto numeric(10,2) NOT NULL CHECK (monto >= 0),
  descuento numeric(10,2) NOT NULL DEFAULT 0,
  mora numeric(10,2) NOT NULL DEFAULT 0,
  pagado numeric(10,2) NOT NULL DEFAULT 0,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','parcial','pagado','anulado','exonerado')),
  codigo_recaudacion varchar(20),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  CHECK (pagado <= monto - descuento + mora)
);
CREATE TRIGGER trg_cargos_upd BEFORE UPDATE ON cargos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Dinero recibido.
CREATE TABLE pagos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  apoderado_id bigint,
  medio varchar(15) NOT NULL CHECK (medio in ('efectivo','yape','plin','tarjeta','transferencia','recaudacion')),
  monto numeric(10,2) NOT NULL CHECK (monto > 0),
  fecha timestamptz NOT NULL DEFAULT now(),
  referencia varchar(80),
  caja_sesion_id bigint,
  pasarela_transaccion_id bigint,
  recaudacion_linea_id bigint,
  registrado_por bigint,
  estado varchar(10) NOT NULL DEFAULT 'valido' CHECK (estado in ('valido','anulado')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_pagos_upd BEFORE UPDATE ON pagos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cómo se reparte un pago entre cargos.
CREATE TABLE pago_aplicaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  pago_id bigint NOT NULL,
  cargo_id bigint NOT NULL,
  monto numeric(10,2) NOT NULL CHECK (monto > 0),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (pago_id, cargo_id)
);
CREATE TRIGGER trg_pago_aplicaciones_upd BEFORE UPDATE ON pago_aplicaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Puntos de cobro presencial.
CREATE TABLE cajas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(60) NOT NULL,
  sede_id bigint,
  activa boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_cajas_upd BEFORE UPDATE ON cajas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Apertura, arqueo y cierre diario.
CREATE TABLE caja_sesiones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  caja_id bigint NOT NULL,
  abierta_por bigint NOT NULL,
  abierta_at timestamptz NOT NULL DEFAULT now(),
  monto_inicial numeric(10,2) NOT NULL DEFAULT 0,
  cerrada_at timestamptz,
  cerrada_por bigint,
  arqueo jsonb,
  diferencia numeric(10,2),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_caja_sesiones_upd BEFORE UPDATE ON caja_sesiones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Conexión con uno de los 2 proveedores de facturación electrónica (OSE/PSE).
CREATE TABLE proveedores_facturacion (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  proveedor varchar(30) NOT NULL,
  ambiente varchar(10) NOT NULL DEFAULT 'beta' CHECK (ambiente in ('beta','produccion')),
  credenciales_cifradas text NOT NULL,
  serie_boleta char(4) NOT NULL,
  serie_factura char(4) NOT NULL,
  serie_nota_credito char(4),
  predeterminado boolean NOT NULL DEFAULT false,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_proveedores_facturacion_upd BEFORE UPDATE ON proveedores_facturacion FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Boletas, facturas y notas de crédito.
CREATE TABLE comprobantes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  proveedor_facturacion_id bigint NOT NULL,
  pago_id bigint,
  tipo char(2) NOT NULL CHECK (tipo in ('03','01','07')),
  serie char(4) NOT NULL,
  numero int NOT NULL,
  fecha_emision date NOT NULL,
  cliente_tipo_doc char(1) NOT NULL,
  cliente_numero_doc varchar(15) NOT NULL,
  cliente_nombre varchar(200) NOT NULL,
  gravado numeric(10,2) NOT NULL DEFAULT 0,
  exonerado numeric(10,2) NOT NULL DEFAULT 0,
  igv numeric(10,2) NOT NULL DEFAULT 0,
  total numeric(10,2) NOT NULL,
  comprobante_ref_id bigint,
  estado_sunat varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado_sunat in ('pendiente','enviado','aceptado','observado','rechazado','anulado')),
  respuesta_proveedor jsonb,
  xml_archivo_id bigint,
  pdf_archivo_id bigint,
  intentos smallint NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, tipo, serie, numero)
);
CREATE TRIGGER trg_comprobantes_upd BEFORE UPDATE ON comprobantes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Detalle del comprobante.
CREATE TABLE comprobante_items (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  comprobante_id bigint NOT NULL,
  cargo_id bigint,
  descripcion varchar(250) NOT NULL,
  cantidad numeric(10,2) NOT NULL DEFAULT 1,
  valor_unitario numeric(10,2) NOT NULL,
  afectacion_igv char(2) NOT NULL DEFAULT '20',
  total numeric(10,2) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_comprobante_items_upd BEFORE UPDATE ON comprobante_items FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cuenta propia del colegio en la pasarela de pagos (el dinero va directo al colegio; Edunova no recibe ni retiene fondos).
CREATE TABLE pasarela_cuentas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  proveedor varchar(20) NOT NULL CHECK (proveedor in ('culqi','niubiz','mercadopago','izipay','openpay')),
  ambiente varchar(12) NOT NULL DEFAULT 'pruebas' CHECK (ambiente in ('pruebas','produccion')),
  llave_publica varchar(200),
  credenciales_cifradas text NOT NULL,
  webhook_secreto_cifrado text,
  medios jsonb NOT NULL DEFAULT '{}',
  predeterminada boolean NOT NULL DEFAULT false,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','activa','suspendida')),
  verificada_en timestamptz,
  configurada_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, proveedor)
);
CREATE TRIGGER trg_pasarela_cuentas_upd BEFORE UPDATE ON pasarela_cuentas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Pagos en línea (Yape, Plin, tarjeta).
CREATE TABLE pasarela_transacciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  pasarela_cuenta_id bigint,
  proveedor varchar(30) NOT NULL,
  orden varchar(60) NOT NULL,
  monto numeric(10,2) NOT NULL,
  comision numeric(10,2),
  medio varchar(12) CHECK (medio in ('tarjeta','yape','plin','pagoefectivo','otro')),
  neto numeric(10,2),
  fecha_abono date,
  estado varchar(12) NOT NULL DEFAULT 'creada' CHECK (estado in ('creada','autorizada','pagada','fallida','reembolsada')),
  payload jsonb,
  apoderado_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (proveedor, orden)
);
CREATE TRIGGER trg_pasarela_transacciones_upd BEFORE UPDATE ON pasarela_transacciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Archivos de deuda y de pagos con BCP o Interbank.
CREATE TABLE recaudacion_archivos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  banco varchar(10) NOT NULL CHECK (banco in ('BCP','INTERBANK')),
  direccion varchar(10) NOT NULL CHECK (direccion in ('deudas','pagos')),
  archivo_id bigint NOT NULL,
  lineas int NOT NULL DEFAULT 0,
  conciliadas int NOT NULL DEFAULT 0,
  estado varchar(12) NOT NULL DEFAULT 'procesando',
  procesado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_recaudacion_archivos_upd BEFORE UPDATE ON recaudacion_archivos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Línea del archivo del banco.
CREATE TABLE recaudacion_lineas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  recaudacion_archivo_id bigint NOT NULL,
  numero_linea int NOT NULL,
  codigo_recaudacion varchar(20) NOT NULL,
  monto numeric(10,2) NOT NULL,
  fecha_pago date,
  cargo_id bigint,
  resultado varchar(15) NOT NULL CHECK (resultado in ('conciliado','monto_distinto','sin_coincidencia','duplicado')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_recaudacion_lineas_upd BEFORE UPDATE ON recaudacion_lineas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Compromiso de pago de una familia con deuda.
CREATE TABLE compromisos_pago (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  monto numeric(10,2) NOT NULL,
  fecha_compromiso date NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'vigente' CHECK (estado in ('vigente','cumplido','incumplido')),
  archivo_firmado_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_compromisos_pago_upd BEFORE UPDATE ON compromisos_pago FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Convenio para pagar deuda vencida en cuotas, con acta firmada por el apoderado.
CREATE TABLE convenios_fraccionamiento (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  numero varchar(15) NOT NULL,
  apoderado_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  monto_total numeric(10,2) NOT NULL CHECK (monto_total > 0),
  numero_cuotas smallint NOT NULL CHECK (numero_cuotas between 2 and 12),
  estado varchar(12) NOT NULL DEFAULT 'borrador' CHECK (estado in ('borrador','vigente','cumplido','incumplido','anulado')),
  acta_archivo_id bigint,
  firmado_at timestamptz,
  aprobado_por bigint,
  aprobado_at timestamptz,
  observaciones text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, numero)
);
CREATE TRIGGER trg_convenios_fraccionamiento_upd BEFORE UPDATE ON convenios_fraccionamiento FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cargos vencidos que entran al convenio (los pagos se siguen aplicando a estos cargos).
CREATE TABLE convenio_cargos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  convenio_fraccionamiento_id bigint NOT NULL,
  cargo_id bigint NOT NULL,
  saldo_incluido numeric(10,2) NOT NULL CHECK (saldo_incluido > 0),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (convenio_fraccionamiento_id, cargo_id)
);
CREATE TRIGGER trg_convenio_cargos_upd BEFORE UPDATE ON convenio_cargos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Cronograma del convenio. Sin intereses ni mora salvo parámetro legal confirmado.
CREATE TABLE convenio_cuotas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  convenio_fraccionamiento_id bigint NOT NULL,
  numero smallint NOT NULL,
  vencimiento date NOT NULL,
  monto numeric(10,2) NOT NULL CHECK (monto > 0),
  pagado numeric(10,2) NOT NULL DEFAULT 0,
  estado varchar(10) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','pagada','vencida')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (convenio_fraccionamiento_id, numero),
  CHECK (pagado <= monto)
);
CREATE TRIGGER trg_convenio_cuotas_upd BEFORE UPDATE ON convenio_cuotas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [PAG] Contactos de cobranza.
CREATE TABLE gestiones_cobranza (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  canal varchar(12) NOT NULL,
  resultado varchar(200),
  realizado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_gestiones_cobranza_upd BEFORE UPDATE ON gestiones_cobranza FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Evidencias que respaldan la nota por competencia.
CREATE TABLE actividades_evaluacion (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  carga_docente_id bigint NOT NULL,
  periodo_id bigint NOT NULL,
  competencia_id bigint NOT NULL,
  nombre varchar(150) NOT NULL,
  fecha date,
  aula_tarea_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_actividades_evaluacion_upd BEFORE UPDATE ON actividades_evaluacion FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Nota de una actividad (insumo, no va al SIAGIE).
CREATE TABLE notas_actividad (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  actividad_evaluacion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  valor varchar(2) NOT NULL,
  comentario varchar(500),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (actividad_evaluacion_id, alumno_id)
);
CREATE TRIGGER trg_notas_actividad_upd BEFORE UPDATE ON notas_actividad FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Nota oficial por competencia y periodo. valor es texto: AD/A/B/C o 00-20.
CREATE TABLE calificaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  competencia_id bigint NOT NULL,
  periodo_id bigint NOT NULL,
  valor varchar(2) CHECK (valor is null or valor in ('AD','A','B','C') or valor ~ '^(0[0-9]|1[0-9]|20)$'),
  conclusion_descriptiva text,
  registrado_por bigint,
  origen varchar(10) NOT NULL DEFAULT 'manual' CHECK (origen in ('manual','aula','moodle','import')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, competencia_id, periodo_id)
);
CREATE TRIGGER trg_calificaciones_upd BEFORE UPDATE ON calificaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Flujo de revisión y cierre por sección y periodo.
CREATE TABLE cierres_periodo (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  periodo_id bigint NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'registro' CHECK (estado in ('registro','revision','aprobado','cerrado','reabierto')),
  revisado_por bigint,
  aprobado_por bigint,
  cerrado_at timestamptz,
  observaciones text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (seccion_id, periodo_id)
);
CREATE TRIGGER trg_cierres_periodo_upd BEFORE UPDATE ON cierres_periodo FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Asistencia diaria o por sesión.
CREATE TABLE asistencias (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  seccion_id bigint NOT NULL,
  fecha date NOT NULL,
  horario_sesion_id bigint,
  estado char(1) NOT NULL CHECK (estado in ('P','T','F','J')),
  hora_registro time,
  registrado_por bigint,
  origen varchar(10) NOT NULL DEFAULT 'web' CHECK (origen in ('web','movil','qr','offline')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_asistencias_upd BEFORE UPDATE ON asistencias FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Justificación enviada por el padre.
CREATE TABLE justificaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  desde date NOT NULL,
  hasta date NOT NULL,
  motivo text NOT NULL,
  archivo_id bigint,
  enviado_por bigint NOT NULL,
  estado varchar(10) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','aceptada','rechazada')),
  revisado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_justificaciones_upd BEFORE UPDATE ON justificaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Incidencias y méritos.
CREATE TABLE incidencias_conducta (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  tipo varchar(10) NOT NULL CHECK (tipo in ('incidencia','merito')),
  gravedad varchar(10),
  detalle text NOT NULL,
  registrado_por bigint,
  notificado_padre_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_incidencias_conducta_upd BEFORE UPDATE ON incidencias_conducta FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Entrevistas y acuerdos de tutoría.
CREATE TABLE entrevistas_tutoria (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  participantes varchar(200) NOT NULL,
  acuerdos text NOT NULL,
  confidencial boolean NOT NULL DEFAULT false,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_entrevistas_tutoria_upd BEFORE UPDATE ON entrevistas_tutoria FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Alertas automáticas (notas, faltas, aula).
CREATE TABLE alertas_riesgo (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  periodo_id bigint,
  motivo varchar(30) NOT NULL,
  detalle text NOT NULL,
  nivel varchar(10) NOT NULL DEFAULT 'media',
  estado varchar(12) NOT NULL DEFAULT 'abierta' CHECK (estado in ('abierta','en_seguimiento','cerrada')),
  accion text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_alertas_riesgo_upd BEFORE UPDATE ON alertas_riesgo FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Libretas PDF generadas por periodo.
CREATE TABLE libretas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  matricula_id bigint NOT NULL,
  periodo_id bigint NOT NULL,
  archivo_id bigint NOT NULL,
  publicada_at timestamptz,
  vista_por_padre_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (matricula_id, periodo_id)
);
CREATE TRIGGER trg_libretas_upd BEFORE UPDATE ON libretas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [CAL] Recuperación pedagógica.
CREATE TABLE evaluaciones_recuperacion (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  competencia_id bigint NOT NULL,
  valor varchar(2),
  fecha date,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, anio_escolar_id, competencia_id)
);
CREATE TRIGGER trg_evaluaciones_recuperacion_upd BEFORE UPDATE ON evaluaciones_recuperacion FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SIA] Importación de nómina o llenado de plantilla de notas.
CREATE TABLE siagie_procesos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tipo varchar(20) NOT NULL CHECK (tipo in ('nomina','plantilla_notas')),
  seccion_id bigint,
  periodo_id bigint,
  archivo_original_id bigint NOT NULL,
  archivo_resultado_id bigint,
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','procesando','completado','con_errores','fallido')),
  total_filas int NOT NULL DEFAULT 0,
  creados int NOT NULL DEFAULT 0,
  actualizados int NOT NULL DEFAULT 0,
  con_error int NOT NULL DEFAULT 0,
  mapeo jsonb,
  advertencias jsonb,
  usuario_id bigint,
  iniciado_at timestamptz,
  finalizado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_siagie_procesos_upd BEFORE UPDATE ON siagie_procesos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SIA] Errores por fila del proceso.
CREATE TABLE siagie_errores (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  siagie_proceso_id bigint NOT NULL,
  fila int NOT NULL,
  columna varchar(5),
  mensaje varchar(500) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_siagie_errores_upd BEFORE UPDATE ON siagie_errores FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SIA] Mapeo manual de plantillas cuando la detección automática no alcanza.
CREATE TABLE siagie_mapeos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tipo varchar(20) NOT NULL,
  grado_id bigint,
  mapeo jsonb NOT NULL,
  vigente boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_siagie_mapeos_upd BEFORE UPDATE ON siagie_mapeos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Catálogo de trámites de la institución.
CREATE TABLE tramite_tipos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(120) NOT NULL,
  descripcion text,
  concepto_cobro_id bigint,
  plazo_dias smallint NOT NULL DEFAULT 2,
  requiere_sin_deuda boolean NOT NULL DEFAULT false,
  plantilla_documento_id bigint,
  firma_cargo varchar(40) NOT NULL DEFAULT 'director',
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tramite_tipos_upd BEFORE UPDATE ON tramite_tipos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Requisitos de cada tipo.
CREATE TABLE tramite_requisitos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tramite_tipo_id bigint NOT NULL,
  descripcion varchar(200) NOT NULL,
  obligatorio boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tramite_requisitos_upd BEFORE UPDATE ON tramite_requisitos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Solicitud de un padre.
CREATE TABLE tramites (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  numero varchar(15) NOT NULL,
  tramite_tipo_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  solicitado_por bigint NOT NULL,
  cargo_id bigint,
  estado varchar(12) NOT NULL DEFAULT 'solicitado' CHECK (estado in ('solicitado','observado','en_revision','por_firmar','entregado','rechazado')),
  vence date,
  documento_emitido_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, numero)
);
CREATE TRIGGER trg_tramites_upd BEFORE UPDATE ON tramites FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Cambios de estado del trámite.
CREATE TABLE tramite_historial (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tramite_id bigint NOT NULL,
  estado varchar(12) NOT NULL,
  comentario text,
  user_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tramite_historial_upd BEFORE UPDATE ON tramite_historial FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Archivos que adjunta el padre.
CREATE TABLE tramite_adjuntos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tramite_id bigint NOT NULL,
  tramite_requisito_id bigint,
  archivo_id bigint NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tramite_adjuntos_upd BEFORE UPDATE ON tramite_adjuntos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Plantillas con variables.
CREATE TABLE plantillas_documento (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(120) NOT NULL,
  tipo varchar(20) NOT NULL,
  contenido_html text NOT NULL,
  con_qr boolean NOT NULL DEFAULT true,
  version smallint NOT NULL DEFAULT 1,
  activa boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_plantillas_documento_upd BEFORE UPDATE ON plantillas_documento FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Documento final firmado con código de verificación.
CREATE TABLE documentos_emitidos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  plantilla_documento_id bigint,
  alumno_id bigint,
  personal_id bigint,
  codigo_verificacion varchar(24) NOT NULL UNIQUE,
  sha256 char(64) NOT NULL,
  archivo_id bigint NOT NULL,
  firmado_por bigint,
  firmado_at timestamptz,
  anulado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_documentos_emitidos_upd BEFORE UPDATE ON documentos_emitidos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Expediente digital de alumno o personal.
CREATE TABLE expediente_documentos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titular_tipo varchar(10) NOT NULL CHECK (titular_tipo in ('alumno','personal')),
  titular_id bigint NOT NULL,
  categoria varchar(30) NOT NULL,
  nombre varchar(150) NOT NULL,
  archivo_id bigint NOT NULL,
  vence date,
  verificado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_expediente_documentos_upd BEFORE UPDATE ON expediente_documentos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Reglamento interno, PEI, normas de convivencia.
CREATE TABLE documentos_institucionales (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tipo varchar(30) NOT NULL,
  nombre varchar(150) NOT NULL,
  version varchar(20) NOT NULL,
  archivo_id bigint NOT NULL,
  publicado_at timestamptz,
  requiere_lectura boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_documentos_institucionales_upd BEFORE UPDATE ON documentos_institucionales FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Confirmación de lectura de padres.
CREATE TABLE documento_lecturas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  documento_institucional_id bigint NOT NULL,
  user_id bigint NOT NULL,
  leido_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (documento_institucional_id, user_id)
);
CREATE TRIGGER trg_documento_lecturas_upd BEFORE UPDATE ON documento_lecturas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Autorizaciones que firman los padres (salidas, campañas).
CREATE TABLE autorizaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  contenido text NOT NULL,
  alcance jsonb NOT NULL,
  vence date NOT NULL,
  creado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_autorizaciones_upd BEFORE UPDATE ON autorizaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [DOC] Respuesta del padre por alumno.
CREATE TABLE autorizacion_firmas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  autorizacion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  apoderado_id bigint NOT NULL,
  acepta boolean NOT NULL,
  firmado_at timestamptz NOT NULL DEFAULT now(),
  ip inet,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (autorizacion_id, alumno_id)
);
CREATE TRIGGER trg_autorizacion_firmas_upd BEFORE UPDATE ON autorizacion_firmas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Curso = área en una sección (se crea desde cargas_docentes).
CREATE TABLE aula_cursos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  carga_docente_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  visible boolean NOT NULL DEFAULT true,
  secuencial boolean NOT NULL DEFAULT true,
  video_sin_adelantar boolean NOT NULL DEFAULT true,
  video_pct_completado smallint NOT NULL DEFAULT 80 CHECK (video_pct_completado between 10 and 100),
  encuesta_activa boolean NOT NULL DEFAULT true,
  avance_visible_familia boolean NOT NULL DEFAULT true,
  pdf_descargable boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (carga_docente_id)
);
CREATE TRIGGER trg_aula_cursos_upd BEFORE UPDATE ON aula_cursos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Unidades o semanas.
CREATE TABLE aula_unidades (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  orden smallint NOT NULL DEFAULT 0,
  visible_desde date,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_unidades_upd BEFORE UPDATE ON aula_unidades FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Materiales.
CREATE TABLE aula_recursos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_unidad_id bigint NOT NULL,
  tipo varchar(10) NOT NULL CHECK (tipo in ('archivo','pdf','video','enlace','texto')),
  duracion_seg int,
  visible boolean NOT NULL DEFAULT true,
  titulo varchar(150) NOT NULL,
  archivo_id bigint,
  url varchar(500),
  contenido text,
  orden smallint NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_recursos_upd BEFORE UPDATE ON aula_recursos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Tareas con entrega.
CREATE TABLE aula_tareas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_unidad_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  instrucciones text NOT NULL,
  competencia_id bigint,
  publicada_at timestamptz,
  vence_at timestamptz NOT NULL,
  acepta_tardias boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_tareas_upd BEFORE UPDATE ON aula_tareas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Entrega del alumno.
CREATE TABLE aula_entregas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_tarea_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  texto text,
  archivo_id bigint,
  entregado_at timestamptz NOT NULL DEFAULT now(),
  tardia boolean NOT NULL DEFAULT false,
  nota varchar(2),
  comentario text,
  revisado_por bigint,
  revisado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (aula_tarea_id, alumno_id)
);
CREATE TRIGGER trg_aula_entregas_upd BEFORE UPDATE ON aula_entregas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Banco de preguntas: general del área (compartido entre secciones y años) o propio de un curso.
CREATE TABLE aula_bancos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(150) NOT NULL,
  alcance varchar(6) NOT NULL CHECK (alcance in ('area','curso')),
  area_curricular_id bigint NOT NULL,
  aula_curso_id bigint,
  creado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_bancos_upd BEFORE UPDATE ON aula_bancos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Banco de preguntas por área.
CREATE TABLE aula_preguntas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_banco_id bigint,
  area_curricular_id bigint NOT NULL,
  grado_id bigint,
  tipo varchar(15) NOT NULL CHECK (tipo in ('opcion_multiple','verdadero_falso','relacionar','respuesta_corta')),
  enunciado text NOT NULL,
  retroalimentacion text,
  unidad varchar(60),
  activa boolean NOT NULL DEFAULT true,
  opciones jsonb,
  respuesta_correcta jsonb NOT NULL,
  generada_ia boolean NOT NULL DEFAULT false,
  revisada_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_preguntas_upd BEFORE UPDATE ON aula_preguntas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Evaluación en línea.
CREATE TABLE aula_cuestionarios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_unidad_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  competencia_id bigint,
  abre_at timestamptz,
  cierra_at timestamptz,
  minutos smallint,
  intentos_max smallint NOT NULL DEFAULT 1,
  aula_banco_id bigint,
  preguntas_azar smallint,
  orden_aleatorio boolean NOT NULL DEFAULT true,
  nota_minima varchar(2),
  conserva varchar(8) NOT NULL DEFAULT 'mejor' CHECK (conserva in ('mejor','ultimo','promedio')),
  mostrar_respuestas varchar(12) NOT NULL DEFAULT 'al_terminar' CHECK (mostrar_respuestas in ('al_terminar','al_cerrar','nunca')),
  requiere_previos boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_cuestionarios_upd BEFORE UPDATE ON aula_cuestionarios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Preguntas del cuestionario.
CREATE TABLE aula_cuestionario_preguntas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_cuestionario_id bigint NOT NULL,
  aula_pregunta_id bigint NOT NULL,
  orden smallint NOT NULL DEFAULT 0,
  puntaje numeric(5,2) NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (aula_cuestionario_id, aula_pregunta_id)
);
CREATE TRIGGER trg_aula_cuestionario_preguntas_upd BEFORE UPDATE ON aula_cuestionario_preguntas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Intento del alumno.
CREATE TABLE aula_intentos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_cuestionario_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  numero smallint NOT NULL DEFAULT 1,
  iniciado_at timestamptz NOT NULL DEFAULT now(),
  enviado_at timestamptz,
  respuestas jsonb NOT NULL DEFAULT '{}'::jsonb,
  puntaje numeric(6,2),
  nota varchar(2),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (aula_cuestionario_id, alumno_id, numero)
);
CREATE TRIGGER trg_aula_intentos_upd BEFORE UPDATE ON aula_intentos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Intentos adicionales que el docente otorga a un alumno.
CREATE TABLE aula_intentos_extra (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_cuestionario_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  cantidad smallint NOT NULL DEFAULT 1 CHECK (cantidad between 1 and 5),
  otorgado_por bigint NOT NULL,
  motivo varchar(200),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_intentos_extra_upd BEFORE UPDATE ON aula_intentos_extra FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Avance del alumno por contenido (video visto, lectura completada). Base del desbloqueo en orden.
CREATE TABLE aula_progreso (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_recurso_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  porcentaje smallint NOT NULL DEFAULT 0 CHECK (porcentaje between 0 and 100),
  segundos int NOT NULL DEFAULT 0,
  completado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (aula_recurso_id, alumno_id)
);
CREATE TRIGGER trg_aula_progreso_upd BEFORE UPDATE ON aula_progreso FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Encuesta de satisfacción de la unidad (caritas 1 a 4).
CREATE TABLE aula_encuestas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_unidad_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  valor smallint NOT NULL CHECK (valor between 1 and 4),
  comentario varchar(300),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (aula_unidad_id, alumno_id)
);
CREATE TRIGGER trg_aula_encuestas_upd BEFORE UPDATE ON aula_encuestas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Observaciones del docente sobre un alumno (aspecto a mejorar, fortaleza).
CREATE TABLE aula_observaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  tipo varchar(16) NOT NULL CHECK (tipo in ('aspecto_mejorar','fortaleza','comentario')),
  texto text NOT NULL,
  autor_id bigint NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_observaciones_upd BEFORE UPDATE ON aula_observaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Avisos del docente en la portada del curso.
CREATE TABLE aula_avisos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  texto text NOT NULL,
  autor_id bigint NOT NULL,
  publicado_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_avisos_upd BEFORE UPDATE ON aula_avisos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Foros del curso.
CREATE TABLE aula_foros (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  creado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_foros_upd BEFORE UPDATE ON aula_foros FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Mensajes del foro.
CREATE TABLE aula_foro_mensajes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_foro_id bigint NOT NULL,
  autor_id bigint NOT NULL,
  contenido text NOT NULL,
  respuesta_a bigint,
  oculto boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_foro_mensajes_upd BEFORE UPDATE ON aula_foro_mensajes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Enlaces a Meet, Zoom o Teams.
CREATE TABLE aula_clases_vivo (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  titulo varchar(150) NOT NULL,
  inicio timestamptz NOT NULL,
  plataforma varchar(10) NOT NULL CHECK (plataforma in ('meet','zoom','teams','otro')),
  url varchar(500) NOT NULL,
  grabacion_url varchar(500),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_clases_vivo_upd BEFORE UPDATE ON aula_clases_vivo FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [AUL] Actividad del alumno (para seguimiento y alertas).
CREATE TABLE aula_accesos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  user_id bigint NOT NULL,
  aula_curso_id bigint NOT NULL,
  recurso_tipo varchar(20),
  recurso_id bigint,
  segundos int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_aula_accesos_upd BEFORE UPDATE ON aula_accesos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Moodle del colegio (propio o alojado por Edunova).
CREATE TABLE moodle_conexiones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  url varchar(300) NOT NULL,
  version varchar(20),
  token_cifrado text NOT NULL,
  sso_activo boolean NOT NULL DEFAULT true,
  sso_secreto_cifrado text,
  categoria_raiz_id int,
  frecuencia_minutos smallint NOT NULL DEFAULT 60,
  alojado_por_edunova boolean NOT NULL DEFAULT false,
  estado varchar(12) NOT NULL DEFAULT 'activo' CHECK (estado in ('activo','pausado','error')),
  ultima_sincronizacion_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id)
);
CREATE TRIGGER trg_moodle_conexiones_upd BEFORE UPDATE ON moodle_conexiones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Curso de Moodle por carga docente.
CREATE TABLE moodle_cursos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  moodle_conexion_id bigint NOT NULL,
  carga_docente_id bigint NOT NULL,
  moodle_course_id int NOT NULL,
  shortname varchar(100) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (moodle_conexion_id, moodle_course_id),
  UNIQUE (carga_docente_id)
);
CREATE TRIGGER trg_moodle_cursos_upd BEFORE UPDATE ON moodle_cursos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Usuario de Moodle por usuario Edunova.
CREATE TABLE moodle_usuarios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  moodle_conexion_id bigint NOT NULL,
  user_id bigint NOT NULL,
  moodle_user_id int NOT NULL,
  username varchar(100) NOT NULL,
  suspendido boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (moodle_conexion_id, user_id),
  UNIQUE (moodle_conexion_id, moodle_user_id)
);
CREATE TRIGGER trg_moodle_usuarios_upd BEFORE UPDATE ON moodle_usuarios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Bitácora de cada sincronización.
CREATE TABLE moodle_sincronizaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  moodle_conexion_id bigint NOT NULL,
  tipo varchar(20) NOT NULL,
  iniciada_at timestamptz NOT NULL DEFAULT now(),
  finalizada_at timestamptz,
  registros int NOT NULL DEFAULT 0,
  errores int NOT NULL DEFAULT 0,
  detalle jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_moodle_sincronizaciones_upd BEFORE UPDATE ON moodle_sincronizaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Conversión de nota numérica de Moodle a AD/A/B/C.
CREATE TABLE moodle_tablas_conversion (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(60) NOT NULL,
  rangos jsonb NOT NULL,
  predeterminada boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_moodle_tablas_conversion_upd BEFORE UPDATE ON moodle_tablas_conversion FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MDL] Notas traídas de Moodle, pendientes de aprobación docente.
CREATE TABLE moodle_notas_recibidas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  moodle_curso_id bigint NOT NULL,
  moodle_item_id int NOT NULL,
  item_nombre varchar(200) NOT NULL,
  alumno_id bigint NOT NULL,
  nota_numerica numeric(6,2),
  competencia_id bigint,
  periodo_id bigint,
  valor_convertido varchar(2),
  estado varchar(12) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','aprobada','descartada')),
  aprobado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (moodle_curso_id, moodle_item_id, alumno_id)
);
CREATE TRIGGER trg_moodle_notas_recibidas_upd BEFORE UPDATE ON moodle_notas_recibidas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Catálogo de títulos.
CREATE TABLE bib_libros (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  titulo varchar(250) NOT NULL,
  autor varchar(200),
  isbn varchar(20),
  editorial varchar(120),
  anio_publicacion smallint,
  categoria varchar(60),
  ubicacion varchar(40),
  tipo varchar(10) NOT NULL DEFAULT 'libro' CHECK (tipo in ('libro','texto','consulta','revista','ebook','audiolibro')),
  area_curricular_id bigint,
  nivel varchar(10) NOT NULL DEFAULT 'todos' CHECK (nivel in ('inicial','primaria','secundaria','todos')),
  grados varchar(40),
  descripcion text,
  licencias smallint,
  archivo_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_bib_libros_upd BEFORE UPDATE ON bib_libros FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Copias físicas con código de barras.
CREATE TABLE bib_ejemplares (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  bib_libro_id bigint NOT NULL,
  codigo varchar(30) NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'disponible' CHECK (estado in ('disponible','prestado','reservado','extraviado','baja')),
  conservacion varchar(8) NOT NULL DEFAULT 'bueno' CHECK (conservacion in ('bueno','regular','malo')),
  origen varchar(20),
  ingreso date,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, codigo)
);
CREATE TRIGGER trg_bib_ejemplares_upd BEFORE UPDATE ON bib_ejemplares FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Préstamos.
CREATE TABLE bib_prestamos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  bib_ejemplar_id bigint NOT NULL,
  lector_tipo varchar(10) NOT NULL CHECK (lector_tipo in ('alumno','personal')),
  lector_id bigint NOT NULL,
  prestado_at timestamptz NOT NULL DEFAULT now(),
  vence date NOT NULL,
  devuelto_at timestamptz,
  renovaciones smallint NOT NULL DEFAULT 0,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_bib_prestamos_upd BEFORE UPDATE ON bib_prestamos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Reservas.
CREATE TABLE bib_reservas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  bib_libro_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'en_espera' CHECK (estado in ('en_espera','disponible','atendida','cancelada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_bib_reservas_upd BEFORE UPDATE ON bib_reservas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Plan lector: libros por grado y periodo.
CREATE TABLE bib_plan_lector (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  periodo_id bigint NOT NULL,
  bib_libro_id bigint NOT NULL,
  docente_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (grado_id, periodo_id, bib_libro_id)
);
CREATE TRIGGER trg_bib_plan_lector_upd BEFORE UPDATE ON bib_plan_lector FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Avance de lectura del alumno en el plan lector.
CREATE TABLE bib_plan_lector_avance (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  bib_plan_lector_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  estado varchar(12) NOT NULL DEFAULT 'sin_empezar' CHECK (estado in ('sin_empezar','leyendo','terminado')),
  ficha_archivo_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (bib_plan_lector_id, alumno_id)
);
CREATE TRIGGER trg_bib_plan_lector_avance_upd BEFORE UPDATE ON bib_plan_lector_avance FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Préstamo de un libro digital (licencia) que vence solo.
CREATE TABLE bib_lecturas_digitales (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  bib_libro_id bigint NOT NULL,
  user_id bigint NOT NULL,
  inicio timestamptz NOT NULL DEFAULT now(),
  vence timestamptz NOT NULL,
  devuelto_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_bib_lecturas_digitales_upd BEFORE UPDATE ON bib_lecturas_digitales FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Reglas de préstamo del colegio.
CREATE TABLE bib_reglas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  dias_alumno smallint NOT NULL DEFAULT 7,
  dias_personal smallint NOT NULL DEFAULT 30,
  max_alumno smallint NOT NULL DEFAULT 2,
  renovaciones_max smallint NOT NULL DEFAULT 1,
  suspension_por_atraso boolean NOT NULL DEFAULT true,
  reservas_app boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id)
);
CREATE TRIGGER trg_bib_reglas_upd BEFORE UPDATE ON bib_reglas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [BIB] Suspensión de préstamos por atraso (sin multas en dinero).
CREATE TABLE bib_suspensiones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  lector_tipo varchar(10) NOT NULL CHECK (lector_tipo in ('alumno','personal')),
  lector_id bigint NOT NULL,
  hasta date NOT NULL,
  bib_prestamo_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_bib_suspensiones_upd BEFORE UPDATE ON bib_suspensiones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Vehículos.
CREATE TABLE mov_unidades (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  placa varchar(10) NOT NULL,
  capacidad smallint NOT NULL,
  soat_vence date,
  autorizacion_vence date,
  revision_tecnica_vence date,
  propia boolean NOT NULL DEFAULT false,
  empresa varchar(150),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, placa)
);
CREATE TRIGGER trg_mov_unidades_upd BEFORE UPDATE ON mov_unidades FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Conductores y auxiliares.
CREATE TABLE mov_conductores (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombres varchar(200) NOT NULL,
  numero_documento varchar(15) NOT NULL,
  rol varchar(10) NOT NULL CHECK (rol in ('conductor','auxiliar')),
  licencia varchar(20),
  licencia_vence date,
  user_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mov_conductores_upd BEFORE UPDATE ON mov_conductores FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Rutas por turno.
CREATE TABLE mov_rutas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(80) NOT NULL,
  turno varchar(10) NOT NULL,
  mov_unidad_id bigint,
  conductor_id bigint,
  auxiliar_id bigint,
  concepto_cobro_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mov_rutas_upd BEFORE UPDATE ON mov_rutas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Paraderos ordenados.
CREATE TABLE mov_paraderos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  mov_ruta_id bigint NOT NULL,
  nombre varchar(150) NOT NULL,
  orden smallint NOT NULL,
  latitud numeric(9,6),
  longitud numeric(9,6),
  hora_estimada time,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mov_paraderos_upd BEFORE UPDATE ON mov_paraderos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Alumno en una ruta.
CREATE TABLE mov_asignaciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  mov_ruta_id bigint NOT NULL,
  mov_paradero_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  activa boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, anio_escolar_id, mov_ruta_id)
);
CREATE TRIGGER trg_mov_asignaciones_upd BEFORE UPDATE ON mov_asignaciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Un viaje de una ruta (ida o vuelta) en un día. La ubicación solo se comparte mientras está en curso.
CREATE TABLE mov_recorridos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  mov_ruta_id bigint NOT NULL,
  fecha date NOT NULL,
  sentido varchar(10) NOT NULL CHECK (sentido in ('ida','vuelta')),
  estado varchar(12) NOT NULL DEFAULT 'programado' CHECK (estado in ('programado','en_curso','finalizado','cancelado')),
  iniciado_at timestamptz,
  finalizado_at timestamptz,
  iniciado_por bigint,
  ultima_latitud numeric(9,6),
  ultima_longitud numeric(9,6),
  ultima_posicion_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (mov_ruta_id, fecha, sentido)
);
CREATE TRIGGER trg_mov_recorridos_upd BEFORE UPDATE ON mov_recorridos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Posiciones GPS enviadas por la app del auxiliar. Se borran a los 30 días (fn_purgar_posiciones).
CREATE TABLE mov_posiciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  mov_recorrido_id bigint NOT NULL,
  latitud numeric(9,6) NOT NULL CHECK (latitud between -90 and 90),
  longitud numeric(9,6) NOT NULL CHECK (longitud between -180 and 180),
  velocidad_kmh numeric(5,1),
  precision_m smallint,
  registrado_at timestamptz NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mov_posiciones_upd BEFORE UPDATE ON mov_posiciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [MOV] Subidas y bajadas registradas desde la app del auxiliar.
CREATE TABLE mov_eventos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  mov_asignacion_id bigint NOT NULL,
  mov_recorrido_id bigint,
  tipo varchar(10) NOT NULL CHECK (tipo in ('subio','bajo','no_subio')),
  ocurrido_at timestamptz NOT NULL,
  latitud numeric(9,6),
  longitud numeric(9,6),
  registrado_por bigint,
  notificado_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mov_eventos_upd BEFORE UPDATE ON mov_eventos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COD] Menú por día.
CREATE TABLE com_menus (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  fecha date NOT NULL,
  entrada varchar(150),
  fondo varchar(150) NOT NULL,
  bebida varchar(100),
  postre varchar(100),
  kcal smallint CHECK (kcal between 0 and 3000),
  proteinas_g numeric(5,1),
  carbohidratos_g numeric(5,1),
  grasas_g numeric(5,1),
  alergenos varchar(200),
  validado_por varchar(150),
  publicado boolean NOT NULL DEFAULT false,
  estado varchar(10) NOT NULL DEFAULT 'borrador' CHECK (estado in ('borrador','propuesto','aprobado','publicado')),
  porciones jsonb,
  aprobado_por bigint,
  aprobado_at timestamptz,
  observacion_nutricion text,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, fecha)
);
CREATE TRIGGER trg_com_menus_upd BEFORE UPDATE ON com_menus FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COD] Planes del comedor.
CREATE TABLE com_planes (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(60) NOT NULL,
  modalidad varchar(10) NOT NULL CHECK (modalidad in ('mensual','diario')),
  concepto_cobro_id bigint,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_com_planes_upd BEFORE UPDATE ON com_planes FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COD] Alumno inscrito en un plan.
CREATE TABLE com_inscripciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  com_plan_id bigint NOT NULL,
  desde date NOT NULL,
  hasta date,
  dieta_especial boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_com_inscripciones_upd BEFORE UPDATE ON com_inscripciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [COD] Consumo diario.
CREATE TABLE com_consumos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  com_menu_id bigint,
  cargo_id bigint,
  origen varchar(10) NOT NULL DEFAULT 'lista' CHECK (origen in ('qr','lista','manual')),
  hora time,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, fecha)
);
CREATE TRIGGER trg_com_consumos_upd BEFORE UPDATE ON com_consumos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SAL] Ficha de salud del alumno. Visible solo con permiso de salud.
CREATE TABLE salud.fichas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  grupo_sanguineo varchar(5),
  seguro varchar(40),
  alergias text,
  condiciones text,
  medicacion text,
  contacto_emergencia varchar(200),
  telefono_emergencia varchar(15),
  actualizado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id)
);
CREATE TRIGGER trg_fichas_upd BEFORE UPDATE ON salud.fichas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SAL] Atenciones del tópico.
CREATE TABLE salud.atenciones (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  atendido_at timestamptz NOT NULL DEFAULT now(),
  motivo varchar(200) NOT NULL,
  accion text NOT NULL,
  derivado boolean NOT NULL DEFAULT false,
  retirado_por varchar(150),
  padre_notificado_at timestamptz,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_atenciones_upd BEFORE UPDATE ON salud.atenciones FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SAL] Campañas (vacunación, despistajes).
CREATE TABLE salud.campanas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(150) NOT NULL,
  fecha date NOT NULL,
  alcance jsonb NOT NULL,
  autorizacion_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_campanas_upd BEFORE UPDATE ON salud.campanas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SAL] Alergias, intolerancias y dietas para el comedor. Solo enfermería y nutricionista; el pase muestra una alerta sin detalle.
CREATE TABLE salud.restricciones_alimentarias (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  tipo varchar(12) NOT NULL CHECK (tipo in ('alergia','intolerancia','dieta','otra')),
  detalle varchar(200) NOT NULL,
  reemplazo varchar(200),
  vigente boolean NOT NULL DEFAULT true,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_restricciones_alimentarias_upd BEFORE UPDATE ON salud.restricciones_alimentarias FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [SAL] Peso y talla de campañas, con autorización de los padres.
CREATE TABLE salud.mediciones_nutricionales (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  peso_kg numeric(5,2) CHECK (peso_kg between 5 and 200),
  talla_cm numeric(5,1) CHECK (talla_cm between 40 and 220),
  campana_id bigint,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_mediciones_nutricionales_upd BEFORE UPDATE ON salud.mediciones_nutricionales FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Útiles, uniformes, textos y packs. Comprar es siempre voluntario.
CREATE TABLE tienda_productos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  codigo varchar(20) NOT NULL,
  nombre varchar(150) NOT NULL,
  categoria varchar(10) NOT NULL CHECK (categoria in ('util','uniforme','texto','pack','otro')),
  nivel varchar(10) NOT NULL DEFAULT 'todos' CHECK (nivel in ('inicial','primaria','secundaria','todos')),
  precio numeric(10,2) NOT NULL CHECK (precio >= 0),
  afectacion_igv char(2) NOT NULL DEFAULT '10',
  stock int NOT NULL DEFAULT 0,
  stock_minimo int NOT NULL DEFAULT 0,
  visible_app boolean NOT NULL DEFAULT true,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, codigo)
);
CREATE TRIGGER trg_tienda_productos_upd BEFORE UPDATE ON tienda_productos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Productos que componen un pack.
CREATE TABLE tienda_pack_items (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  pack_id bigint NOT NULL,
  producto_id bigint NOT NULL,
  cantidad smallint NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (pack_id, producto_id)
);
CREATE TRIGGER trg_tienda_pack_items_upd BEFORE UPDATE ON tienda_pack_items FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Lista de útiles por grado y año; se publica en la app.
CREATE TABLE tienda_listas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  grado_id bigint NOT NULL,
  anio_escolar_id bigint NOT NULL,
  pack_id bigint,
  entrega_gradual boolean NOT NULL DEFAULT true,
  publicada_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (grado_id, anio_escolar_id)
);
CREATE TRIGGER trg_tienda_listas_upd BEFORE UPDATE ON tienda_listas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Material de la lista de útiles.
CREATE TABLE tienda_lista_items (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tienda_lista_id bigint NOT NULL,
  descripcion varchar(150) NOT NULL,
  cantidad smallint NOT NULL DEFAULT 1,
  area varchar(60),
  producto_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tienda_lista_items_upd BEFORE UPDATE ON tienda_lista_items FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Pedido desde la app o venta en caja.
CREATE TABLE tienda_pedidos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  numero varchar(15) NOT NULL,
  apoderado_id bigint,
  alumno_id bigint,
  canal varchar(5) NOT NULL CHECK (canal in ('app','caja')),
  total numeric(10,2) NOT NULL,
  forma_pago varchar(10) NOT NULL DEFAULT 'inmediato' CHECK (forma_pago in ('inmediato','con_pension')),
  estado varchar(10) NOT NULL DEFAULT 'pendiente' CHECK (estado in ('pendiente','pagado','preparado','entregado','anulado')),
  pago_id bigint,
  cargo_id bigint,
  reservado_hasta timestamptz,
  entregado_at timestamptz,
  entregado_a varchar(150),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, numero)
);
CREATE TRIGGER trg_tienda_pedidos_upd BEFORE UPDATE ON tienda_pedidos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Detalle del pedido.
CREATE TABLE tienda_pedido_items (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  tienda_pedido_id bigint NOT NULL,
  producto_id bigint NOT NULL,
  cantidad smallint NOT NULL CHECK (cantidad > 0),
  precio numeric(10,2) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tienda_pedido_items_upd BEFORE UPDATE ON tienda_pedido_items FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [TIE] Kardex: ingresos, ventas, ajustes y devoluciones.
CREATE TABLE tienda_movimientos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  producto_id bigint NOT NULL,
  tipo varchar(10) NOT NULL CHECK (tipo in ('ingreso','venta','ajuste','devolucion')),
  cantidad int NOT NULL,
  costo_unitario numeric(10,2),
  proveedor varchar(150),
  tienda_pedido_id bigint,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_tienda_movimientos_upd BEFORE UPDATE ON tienda_movimientos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [POR] Ingreso del alumno por la puerta (QR del carné o DNI). Marca la asistencia del día.
CREATE TABLE por_ingresos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  hora time NOT NULL,
  metodo varchar(6) NOT NULL CHECK (metodo in ('qr','dni','manual')),
  puerta varchar(40),
  estado varchar(10) NOT NULL CHECK (estado in ('a_tiempo','tardanza')),
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, fecha)
);
CREATE TRIGGER trg_por_ingresos_upd BEFORE UPDATE ON por_ingresos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [POR] Personas autorizadas por el apoderado para recoger al alumno.
CREATE TABLE por_autorizados (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  nombres varchar(200) NOT NULL,
  numero_documento varchar(15) NOT NULL,
  parentesco varchar(40) NOT NULL,
  vigente_hasta date,
  activo boolean NOT NULL DEFAULT true,
  registrado_por_apoderado_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (alumno_id, numero_documento)
);
CREATE TRIGGER trg_por_autorizados_upd BEFORE UPDATE ON por_autorizados FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [POR] Salidas anticipadas y recojos verificados.
CREATE TABLE por_salidas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  alumno_id bigint NOT NULL,
  fecha date NOT NULL,
  hora time,
  motivo varchar(200) NOT NULL,
  origen varchar(10) NOT NULL CHECK (origen in ('app','topico','secretaria','porteria')),
  por_autorizado_id bigint,
  estado varchar(10) NOT NULL DEFAULT 'solicitada' CHECK (estado in ('solicitada','autorizada','salio','rechazada','anulada')),
  salio_at timestamptz,
  verificado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_por_salidas_upd BEFORE UPDATE ON por_salidas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [POR] Registro de visitas.
CREATE TABLE por_visitas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombres varchar(200) NOT NULL,
  numero_documento varchar(15) NOT NULL,
  visita_a varchar(150) NOT NULL,
  motivo varchar(200),
  ingreso_at timestamptz NOT NULL DEFAULT now(),
  salida_at timestamptz,
  registrado_por bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_por_visitas_upd BEFORE UPDATE ON por_visitas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Laboratorios, sala de cómputo, talleres.
CREATE TABLE lab_espacios (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(80) NOT NULL,
  tipo varchar(12) NOT NULL CHECK (tipo in ('laboratorio','computo','taller','otro')),
  capacidad smallint,
  activo boolean NOT NULL DEFAULT true,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_lab_espacios_upd BEFORE UPDATE ON lab_espacios FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Reserva de un espacio por hora pedagógica.
CREATE TABLE lab_reservas (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  lab_espacio_id bigint NOT NULL,
  fecha date NOT NULL,
  hora_inicio time NOT NULL,
  hora_fin time NOT NULL,
  seccion_id bigint,
  docente_id bigint NOT NULL,
  materiales text,
  estado varchar(10) NOT NULL DEFAULT 'reservada' CHECK (estado in ('reservada','realizada','cancelada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (lab_espacio_id, fecha, hora_inicio)
);
CREATE TRIGGER trg_lab_reservas_upd BEFORE UPDATE ON lab_reservas FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Laptops, tablets, proyectores, kits.
CREATE TABLE lab_equipos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  codigo varchar(30) NOT NULL,
  tipo varchar(40) NOT NULL,
  descripcion varchar(200),
  estado varchar(12) NOT NULL DEFAULT 'disponible' CHECK (estado in ('disponible','prestado','reparacion','baja')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id),
  UNIQUE (institucion_id, codigo)
);
CREATE TRIGGER trg_lab_equipos_upd BEFORE UPDATE ON lab_equipos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Préstamo de equipos a docentes o secciones.
CREATE TABLE lab_prestamos (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  lab_equipo_id bigint NOT NULL,
  prestado_a bigint NOT NULL,
  seccion_id bigint,
  desde timestamptz NOT NULL DEFAULT now(),
  hasta timestamptz NOT NULL,
  devuelto_at timestamptz,
  observacion varchar(200),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_lab_prestamos_upd BEFORE UPDATE ON lab_prestamos FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Materiales y reactivos con vencimiento.
CREATE TABLE lab_materiales (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  nombre varchar(150) NOT NULL,
  unidad varchar(20) NOT NULL,
  stock numeric(10,2) NOT NULL DEFAULT 0,
  stock_minimo numeric(10,2) NOT NULL DEFAULT 0,
  vence date,
  ubicacion varchar(60),
  peligroso boolean NOT NULL DEFAULT false,
  hoja_seguridad_archivo_id bigint,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_lab_materiales_upd BEFORE UPDATE ON lab_materiales FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- [LAB] Equipos dañados o incidentes leves.
CREATE TABLE lab_incidencias (
  id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  institucion_id bigint NOT NULL,
  fecha date NOT NULL,
  detalle text NOT NULL,
  lab_equipo_id bigint,
  reportado_por bigint NOT NULL,
  estado varchar(10) NOT NULL DEFAULT 'abierta' CHECK (estado in ('abierta','en_proceso','cerrada')),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (institucion_id, id)
);
CREATE TRIGGER trg_lab_incidencias_upd BEFORE UPDATE ON lab_incidencias FOR EACH ROW EXECUTE FUNCTION tg_updated_at();

-- ============================ CLAVES FORÁNEAS
ALTER TABLE suscripciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON suscripciones (institucion_id);
ALTER TABLE suscripciones ADD FOREIGN KEY (plan_id) REFERENCES planes (id);
CREATE INDEX ON suscripciones (plan_id);
ALTER TABLE suscripcion_modulos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON suscripcion_modulos (institucion_id);
ALTER TABLE suscripcion_modulos ADD FOREIGN KEY (institucion_id, suscripcion_id) REFERENCES suscripciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON suscripcion_modulos (institucion_id, suscripcion_id);
ALTER TABLE suscripcion_modulos ADD FOREIGN KEY (modulo_id) REFERENCES modulos_catalogo (id);
CREATE INDEX ON suscripcion_modulos (modulo_id);
ALTER TABLE facturas_saas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON facturas_saas (institucion_id);
ALTER TABLE facturas_saas ADD FOREIGN KEY (institucion_id, suscripcion_id) REFERENCES suscripciones (institucion_id, id);
CREATE INDEX ON facturas_saas (institucion_id, suscripcion_id);
ALTER TABLE tickets_soporte ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tickets_soporte (institucion_id);
ALTER TABLE tickets_soporte ADD FOREIGN KEY (creado_por) REFERENCES users (id);
CREATE INDEX ON tickets_soporte (creado_por);
ALTER TABLE tickets_soporte ADD FOREIGN KEY (asignado_a) REFERENCES users (id);
CREATE INDEX ON tickets_soporte (asignado_a);
ALTER TABLE tickets_soporte ADD FOREIGN KEY (sla_politica_id) REFERENCES sla_politicas (id);
CREATE INDEX ON tickets_soporte (sla_politica_id);
CREATE INDEX ON tickets_soporte (institucion_id, estado, vence_resolucion_at);
ALTER TABLE ticket_mensajes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON ticket_mensajes (institucion_id);
ALTER TABLE ticket_mensajes ADD FOREIGN KEY (institucion_id, ticket_id) REFERENCES tickets_soporte (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON ticket_mensajes (institucion_id, ticket_id);
ALTER TABLE ticket_mensajes ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON ticket_mensajes (autor_id);
ALTER TABLE rol_permiso ADD FOREIGN KEY (rol_id) REFERENCES roles (id) ON DELETE CASCADE;
CREATE INDEX ON rol_permiso (rol_id);
ALTER TABLE rol_permiso ADD FOREIGN KEY (permiso_id) REFERENCES permisos (id) ON DELETE CASCADE;
CREATE INDEX ON rol_permiso (permiso_id);
ALTER TABLE membresias ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON membresias (institucion_id);
ALTER TABLE membresias ADD FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
CREATE INDEX ON membresias (user_id);
ALTER TABLE membresias ADD FOREIGN KEY (rol_id) REFERENCES roles (id);
CREATE INDEX ON membresias (rol_id);
CREATE INDEX ON membresias (institucion_id, user_id);
ALTER TABLE dispositivos_moviles ADD FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
CREATE INDEX ON dispositivos_moviles (user_id);
ALTER TABLE auditoria ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON auditoria (institucion_id);
ALTER TABLE auditoria ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON auditoria (user_id);
CREATE INDEX ON auditoria (institucion_id, tabla, registro_id);
ALTER TABLE consentimientos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON consentimientos (institucion_id);
ALTER TABLE consentimientos ADD FOREIGN KEY (otorgado_por) REFERENCES users (id);
CREATE INDEX ON consentimientos (otorgado_por);
CREATE INDEX ON consentimientos (institucion_id, titular_tipo, titular_id);
ALTER TABLE archivos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON archivos (institucion_id);
ALTER TABLE archivos ADD FOREIGN KEY (subido_por) REFERENCES users (id);
CREATE INDEX ON archivos (subido_por);
ALTER TABLE competencias ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id) ON DELETE CASCADE;
CREATE INDEX ON competencias (area_curricular_id);
ALTER TABLE servicios_educativos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON servicios_educativos (institucion_id);
ALTER TABLE sedes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON sedes (institucion_id);
ALTER TABLE ambientes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON ambientes (institucion_id);
ALTER TABLE ambientes ADD FOREIGN KEY (institucion_id, sede_id) REFERENCES sedes (institucion_id, id);
CREATE INDEX ON ambientes (institucion_id, sede_id);
ALTER TABLE anios_escolares ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON anios_escolares (institucion_id);
ALTER TABLE periodos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON periodos (institucion_id);
ALTER TABLE periodos ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON periodos (institucion_id, anio_escolar_id);
ALTER TABLE periodos ADD FOREIGN KEY (cerrado_por) REFERENCES users (id);
CREATE INDEX ON periodos (cerrado_por);
ALTER TABLE feriados ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON feriados (institucion_id);
ALTER TABLE feriados ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON feriados (institucion_id, anio_escolar_id);
ALTER TABLE grados ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON grados (institucion_id);
ALTER TABLE grados ADD FOREIGN KEY (institucion_id, servicio_educativo_id) REFERENCES servicios_educativos (institucion_id, id);
CREATE INDEX ON grados (institucion_id, servicio_educativo_id);
ALTER TABLE area_curricular_grado ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON area_curricular_grado (institucion_id);
ALTER TABLE area_curricular_grado ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON area_curricular_grado (institucion_id, grado_id);
ALTER TABLE area_curricular_grado ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id);
CREATE INDEX ON area_curricular_grado (area_curricular_id);
ALTER TABLE secciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON secciones (institucion_id);
ALTER TABLE secciones ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON secciones (institucion_id, anio_escolar_id);
ALTER TABLE secciones ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON secciones (institucion_id, grado_id);
ALTER TABLE secciones ADD FOREIGN KEY (institucion_id, ambiente_id) REFERENCES ambientes (institucion_id, id) ON DELETE SET NULL (ambiente_id);
CREATE INDEX ON secciones (institucion_id, ambiente_id);
ALTER TABLE secciones ADD FOREIGN KEY (institucion_id, tutor_personal_id) REFERENCES personal (institucion_id, id) ON DELETE SET NULL (tutor_personal_id);
CREATE INDEX ON secciones (institucion_id, tutor_personal_id);
ALTER TABLE personal ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON personal (institucion_id);
ALTER TABLE personal ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON personal (user_id);
ALTER TABLE cargas_docentes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cargas_docentes (institucion_id);
ALTER TABLE cargas_docentes ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON cargas_docentes (institucion_id, seccion_id);
ALTER TABLE cargas_docentes ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id);
CREATE INDEX ON cargas_docentes (area_curricular_id);
ALTER TABLE cargas_docentes ADD FOREIGN KEY (institucion_id, personal_id) REFERENCES personal (institucion_id, id);
CREATE INDEX ON cargas_docentes (institucion_id, personal_id);
ALTER TABLE bloques_horarios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bloques_horarios (institucion_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON horario_sesiones (institucion_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON horario_sesiones (institucion_id, seccion_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id, bloque_horario_id) REFERENCES bloques_horarios (institucion_id, id);
CREATE INDEX ON horario_sesiones (institucion_id, bloque_horario_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id, carga_docente_id) REFERENCES cargas_docentes (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON horario_sesiones (institucion_id, carga_docente_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id, personal_id) REFERENCES personal (institucion_id, id);
CREATE INDEX ON horario_sesiones (institucion_id, personal_id);
ALTER TABLE horario_sesiones ADD FOREIGN KEY (institucion_id, ambiente_id) REFERENCES ambientes (institucion_id, id);
CREATE INDEX ON horario_sesiones (institucion_id, ambiente_id);
ALTER TABLE alumnos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON alumnos (institucion_id);
ALTER TABLE alumnos ADD FOREIGN KEY (institucion_id, foto_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON alumnos (institucion_id, foto_archivo_id);
ALTER TABLE alumnos ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON alumnos (user_id);
CREATE INDEX ON alumnos (institucion_id, apellido_paterno, apellido_materno);
ALTER TABLE apoderados ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON apoderados (institucion_id);
ALTER TABLE apoderados ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON apoderados (user_id);
ALTER TABLE alumno_apoderado ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON alumno_apoderado (institucion_id);
ALTER TABLE alumno_apoderado ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON alumno_apoderado (institucion_id, alumno_id);
ALTER TABLE alumno_apoderado ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON alumno_apoderado (institucion_id, apoderado_id);
ALTER TABLE admision_procesos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON admision_procesos (institucion_id);
ALTER TABLE admision_procesos ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON admision_procesos (institucion_id, anio_escolar_id);
ALTER TABLE vacantes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON vacantes (institucion_id);
ALTER TABLE vacantes ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON vacantes (institucion_id, anio_escolar_id);
ALTER TABLE vacantes ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON vacantes (institucion_id, grado_id);
ALTER TABLE postulantes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON postulantes (institucion_id);
ALTER TABLE postulantes ADD FOREIGN KEY (institucion_id, admision_proceso_id) REFERENCES admision_procesos (institucion_id, id);
CREATE INDEX ON postulantes (institucion_id, admision_proceso_id);
ALTER TABLE postulantes ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON postulantes (institucion_id, grado_id);
ALTER TABLE postulantes ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON postulantes (institucion_id, alumno_id);
ALTER TABLE postulante_eventos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON postulante_eventos (institucion_id);
ALTER TABLE postulante_eventos ADD FOREIGN KEY (institucion_id, postulante_id) REFERENCES postulantes (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON postulante_eventos (institucion_id, postulante_id);
ALTER TABLE postulante_eventos ADD FOREIGN KEY (responsable_id) REFERENCES users (id);
CREATE INDEX ON postulante_eventos (responsable_id);
ALTER TABLE matriculas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON matriculas (institucion_id);
ALTER TABLE matriculas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON matriculas (institucion_id, alumno_id);
ALTER TABLE matriculas ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON matriculas (institucion_id, anio_escolar_id);
ALTER TABLE matriculas ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON matriculas (institucion_id, seccion_id);
CREATE INDEX ON matriculas (institucion_id, seccion_id, estado);
ALTER TABLE condiciones_economicas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON condiciones_economicas (institucion_id);
ALTER TABLE condiciones_economicas ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON condiciones_economicas (institucion_id, anio_escolar_id);
ALTER TABLE condiciones_aceptaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON condiciones_aceptaciones (institucion_id);
ALTER TABLE condiciones_aceptaciones ADD FOREIGN KEY (institucion_id, condiciones_economicas_id) REFERENCES condiciones_economicas (institucion_id, id);
CREATE INDEX ON condiciones_aceptaciones (institucion_id, condiciones_economicas_id);
ALTER TABLE condiciones_aceptaciones ADD FOREIGN KEY (institucion_id, matricula_id) REFERENCES matriculas (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON condiciones_aceptaciones (institucion_id, matricula_id);
ALTER TABLE condiciones_aceptaciones ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON condiciones_aceptaciones (institucion_id, apoderado_id);
ALTER TABLE movimientos_matricula ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON movimientos_matricula (institucion_id);
ALTER TABLE movimientos_matricula ADD FOREIGN KEY (institucion_id, matricula_id) REFERENCES matriculas (institucion_id, id);
CREATE INDEX ON movimientos_matricula (institucion_id, matricula_id);
ALTER TABLE movimientos_matricula ADD FOREIGN KEY (institucion_id, seccion_destino_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON movimientos_matricula (institucion_id, seccion_destino_id);
ALTER TABLE movimientos_matricula ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON movimientos_matricula (registrado_por);
ALTER TABLE comunicados ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON comunicados (institucion_id);
ALTER TABLE comunicados ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON comunicados (autor_id);
ALTER TABLE comunicado_destinatarios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON comunicado_destinatarios (institucion_id);
ALTER TABLE comunicado_destinatarios ADD FOREIGN KEY (institucion_id, comunicado_id) REFERENCES comunicados (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON comunicado_destinatarios (institucion_id, comunicado_id);
ALTER TABLE comunicado_destinatarios ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON comunicado_destinatarios (user_id);
ALTER TABLE comunicado_destinatarios ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON comunicado_destinatarios (institucion_id, alumno_id);
ALTER TABLE conversaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON conversaciones (institucion_id);
ALTER TABLE conversaciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON conversaciones (institucion_id, alumno_id);
ALTER TABLE conversacion_participantes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON conversacion_participantes (institucion_id);
ALTER TABLE conversacion_participantes ADD FOREIGN KEY (institucion_id, conversacion_id) REFERENCES conversaciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON conversacion_participantes (institucion_id, conversacion_id);
ALTER TABLE conversacion_participantes ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON conversacion_participantes (user_id);
ALTER TABLE mensajes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mensajes (institucion_id);
ALTER TABLE mensajes ADD FOREIGN KEY (institucion_id, conversacion_id) REFERENCES conversaciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON mensajes (institucion_id, conversacion_id);
ALTER TABLE mensajes ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON mensajes (autor_id);
ALTER TABLE mensajes ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON mensajes (institucion_id, archivo_id);
ALTER TABLE eventos_calendario ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON eventos_calendario (institucion_id);
ALTER TABLE citas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON citas (institucion_id);
ALTER TABLE citas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON citas (institucion_id, alumno_id);
ALTER TABLE citas ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON citas (institucion_id, apoderado_id);
ALTER TABLE citas ADD FOREIGN KEY (institucion_id, personal_id) REFERENCES personal (institucion_id, id);
CREATE INDEX ON citas (institucion_id, personal_id);
ALTER TABLE reclamaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON reclamaciones (institucion_id);
ALTER TABLE reclamacion_respuestas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON reclamacion_respuestas (institucion_id);
ALTER TABLE reclamacion_respuestas ADD FOREIGN KEY (institucion_id, reclamacion_id) REFERENCES reclamaciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON reclamacion_respuestas (institucion_id, reclamacion_id);
ALTER TABLE reclamacion_respuestas ADD FOREIGN KEY (respondido_por) REFERENCES users (id);
CREATE INDEX ON reclamacion_respuestas (respondido_por);
ALTER TABLE notificaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON notificaciones (institucion_id);
ALTER TABLE notificaciones ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON notificaciones (user_id);
CREATE INDEX ON notificaciones (institucion_id, user_id, estado);
ALTER TABLE conceptos_cobro ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON conceptos_cobro (institucion_id);
ALTER TABLE cronogramas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cronogramas (institucion_id);
ALTER TABLE cronogramas ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON cronogramas (institucion_id, anio_escolar_id);
ALTER TABLE cronograma_cuotas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cronograma_cuotas (institucion_id);
ALTER TABLE cronograma_cuotas ADD FOREIGN KEY (institucion_id, cronograma_id) REFERENCES cronogramas (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON cronograma_cuotas (institucion_id, cronograma_id);
ALTER TABLE cronograma_cuotas ADD FOREIGN KEY (institucion_id, concepto_cobro_id) REFERENCES conceptos_cobro (institucion_id, id);
CREATE INDEX ON cronograma_cuotas (institucion_id, concepto_cobro_id);
ALTER TABLE beneficios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON beneficios (institucion_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON alumno_beneficios (institucion_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON alumno_beneficios (institucion_id, alumno_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (institucion_id, beneficio_id) REFERENCES beneficios (institucion_id, id);
CREATE INDEX ON alumno_beneficios (institucion_id, beneficio_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON alumno_beneficios (institucion_id, anio_escolar_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (institucion_id, sustento_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON alumno_beneficios (institucion_id, sustento_archivo_id);
ALTER TABLE alumno_beneficios ADD FOREIGN KEY (aprobado_por) REFERENCES users (id);
CREATE INDEX ON alumno_beneficios (aprobado_por);
ALTER TABLE cargos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cargos (institucion_id);
ALTER TABLE cargos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON cargos (institucion_id, alumno_id);
ALTER TABLE cargos ADD FOREIGN KEY (institucion_id, matricula_id) REFERENCES matriculas (institucion_id, id);
CREATE INDEX ON cargos (institucion_id, matricula_id);
ALTER TABLE cargos ADD FOREIGN KEY (institucion_id, concepto_cobro_id) REFERENCES conceptos_cobro (institucion_id, id);
CREATE INDEX ON cargos (institucion_id, concepto_cobro_id);
ALTER TABLE cargos ADD FOREIGN KEY (institucion_id, cronograma_cuota_id) REFERENCES cronograma_cuotas (institucion_id, id);
CREATE INDEX ON cargos (institucion_id, cronograma_cuota_id);
CREATE INDEX ON cargos (institucion_id, alumno_id, estado);
CREATE INDEX ON cargos (institucion_id, vencimiento);
ALTER TABLE pagos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON pagos (institucion_id);
ALTER TABLE pagos ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON pagos (institucion_id, apoderado_id);
ALTER TABLE pagos ADD FOREIGN KEY (institucion_id, caja_sesion_id) REFERENCES caja_sesiones (institucion_id, id);
CREATE INDEX ON pagos (institucion_id, caja_sesion_id);
ALTER TABLE pagos ADD FOREIGN KEY (institucion_id, pasarela_transaccion_id) REFERENCES pasarela_transacciones (institucion_id, id);
CREATE INDEX ON pagos (institucion_id, pasarela_transaccion_id);
ALTER TABLE pagos ADD FOREIGN KEY (institucion_id, recaudacion_linea_id) REFERENCES recaudacion_lineas (institucion_id, id);
CREATE INDEX ON pagos (institucion_id, recaudacion_linea_id);
ALTER TABLE pagos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON pagos (registrado_por);
ALTER TABLE pago_aplicaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON pago_aplicaciones (institucion_id);
ALTER TABLE pago_aplicaciones ADD FOREIGN KEY (institucion_id, pago_id) REFERENCES pagos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON pago_aplicaciones (institucion_id, pago_id);
ALTER TABLE pago_aplicaciones ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON pago_aplicaciones (institucion_id, cargo_id);
ALTER TABLE cajas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cajas (institucion_id);
ALTER TABLE cajas ADD FOREIGN KEY (institucion_id, sede_id) REFERENCES sedes (institucion_id, id);
CREATE INDEX ON cajas (institucion_id, sede_id);
ALTER TABLE caja_sesiones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON caja_sesiones (institucion_id);
ALTER TABLE caja_sesiones ADD FOREIGN KEY (institucion_id, caja_id) REFERENCES cajas (institucion_id, id);
CREATE INDEX ON caja_sesiones (institucion_id, caja_id);
ALTER TABLE caja_sesiones ADD FOREIGN KEY (abierta_por) REFERENCES users (id);
CREATE INDEX ON caja_sesiones (abierta_por);
ALTER TABLE caja_sesiones ADD FOREIGN KEY (cerrada_por) REFERENCES users (id);
CREATE INDEX ON caja_sesiones (cerrada_por);
ALTER TABLE proveedores_facturacion ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON proveedores_facturacion (institucion_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON comprobantes (institucion_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id, proveedor_facturacion_id) REFERENCES proveedores_facturacion (institucion_id, id);
CREATE INDEX ON comprobantes (institucion_id, proveedor_facturacion_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id, pago_id) REFERENCES pagos (institucion_id, id);
CREATE INDEX ON comprobantes (institucion_id, pago_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id, comprobante_ref_id) REFERENCES comprobantes (institucion_id, id);
CREATE INDEX ON comprobantes (institucion_id, comprobante_ref_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id, xml_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON comprobantes (institucion_id, xml_archivo_id);
ALTER TABLE comprobantes ADD FOREIGN KEY (institucion_id, pdf_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON comprobantes (institucion_id, pdf_archivo_id);
ALTER TABLE comprobante_items ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON comprobante_items (institucion_id);
ALTER TABLE comprobante_items ADD FOREIGN KEY (institucion_id, comprobante_id) REFERENCES comprobantes (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON comprobante_items (institucion_id, comprobante_id);
ALTER TABLE comprobante_items ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON comprobante_items (institucion_id, cargo_id);
ALTER TABLE pasarela_cuentas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON pasarela_cuentas (institucion_id);
ALTER TABLE pasarela_cuentas ADD FOREIGN KEY (configurada_por) REFERENCES users (id);
CREATE INDEX ON pasarela_cuentas (configurada_por);
ALTER TABLE pasarela_transacciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON pasarela_transacciones (institucion_id);
ALTER TABLE pasarela_transacciones ADD FOREIGN KEY (institucion_id, pasarela_cuenta_id) REFERENCES pasarela_cuentas (institucion_id, id);
CREATE INDEX ON pasarela_transacciones (institucion_id, pasarela_cuenta_id);
ALTER TABLE pasarela_transacciones ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON pasarela_transacciones (institucion_id, apoderado_id);
ALTER TABLE recaudacion_archivos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON recaudacion_archivos (institucion_id);
ALTER TABLE recaudacion_archivos ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON recaudacion_archivos (institucion_id, archivo_id);
ALTER TABLE recaudacion_archivos ADD FOREIGN KEY (procesado_por) REFERENCES users (id);
CREATE INDEX ON recaudacion_archivos (procesado_por);
ALTER TABLE recaudacion_lineas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON recaudacion_lineas (institucion_id);
ALTER TABLE recaudacion_lineas ADD FOREIGN KEY (institucion_id, recaudacion_archivo_id) REFERENCES recaudacion_archivos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON recaudacion_lineas (institucion_id, recaudacion_archivo_id);
ALTER TABLE recaudacion_lineas ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON recaudacion_lineas (institucion_id, cargo_id);
ALTER TABLE compromisos_pago ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON compromisos_pago (institucion_id);
ALTER TABLE compromisos_pago ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON compromisos_pago (institucion_id, apoderado_id);
ALTER TABLE compromisos_pago ADD FOREIGN KEY (institucion_id, archivo_firmado_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON compromisos_pago (institucion_id, archivo_firmado_id);
ALTER TABLE convenios_fraccionamiento ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON convenios_fraccionamiento (institucion_id);
ALTER TABLE convenios_fraccionamiento ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON convenios_fraccionamiento (institucion_id, apoderado_id);
ALTER TABLE convenios_fraccionamiento ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON convenios_fraccionamiento (institucion_id, alumno_id);
ALTER TABLE convenios_fraccionamiento ADD FOREIGN KEY (institucion_id, acta_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON convenios_fraccionamiento (institucion_id, acta_archivo_id);
ALTER TABLE convenios_fraccionamiento ADD FOREIGN KEY (aprobado_por) REFERENCES users (id);
CREATE INDEX ON convenios_fraccionamiento (aprobado_por);
ALTER TABLE convenio_cargos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON convenio_cargos (institucion_id);
ALTER TABLE convenio_cargos ADD FOREIGN KEY (institucion_id, convenio_fraccionamiento_id) REFERENCES convenios_fraccionamiento (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON convenio_cargos (institucion_id, convenio_fraccionamiento_id);
ALTER TABLE convenio_cargos ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON convenio_cargos (institucion_id, cargo_id);
ALTER TABLE convenio_cuotas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON convenio_cuotas (institucion_id);
ALTER TABLE convenio_cuotas ADD FOREIGN KEY (institucion_id, convenio_fraccionamiento_id) REFERENCES convenios_fraccionamiento (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON convenio_cuotas (institucion_id, convenio_fraccionamiento_id);
ALTER TABLE gestiones_cobranza ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON gestiones_cobranza (institucion_id);
ALTER TABLE gestiones_cobranza ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON gestiones_cobranza (institucion_id, apoderado_id);
ALTER TABLE gestiones_cobranza ADD FOREIGN KEY (realizado_por) REFERENCES users (id);
CREATE INDEX ON gestiones_cobranza (realizado_por);
ALTER TABLE actividades_evaluacion ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON actividades_evaluacion (institucion_id);
ALTER TABLE actividades_evaluacion ADD FOREIGN KEY (institucion_id, carga_docente_id) REFERENCES cargas_docentes (institucion_id, id);
CREATE INDEX ON actividades_evaluacion (institucion_id, carga_docente_id);
ALTER TABLE actividades_evaluacion ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON actividades_evaluacion (institucion_id, periodo_id);
ALTER TABLE actividades_evaluacion ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON actividades_evaluacion (competencia_id);
ALTER TABLE actividades_evaluacion ADD FOREIGN KEY (institucion_id, aula_tarea_id) REFERENCES aula_tareas (institucion_id, id);
CREATE INDEX ON actividades_evaluacion (institucion_id, aula_tarea_id);
ALTER TABLE notas_actividad ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON notas_actividad (institucion_id);
ALTER TABLE notas_actividad ADD FOREIGN KEY (institucion_id, actividad_evaluacion_id) REFERENCES actividades_evaluacion (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON notas_actividad (institucion_id, actividad_evaluacion_id);
ALTER TABLE notas_actividad ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON notas_actividad (institucion_id, alumno_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON calificaciones (institucion_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON calificaciones (institucion_id, alumno_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON calificaciones (institucion_id, seccion_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON calificaciones (competencia_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON calificaciones (institucion_id, periodo_id);
ALTER TABLE calificaciones ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON calificaciones (registrado_por);
CREATE INDEX ON calificaciones (institucion_id, seccion_id, periodo_id);
ALTER TABLE cierres_periodo ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON cierres_periodo (institucion_id);
ALTER TABLE cierres_periodo ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON cierres_periodo (institucion_id, seccion_id);
ALTER TABLE cierres_periodo ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON cierres_periodo (institucion_id, periodo_id);
ALTER TABLE cierres_periodo ADD FOREIGN KEY (revisado_por) REFERENCES users (id);
CREATE INDEX ON cierres_periodo (revisado_por);
ALTER TABLE cierres_periodo ADD FOREIGN KEY (aprobado_por) REFERENCES users (id);
CREATE INDEX ON cierres_periodo (aprobado_por);
ALTER TABLE asistencias ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON asistencias (institucion_id);
ALTER TABLE asistencias ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON asistencias (institucion_id, alumno_id);
ALTER TABLE asistencias ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON asistencias (institucion_id, seccion_id);
ALTER TABLE asistencias ADD FOREIGN KEY (institucion_id, horario_sesion_id) REFERENCES horario_sesiones (institucion_id, id);
CREATE INDEX ON asistencias (institucion_id, horario_sesion_id);
ALTER TABLE asistencias ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON asistencias (registrado_por);
CREATE INDEX ON asistencias (institucion_id, seccion_id, fecha);
ALTER TABLE justificaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON justificaciones (institucion_id);
ALTER TABLE justificaciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON justificaciones (institucion_id, alumno_id);
ALTER TABLE justificaciones ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON justificaciones (institucion_id, archivo_id);
ALTER TABLE justificaciones ADD FOREIGN KEY (enviado_por) REFERENCES users (id);
CREATE INDEX ON justificaciones (enviado_por);
ALTER TABLE justificaciones ADD FOREIGN KEY (revisado_por) REFERENCES users (id);
CREATE INDEX ON justificaciones (revisado_por);
ALTER TABLE incidencias_conducta ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON incidencias_conducta (institucion_id);
ALTER TABLE incidencias_conducta ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON incidencias_conducta (institucion_id, alumno_id);
ALTER TABLE incidencias_conducta ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON incidencias_conducta (registrado_por);
ALTER TABLE entrevistas_tutoria ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON entrevistas_tutoria (institucion_id);
ALTER TABLE entrevistas_tutoria ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON entrevistas_tutoria (institucion_id, alumno_id);
ALTER TABLE entrevistas_tutoria ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON entrevistas_tutoria (registrado_por);
ALTER TABLE alertas_riesgo ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON alertas_riesgo (institucion_id);
ALTER TABLE alertas_riesgo ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON alertas_riesgo (institucion_id, alumno_id);
ALTER TABLE alertas_riesgo ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON alertas_riesgo (institucion_id, periodo_id);
ALTER TABLE libretas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON libretas (institucion_id);
ALTER TABLE libretas ADD FOREIGN KEY (institucion_id, matricula_id) REFERENCES matriculas (institucion_id, id);
CREATE INDEX ON libretas (institucion_id, matricula_id);
ALTER TABLE libretas ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON libretas (institucion_id, periodo_id);
ALTER TABLE libretas ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON libretas (institucion_id, archivo_id);
ALTER TABLE evaluaciones_recuperacion ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON evaluaciones_recuperacion (institucion_id);
ALTER TABLE evaluaciones_recuperacion ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON evaluaciones_recuperacion (institucion_id, alumno_id);
ALTER TABLE evaluaciones_recuperacion ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON evaluaciones_recuperacion (institucion_id, anio_escolar_id);
ALTER TABLE evaluaciones_recuperacion ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON evaluaciones_recuperacion (competencia_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON siagie_procesos (institucion_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON siagie_procesos (institucion_id, seccion_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON siagie_procesos (institucion_id, periodo_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (institucion_id, archivo_original_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON siagie_procesos (institucion_id, archivo_original_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (institucion_id, archivo_resultado_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON siagie_procesos (institucion_id, archivo_resultado_id);
ALTER TABLE siagie_procesos ADD FOREIGN KEY (usuario_id) REFERENCES users (id);
CREATE INDEX ON siagie_procesos (usuario_id);
ALTER TABLE siagie_errores ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON siagie_errores (institucion_id);
ALTER TABLE siagie_errores ADD FOREIGN KEY (institucion_id, siagie_proceso_id) REFERENCES siagie_procesos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON siagie_errores (institucion_id, siagie_proceso_id);
ALTER TABLE siagie_mapeos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON siagie_mapeos (institucion_id);
ALTER TABLE siagie_mapeos ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON siagie_mapeos (institucion_id, grado_id);
ALTER TABLE tramite_tipos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tramite_tipos (institucion_id);
ALTER TABLE tramite_tipos ADD FOREIGN KEY (institucion_id, concepto_cobro_id) REFERENCES conceptos_cobro (institucion_id, id);
CREATE INDEX ON tramite_tipos (institucion_id, concepto_cobro_id);
ALTER TABLE tramite_tipos ADD FOREIGN KEY (institucion_id, plantilla_documento_id) REFERENCES plantillas_documento (institucion_id, id);
CREATE INDEX ON tramite_tipos (institucion_id, plantilla_documento_id);
ALTER TABLE tramite_requisitos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tramite_requisitos (institucion_id);
ALTER TABLE tramite_requisitos ADD FOREIGN KEY (institucion_id, tramite_tipo_id) REFERENCES tramite_tipos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tramite_requisitos (institucion_id, tramite_tipo_id);
ALTER TABLE tramites ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tramites (institucion_id);
ALTER TABLE tramites ADD FOREIGN KEY (institucion_id, tramite_tipo_id) REFERENCES tramite_tipos (institucion_id, id);
CREATE INDEX ON tramites (institucion_id, tramite_tipo_id);
ALTER TABLE tramites ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON tramites (institucion_id, alumno_id);
ALTER TABLE tramites ADD FOREIGN KEY (solicitado_por) REFERENCES users (id);
CREATE INDEX ON tramites (solicitado_por);
ALTER TABLE tramites ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON tramites (institucion_id, cargo_id);
ALTER TABLE tramites ADD FOREIGN KEY (institucion_id, documento_emitido_id) REFERENCES documentos_emitidos (institucion_id, id);
CREATE INDEX ON tramites (institucion_id, documento_emitido_id);
ALTER TABLE tramite_historial ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tramite_historial (institucion_id);
ALTER TABLE tramite_historial ADD FOREIGN KEY (institucion_id, tramite_id) REFERENCES tramites (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tramite_historial (institucion_id, tramite_id);
ALTER TABLE tramite_historial ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON tramite_historial (user_id);
ALTER TABLE tramite_adjuntos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tramite_adjuntos (institucion_id);
ALTER TABLE tramite_adjuntos ADD FOREIGN KEY (institucion_id, tramite_id) REFERENCES tramites (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tramite_adjuntos (institucion_id, tramite_id);
ALTER TABLE tramite_adjuntos ADD FOREIGN KEY (institucion_id, tramite_requisito_id) REFERENCES tramite_requisitos (institucion_id, id);
CREATE INDEX ON tramite_adjuntos (institucion_id, tramite_requisito_id);
ALTER TABLE tramite_adjuntos ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON tramite_adjuntos (institucion_id, archivo_id);
ALTER TABLE plantillas_documento ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON plantillas_documento (institucion_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON documentos_emitidos (institucion_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (institucion_id, plantilla_documento_id) REFERENCES plantillas_documento (institucion_id, id);
CREATE INDEX ON documentos_emitidos (institucion_id, plantilla_documento_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON documentos_emitidos (institucion_id, alumno_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (institucion_id, personal_id) REFERENCES personal (institucion_id, id);
CREATE INDEX ON documentos_emitidos (institucion_id, personal_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON documentos_emitidos (institucion_id, archivo_id);
ALTER TABLE documentos_emitidos ADD FOREIGN KEY (firmado_por) REFERENCES users (id);
CREATE INDEX ON documentos_emitidos (firmado_por);
ALTER TABLE expediente_documentos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON expediente_documentos (institucion_id);
ALTER TABLE expediente_documentos ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON expediente_documentos (institucion_id, archivo_id);
ALTER TABLE expediente_documentos ADD FOREIGN KEY (verificado_por) REFERENCES users (id);
CREATE INDEX ON expediente_documentos (verificado_por);
CREATE INDEX ON expediente_documentos (institucion_id, titular_tipo, titular_id);
ALTER TABLE documentos_institucionales ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON documentos_institucionales (institucion_id);
ALTER TABLE documentos_institucionales ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON documentos_institucionales (institucion_id, archivo_id);
ALTER TABLE documento_lecturas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON documento_lecturas (institucion_id);
ALTER TABLE documento_lecturas ADD FOREIGN KEY (institucion_id, documento_institucional_id) REFERENCES documentos_institucionales (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON documento_lecturas (institucion_id, documento_institucional_id);
ALTER TABLE documento_lecturas ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON documento_lecturas (user_id);
ALTER TABLE autorizaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON autorizaciones (institucion_id);
ALTER TABLE autorizaciones ADD FOREIGN KEY (creado_por) REFERENCES users (id);
CREATE INDEX ON autorizaciones (creado_por);
ALTER TABLE autorizacion_firmas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON autorizacion_firmas (institucion_id);
ALTER TABLE autorizacion_firmas ADD FOREIGN KEY (institucion_id, autorizacion_id) REFERENCES autorizaciones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON autorizacion_firmas (institucion_id, autorizacion_id);
ALTER TABLE autorizacion_firmas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON autorizacion_firmas (institucion_id, alumno_id);
ALTER TABLE autorizacion_firmas ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON autorizacion_firmas (institucion_id, apoderado_id);
ALTER TABLE aula_cursos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_cursos (institucion_id);
ALTER TABLE aula_cursos ADD FOREIGN KEY (institucion_id, carga_docente_id) REFERENCES cargas_docentes (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_cursos (institucion_id, carga_docente_id);
ALTER TABLE aula_unidades ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_unidades (institucion_id);
ALTER TABLE aula_unidades ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_unidades (institucion_id, aula_curso_id);
ALTER TABLE aula_recursos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_recursos (institucion_id);
ALTER TABLE aula_recursos ADD FOREIGN KEY (institucion_id, aula_unidad_id) REFERENCES aula_unidades (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_recursos (institucion_id, aula_unidad_id);
ALTER TABLE aula_recursos ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON aula_recursos (institucion_id, archivo_id);
ALTER TABLE aula_tareas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_tareas (institucion_id);
ALTER TABLE aula_tareas ADD FOREIGN KEY (institucion_id, aula_unidad_id) REFERENCES aula_unidades (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_tareas (institucion_id, aula_unidad_id);
ALTER TABLE aula_tareas ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON aula_tareas (competencia_id);
ALTER TABLE aula_entregas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_entregas (institucion_id);
ALTER TABLE aula_entregas ADD FOREIGN KEY (institucion_id, aula_tarea_id) REFERENCES aula_tareas (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_entregas (institucion_id, aula_tarea_id);
ALTER TABLE aula_entregas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_entregas (institucion_id, alumno_id);
ALTER TABLE aula_entregas ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON aula_entregas (institucion_id, archivo_id);
ALTER TABLE aula_entregas ADD FOREIGN KEY (revisado_por) REFERENCES users (id);
CREATE INDEX ON aula_entregas (revisado_por);
ALTER TABLE aula_bancos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_bancos (institucion_id);
ALTER TABLE aula_bancos ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id);
CREATE INDEX ON aula_bancos (area_curricular_id);
ALTER TABLE aula_bancos ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id);
CREATE INDEX ON aula_bancos (institucion_id, aula_curso_id);
ALTER TABLE aula_bancos ADD FOREIGN KEY (creado_por) REFERENCES users (id);
CREATE INDEX ON aula_bancos (creado_por);
ALTER TABLE aula_preguntas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_preguntas (institucion_id);
ALTER TABLE aula_preguntas ADD FOREIGN KEY (institucion_id, aula_banco_id) REFERENCES aula_bancos (institucion_id, id);
CREATE INDEX ON aula_preguntas (institucion_id, aula_banco_id);
ALTER TABLE aula_preguntas ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id);
CREATE INDEX ON aula_preguntas (area_curricular_id);
ALTER TABLE aula_preguntas ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON aula_preguntas (institucion_id, grado_id);
ALTER TABLE aula_preguntas ADD FOREIGN KEY (revisada_por) REFERENCES users (id);
CREATE INDEX ON aula_preguntas (revisada_por);
ALTER TABLE aula_cuestionarios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_cuestionarios (institucion_id);
ALTER TABLE aula_cuestionarios ADD FOREIGN KEY (institucion_id, aula_unidad_id) REFERENCES aula_unidades (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_cuestionarios (institucion_id, aula_unidad_id);
ALTER TABLE aula_cuestionarios ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON aula_cuestionarios (competencia_id);
ALTER TABLE aula_cuestionarios ADD FOREIGN KEY (institucion_id, aula_banco_id) REFERENCES aula_bancos (institucion_id, id);
CREATE INDEX ON aula_cuestionarios (institucion_id, aula_banco_id);
ALTER TABLE aula_cuestionario_preguntas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_cuestionario_preguntas (institucion_id);
ALTER TABLE aula_cuestionario_preguntas ADD FOREIGN KEY (institucion_id, aula_cuestionario_id) REFERENCES aula_cuestionarios (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_cuestionario_preguntas (institucion_id, aula_cuestionario_id);
ALTER TABLE aula_cuestionario_preguntas ADD FOREIGN KEY (institucion_id, aula_pregunta_id) REFERENCES aula_preguntas (institucion_id, id);
CREATE INDEX ON aula_cuestionario_preguntas (institucion_id, aula_pregunta_id);
ALTER TABLE aula_intentos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_intentos (institucion_id);
ALTER TABLE aula_intentos ADD FOREIGN KEY (institucion_id, aula_cuestionario_id) REFERENCES aula_cuestionarios (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_intentos (institucion_id, aula_cuestionario_id);
ALTER TABLE aula_intentos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_intentos (institucion_id, alumno_id);
ALTER TABLE aula_intentos_extra ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_intentos_extra (institucion_id);
ALTER TABLE aula_intentos_extra ADD FOREIGN KEY (institucion_id, aula_cuestionario_id) REFERENCES aula_cuestionarios (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_intentos_extra (institucion_id, aula_cuestionario_id);
ALTER TABLE aula_intentos_extra ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_intentos_extra (institucion_id, alumno_id);
ALTER TABLE aula_intentos_extra ADD FOREIGN KEY (otorgado_por) REFERENCES users (id);
CREATE INDEX ON aula_intentos_extra (otorgado_por);
ALTER TABLE aula_progreso ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_progreso (institucion_id);
ALTER TABLE aula_progreso ADD FOREIGN KEY (institucion_id, aula_recurso_id) REFERENCES aula_recursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_progreso (institucion_id, aula_recurso_id);
ALTER TABLE aula_progreso ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_progreso (institucion_id, alumno_id);
ALTER TABLE aula_encuestas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_encuestas (institucion_id);
ALTER TABLE aula_encuestas ADD FOREIGN KEY (institucion_id, aula_unidad_id) REFERENCES aula_unidades (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_encuestas (institucion_id, aula_unidad_id);
ALTER TABLE aula_encuestas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_encuestas (institucion_id, alumno_id);
ALTER TABLE aula_observaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_observaciones (institucion_id);
ALTER TABLE aula_observaciones ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_observaciones (institucion_id, aula_curso_id);
ALTER TABLE aula_observaciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON aula_observaciones (institucion_id, alumno_id);
ALTER TABLE aula_observaciones ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON aula_observaciones (autor_id);
ALTER TABLE aula_avisos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_avisos (institucion_id);
ALTER TABLE aula_avisos ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_avisos (institucion_id, aula_curso_id);
ALTER TABLE aula_avisos ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON aula_avisos (autor_id);
ALTER TABLE aula_foros ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_foros (institucion_id);
ALTER TABLE aula_foros ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_foros (institucion_id, aula_curso_id);
ALTER TABLE aula_foros ADD FOREIGN KEY (creado_por) REFERENCES users (id);
CREATE INDEX ON aula_foros (creado_por);
ALTER TABLE aula_foro_mensajes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_foro_mensajes (institucion_id);
ALTER TABLE aula_foro_mensajes ADD FOREIGN KEY (institucion_id, aula_foro_id) REFERENCES aula_foros (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_foro_mensajes (institucion_id, aula_foro_id);
ALTER TABLE aula_foro_mensajes ADD FOREIGN KEY (autor_id) REFERENCES users (id);
CREATE INDEX ON aula_foro_mensajes (autor_id);
ALTER TABLE aula_foro_mensajes ADD FOREIGN KEY (institucion_id, respuesta_a) REFERENCES aula_foro_mensajes (institucion_id, id);
CREATE INDEX ON aula_foro_mensajes (institucion_id, respuesta_a);
ALTER TABLE aula_clases_vivo ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_clases_vivo (institucion_id);
ALTER TABLE aula_clases_vivo ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_clases_vivo (institucion_id, aula_curso_id);
ALTER TABLE aula_accesos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON aula_accesos (institucion_id);
ALTER TABLE aula_accesos ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON aula_accesos (user_id);
ALTER TABLE aula_accesos ADD FOREIGN KEY (institucion_id, aula_curso_id) REFERENCES aula_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON aula_accesos (institucion_id, aula_curso_id);
CREATE INDEX ON aula_accesos (institucion_id, aula_curso_id, user_id);
ALTER TABLE moodle_conexiones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_conexiones (institucion_id);
ALTER TABLE moodle_cursos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_cursos (institucion_id);
ALTER TABLE moodle_cursos ADD FOREIGN KEY (institucion_id, moodle_conexion_id) REFERENCES moodle_conexiones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON moodle_cursos (institucion_id, moodle_conexion_id);
ALTER TABLE moodle_cursos ADD FOREIGN KEY (institucion_id, carga_docente_id) REFERENCES cargas_docentes (institucion_id, id);
CREATE INDEX ON moodle_cursos (institucion_id, carga_docente_id);
ALTER TABLE moodle_usuarios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_usuarios (institucion_id);
ALTER TABLE moodle_usuarios ADD FOREIGN KEY (institucion_id, moodle_conexion_id) REFERENCES moodle_conexiones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON moodle_usuarios (institucion_id, moodle_conexion_id);
ALTER TABLE moodle_usuarios ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON moodle_usuarios (user_id);
ALTER TABLE moodle_sincronizaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_sincronizaciones (institucion_id);
ALTER TABLE moodle_sincronizaciones ADD FOREIGN KEY (institucion_id, moodle_conexion_id) REFERENCES moodle_conexiones (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON moodle_sincronizaciones (institucion_id, moodle_conexion_id);
ALTER TABLE moodle_tablas_conversion ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_tablas_conversion (institucion_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON moodle_notas_recibidas (institucion_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (institucion_id, moodle_curso_id) REFERENCES moodle_cursos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON moodle_notas_recibidas (institucion_id, moodle_curso_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON moodle_notas_recibidas (institucion_id, alumno_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (competencia_id) REFERENCES competencias (id);
CREATE INDEX ON moodle_notas_recibidas (competencia_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON moodle_notas_recibidas (institucion_id, periodo_id);
ALTER TABLE moodle_notas_recibidas ADD FOREIGN KEY (aprobado_por) REFERENCES users (id);
CREATE INDEX ON moodle_notas_recibidas (aprobado_por);
ALTER TABLE bib_libros ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_libros (institucion_id);
ALTER TABLE bib_libros ADD FOREIGN KEY (area_curricular_id) REFERENCES areas_curriculares (id);
CREATE INDEX ON bib_libros (area_curricular_id);
ALTER TABLE bib_libros ADD FOREIGN KEY (institucion_id, archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON bib_libros (institucion_id, archivo_id);
ALTER TABLE bib_ejemplares ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_ejemplares (institucion_id);
ALTER TABLE bib_ejemplares ADD FOREIGN KEY (institucion_id, bib_libro_id) REFERENCES bib_libros (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON bib_ejemplares (institucion_id, bib_libro_id);
ALTER TABLE bib_prestamos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_prestamos (institucion_id);
ALTER TABLE bib_prestamos ADD FOREIGN KEY (institucion_id, bib_ejemplar_id) REFERENCES bib_ejemplares (institucion_id, id);
CREATE INDEX ON bib_prestamos (institucion_id, bib_ejemplar_id);
ALTER TABLE bib_prestamos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON bib_prestamos (registrado_por);
ALTER TABLE bib_reservas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_reservas (institucion_id);
ALTER TABLE bib_reservas ADD FOREIGN KEY (institucion_id, bib_libro_id) REFERENCES bib_libros (institucion_id, id);
CREATE INDEX ON bib_reservas (institucion_id, bib_libro_id);
ALTER TABLE bib_reservas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON bib_reservas (institucion_id, alumno_id);
ALTER TABLE bib_plan_lector ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_plan_lector (institucion_id);
ALTER TABLE bib_plan_lector ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON bib_plan_lector (institucion_id, grado_id);
ALTER TABLE bib_plan_lector ADD FOREIGN KEY (institucion_id, periodo_id) REFERENCES periodos (institucion_id, id);
CREATE INDEX ON bib_plan_lector (institucion_id, periodo_id);
ALTER TABLE bib_plan_lector ADD FOREIGN KEY (institucion_id, bib_libro_id) REFERENCES bib_libros (institucion_id, id);
CREATE INDEX ON bib_plan_lector (institucion_id, bib_libro_id);
ALTER TABLE bib_plan_lector ADD FOREIGN KEY (docente_id) REFERENCES users (id);
CREATE INDEX ON bib_plan_lector (docente_id);
ALTER TABLE bib_plan_lector_avance ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_plan_lector_avance (institucion_id);
ALTER TABLE bib_plan_lector_avance ADD FOREIGN KEY (institucion_id, bib_plan_lector_id) REFERENCES bib_plan_lector (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON bib_plan_lector_avance (institucion_id, bib_plan_lector_id);
ALTER TABLE bib_plan_lector_avance ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON bib_plan_lector_avance (institucion_id, alumno_id);
ALTER TABLE bib_plan_lector_avance ADD FOREIGN KEY (institucion_id, ficha_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON bib_plan_lector_avance (institucion_id, ficha_archivo_id);
ALTER TABLE bib_lecturas_digitales ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_lecturas_digitales (institucion_id);
ALTER TABLE bib_lecturas_digitales ADD FOREIGN KEY (institucion_id, bib_libro_id) REFERENCES bib_libros (institucion_id, id);
CREATE INDEX ON bib_lecturas_digitales (institucion_id, bib_libro_id);
ALTER TABLE bib_lecturas_digitales ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON bib_lecturas_digitales (user_id);
ALTER TABLE bib_reglas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_reglas (institucion_id);
ALTER TABLE bib_suspensiones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON bib_suspensiones (institucion_id);
ALTER TABLE bib_suspensiones ADD FOREIGN KEY (institucion_id, bib_prestamo_id) REFERENCES bib_prestamos (institucion_id, id);
CREATE INDEX ON bib_suspensiones (institucion_id, bib_prestamo_id);
ALTER TABLE mov_unidades ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_unidades (institucion_id);
ALTER TABLE mov_conductores ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_conductores (institucion_id);
ALTER TABLE mov_conductores ADD FOREIGN KEY (user_id) REFERENCES users (id);
CREATE INDEX ON mov_conductores (user_id);
ALTER TABLE mov_rutas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_rutas (institucion_id);
ALTER TABLE mov_rutas ADD FOREIGN KEY (institucion_id, mov_unidad_id) REFERENCES mov_unidades (institucion_id, id);
CREATE INDEX ON mov_rutas (institucion_id, mov_unidad_id);
ALTER TABLE mov_rutas ADD FOREIGN KEY (institucion_id, conductor_id) REFERENCES mov_conductores (institucion_id, id);
CREATE INDEX ON mov_rutas (institucion_id, conductor_id);
ALTER TABLE mov_rutas ADD FOREIGN KEY (institucion_id, auxiliar_id) REFERENCES mov_conductores (institucion_id, id);
CREATE INDEX ON mov_rutas (institucion_id, auxiliar_id);
ALTER TABLE mov_rutas ADD FOREIGN KEY (institucion_id, concepto_cobro_id) REFERENCES conceptos_cobro (institucion_id, id);
CREATE INDEX ON mov_rutas (institucion_id, concepto_cobro_id);
ALTER TABLE mov_paraderos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_paraderos (institucion_id);
ALTER TABLE mov_paraderos ADD FOREIGN KEY (institucion_id, mov_ruta_id) REFERENCES mov_rutas (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON mov_paraderos (institucion_id, mov_ruta_id);
ALTER TABLE mov_asignaciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_asignaciones (institucion_id);
ALTER TABLE mov_asignaciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON mov_asignaciones (institucion_id, alumno_id);
ALTER TABLE mov_asignaciones ADD FOREIGN KEY (institucion_id, mov_ruta_id) REFERENCES mov_rutas (institucion_id, id);
CREATE INDEX ON mov_asignaciones (institucion_id, mov_ruta_id);
ALTER TABLE mov_asignaciones ADD FOREIGN KEY (institucion_id, mov_paradero_id) REFERENCES mov_paraderos (institucion_id, id);
CREATE INDEX ON mov_asignaciones (institucion_id, mov_paradero_id);
ALTER TABLE mov_asignaciones ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON mov_asignaciones (institucion_id, anio_escolar_id);
ALTER TABLE mov_recorridos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_recorridos (institucion_id);
ALTER TABLE mov_recorridos ADD FOREIGN KEY (institucion_id, mov_ruta_id) REFERENCES mov_rutas (institucion_id, id);
CREATE INDEX ON mov_recorridos (institucion_id, mov_ruta_id);
ALTER TABLE mov_recorridos ADD FOREIGN KEY (iniciado_por) REFERENCES users (id);
CREATE INDEX ON mov_recorridos (iniciado_por);
ALTER TABLE mov_posiciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_posiciones (institucion_id);
ALTER TABLE mov_posiciones ADD FOREIGN KEY (institucion_id, mov_recorrido_id) REFERENCES mov_recorridos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON mov_posiciones (institucion_id, mov_recorrido_id);
CREATE INDEX ON mov_posiciones (institucion_id, mov_recorrido_id, registrado_at);
ALTER TABLE mov_eventos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON mov_eventos (institucion_id);
ALTER TABLE mov_eventos ADD FOREIGN KEY (institucion_id, mov_asignacion_id) REFERENCES mov_asignaciones (institucion_id, id);
CREATE INDEX ON mov_eventos (institucion_id, mov_asignacion_id);
ALTER TABLE mov_eventos ADD FOREIGN KEY (institucion_id, mov_recorrido_id) REFERENCES mov_recorridos (institucion_id, id);
CREATE INDEX ON mov_eventos (institucion_id, mov_recorrido_id);
ALTER TABLE mov_eventos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON mov_eventos (registrado_por);
ALTER TABLE com_menus ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON com_menus (institucion_id);
ALTER TABLE com_menus ADD FOREIGN KEY (aprobado_por) REFERENCES users (id);
CREATE INDEX ON com_menus (aprobado_por);
ALTER TABLE com_planes ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON com_planes (institucion_id);
ALTER TABLE com_planes ADD FOREIGN KEY (institucion_id, concepto_cobro_id) REFERENCES conceptos_cobro (institucion_id, id);
CREATE INDEX ON com_planes (institucion_id, concepto_cobro_id);
ALTER TABLE com_inscripciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON com_inscripciones (institucion_id);
ALTER TABLE com_inscripciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON com_inscripciones (institucion_id, alumno_id);
ALTER TABLE com_inscripciones ADD FOREIGN KEY (institucion_id, com_plan_id) REFERENCES com_planes (institucion_id, id);
CREATE INDEX ON com_inscripciones (institucion_id, com_plan_id);
ALTER TABLE com_consumos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON com_consumos (institucion_id);
ALTER TABLE com_consumos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON com_consumos (institucion_id, alumno_id);
ALTER TABLE com_consumos ADD FOREIGN KEY (institucion_id, com_menu_id) REFERENCES com_menus (institucion_id, id);
CREATE INDEX ON com_consumos (institucion_id, com_menu_id);
ALTER TABLE com_consumos ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON com_consumos (institucion_id, cargo_id);
ALTER TABLE com_consumos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON com_consumos (registrado_por);
ALTER TABLE salud.fichas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON salud.fichas (institucion_id);
ALTER TABLE salud.fichas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON salud.fichas (institucion_id, alumno_id);
ALTER TABLE salud.fichas ADD FOREIGN KEY (actualizado_por) REFERENCES users (id);
CREATE INDEX ON salud.fichas (actualizado_por);
ALTER TABLE salud.atenciones ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON salud.atenciones (institucion_id);
ALTER TABLE salud.atenciones ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON salud.atenciones (institucion_id, alumno_id);
ALTER TABLE salud.atenciones ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON salud.atenciones (registrado_por);
ALTER TABLE salud.campanas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON salud.campanas (institucion_id);
ALTER TABLE salud.campanas ADD FOREIGN KEY (institucion_id, autorizacion_id) REFERENCES autorizaciones (institucion_id, id);
CREATE INDEX ON salud.campanas (institucion_id, autorizacion_id);
ALTER TABLE salud.restricciones_alimentarias ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON salud.restricciones_alimentarias (institucion_id);
ALTER TABLE salud.restricciones_alimentarias ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON salud.restricciones_alimentarias (institucion_id, alumno_id);
ALTER TABLE salud.restricciones_alimentarias ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON salud.restricciones_alimentarias (registrado_por);
ALTER TABLE salud.mediciones_nutricionales ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON salud.mediciones_nutricionales (institucion_id);
ALTER TABLE salud.mediciones_nutricionales ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON salud.mediciones_nutricionales (institucion_id, alumno_id);
ALTER TABLE salud.mediciones_nutricionales ADD FOREIGN KEY (institucion_id, campana_id) REFERENCES salud.campanas (institucion_id, id);
CREATE INDEX ON salud.mediciones_nutricionales (institucion_id, campana_id);
ALTER TABLE salud.mediciones_nutricionales ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON salud.mediciones_nutricionales (registrado_por);
ALTER TABLE tienda_productos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_productos (institucion_id);
ALTER TABLE tienda_pack_items ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_pack_items (institucion_id);
ALTER TABLE tienda_pack_items ADD FOREIGN KEY (institucion_id, pack_id) REFERENCES tienda_productos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tienda_pack_items (institucion_id, pack_id);
ALTER TABLE tienda_pack_items ADD FOREIGN KEY (institucion_id, producto_id) REFERENCES tienda_productos (institucion_id, id);
CREATE INDEX ON tienda_pack_items (institucion_id, producto_id);
ALTER TABLE tienda_listas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_listas (institucion_id);
ALTER TABLE tienda_listas ADD FOREIGN KEY (institucion_id, grado_id) REFERENCES grados (institucion_id, id);
CREATE INDEX ON tienda_listas (institucion_id, grado_id);
ALTER TABLE tienda_listas ADD FOREIGN KEY (institucion_id, anio_escolar_id) REFERENCES anios_escolares (institucion_id, id);
CREATE INDEX ON tienda_listas (institucion_id, anio_escolar_id);
ALTER TABLE tienda_listas ADD FOREIGN KEY (institucion_id, pack_id) REFERENCES tienda_productos (institucion_id, id);
CREATE INDEX ON tienda_listas (institucion_id, pack_id);
ALTER TABLE tienda_lista_items ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_lista_items (institucion_id);
ALTER TABLE tienda_lista_items ADD FOREIGN KEY (institucion_id, tienda_lista_id) REFERENCES tienda_listas (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tienda_lista_items (institucion_id, tienda_lista_id);
ALTER TABLE tienda_lista_items ADD FOREIGN KEY (institucion_id, producto_id) REFERENCES tienda_productos (institucion_id, id);
CREATE INDEX ON tienda_lista_items (institucion_id, producto_id);
ALTER TABLE tienda_pedidos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_pedidos (institucion_id);
ALTER TABLE tienda_pedidos ADD FOREIGN KEY (institucion_id, apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON tienda_pedidos (institucion_id, apoderado_id);
ALTER TABLE tienda_pedidos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON tienda_pedidos (institucion_id, alumno_id);
ALTER TABLE tienda_pedidos ADD FOREIGN KEY (institucion_id, pago_id) REFERENCES pagos (institucion_id, id);
CREATE INDEX ON tienda_pedidos (institucion_id, pago_id);
ALTER TABLE tienda_pedidos ADD FOREIGN KEY (institucion_id, cargo_id) REFERENCES cargos (institucion_id, id);
CREATE INDEX ON tienda_pedidos (institucion_id, cargo_id);
ALTER TABLE tienda_pedido_items ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_pedido_items (institucion_id);
ALTER TABLE tienda_pedido_items ADD FOREIGN KEY (institucion_id, tienda_pedido_id) REFERENCES tienda_pedidos (institucion_id, id) ON DELETE CASCADE;
CREATE INDEX ON tienda_pedido_items (institucion_id, tienda_pedido_id);
ALTER TABLE tienda_pedido_items ADD FOREIGN KEY (institucion_id, producto_id) REFERENCES tienda_productos (institucion_id, id);
CREATE INDEX ON tienda_pedido_items (institucion_id, producto_id);
ALTER TABLE tienda_movimientos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON tienda_movimientos (institucion_id);
ALTER TABLE tienda_movimientos ADD FOREIGN KEY (institucion_id, producto_id) REFERENCES tienda_productos (institucion_id, id);
CREATE INDEX ON tienda_movimientos (institucion_id, producto_id);
ALTER TABLE tienda_movimientos ADD FOREIGN KEY (institucion_id, tienda_pedido_id) REFERENCES tienda_pedidos (institucion_id, id);
CREATE INDEX ON tienda_movimientos (institucion_id, tienda_pedido_id);
ALTER TABLE tienda_movimientos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON tienda_movimientos (registrado_por);
ALTER TABLE por_ingresos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON por_ingresos (institucion_id);
ALTER TABLE por_ingresos ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON por_ingresos (institucion_id, alumno_id);
ALTER TABLE por_ingresos ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON por_ingresos (registrado_por);
ALTER TABLE por_autorizados ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON por_autorizados (institucion_id);
ALTER TABLE por_autorizados ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON por_autorizados (institucion_id, alumno_id);
ALTER TABLE por_autorizados ADD FOREIGN KEY (institucion_id, registrado_por_apoderado_id) REFERENCES apoderados (institucion_id, id);
CREATE INDEX ON por_autorizados (institucion_id, registrado_por_apoderado_id);
ALTER TABLE por_salidas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON por_salidas (institucion_id);
ALTER TABLE por_salidas ADD FOREIGN KEY (institucion_id, alumno_id) REFERENCES alumnos (institucion_id, id);
CREATE INDEX ON por_salidas (institucion_id, alumno_id);
ALTER TABLE por_salidas ADD FOREIGN KEY (institucion_id, por_autorizado_id) REFERENCES por_autorizados (institucion_id, id);
CREATE INDEX ON por_salidas (institucion_id, por_autorizado_id);
ALTER TABLE por_salidas ADD FOREIGN KEY (verificado_por) REFERENCES users (id);
CREATE INDEX ON por_salidas (verificado_por);
ALTER TABLE por_visitas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON por_visitas (institucion_id);
ALTER TABLE por_visitas ADD FOREIGN KEY (registrado_por) REFERENCES users (id);
CREATE INDEX ON por_visitas (registrado_por);
ALTER TABLE lab_espacios ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_espacios (institucion_id);
ALTER TABLE lab_reservas ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_reservas (institucion_id);
ALTER TABLE lab_reservas ADD FOREIGN KEY (institucion_id, lab_espacio_id) REFERENCES lab_espacios (institucion_id, id);
CREATE INDEX ON lab_reservas (institucion_id, lab_espacio_id);
ALTER TABLE lab_reservas ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON lab_reservas (institucion_id, seccion_id);
ALTER TABLE lab_reservas ADD FOREIGN KEY (docente_id) REFERENCES users (id);
CREATE INDEX ON lab_reservas (docente_id);
ALTER TABLE lab_equipos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_equipos (institucion_id);
ALTER TABLE lab_prestamos ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_prestamos (institucion_id);
ALTER TABLE lab_prestamos ADD FOREIGN KEY (institucion_id, lab_equipo_id) REFERENCES lab_equipos (institucion_id, id);
CREATE INDEX ON lab_prestamos (institucion_id, lab_equipo_id);
ALTER TABLE lab_prestamos ADD FOREIGN KEY (prestado_a) REFERENCES users (id);
CREATE INDEX ON lab_prestamos (prestado_a);
ALTER TABLE lab_prestamos ADD FOREIGN KEY (institucion_id, seccion_id) REFERENCES secciones (institucion_id, id);
CREATE INDEX ON lab_prestamos (institucion_id, seccion_id);
ALTER TABLE lab_materiales ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_materiales (institucion_id);
ALTER TABLE lab_materiales ADD FOREIGN KEY (institucion_id, hoja_seguridad_archivo_id) REFERENCES archivos (institucion_id, id);
CREATE INDEX ON lab_materiales (institucion_id, hoja_seguridad_archivo_id);
ALTER TABLE lab_incidencias ADD FOREIGN KEY (institucion_id) REFERENCES instituciones(id) ON DELETE CASCADE;
CREATE INDEX ON lab_incidencias (institucion_id);
ALTER TABLE lab_incidencias ADD FOREIGN KEY (institucion_id, lab_equipo_id) REFERENCES lab_equipos (institucion_id, id);
CREATE INDEX ON lab_incidencias (institucion_id, lab_equipo_id);
ALTER TABLE lab_incidencias ADD FOREIGN KEY (reportado_por) REFERENCES users (id);
CREATE INDEX ON lab_incidencias (reportado_por);

-- ============================ AISLAMIENTO (RLS FORZADO)
ALTER TABLE suscripciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE suscripciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON suscripciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON suscripciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE suscripcion_modulos ENABLE ROW LEVEL SECURITY;
ALTER TABLE suscripcion_modulos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON suscripcion_modulos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON suscripcion_modulos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE facturas_saas ENABLE ROW LEVEL SECURITY;
ALTER TABLE facturas_saas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON facturas_saas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON facturas_saas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tickets_soporte ENABLE ROW LEVEL SECURITY;
ALTER TABLE tickets_soporte FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tickets_soporte USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tickets_soporte TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE ticket_mensajes ENABLE ROW LEVEL SECURITY;
ALTER TABLE ticket_mensajes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON ticket_mensajes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON ticket_mensajes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE membresias ENABLE ROW LEVEL SECURITY;
ALTER TABLE membresias FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON membresias USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON membresias TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE auditoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE auditoria FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON auditoria USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON auditoria TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE consentimientos ENABLE ROW LEVEL SECURITY;
ALTER TABLE consentimientos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON consentimientos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON consentimientos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE archivos ENABLE ROW LEVEL SECURITY;
ALTER TABLE archivos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON archivos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON archivos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE servicios_educativos ENABLE ROW LEVEL SECURITY;
ALTER TABLE servicios_educativos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON servicios_educativos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON servicios_educativos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE sedes ENABLE ROW LEVEL SECURITY;
ALTER TABLE sedes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON sedes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON sedes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE ambientes ENABLE ROW LEVEL SECURITY;
ALTER TABLE ambientes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON ambientes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON ambientes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE anios_escolares ENABLE ROW LEVEL SECURITY;
ALTER TABLE anios_escolares FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON anios_escolares USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON anios_escolares TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE periodos ENABLE ROW LEVEL SECURITY;
ALTER TABLE periodos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON periodos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON periodos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE feriados ENABLE ROW LEVEL SECURITY;
ALTER TABLE feriados FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON feriados USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON feriados TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE grados ENABLE ROW LEVEL SECURITY;
ALTER TABLE grados FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON grados USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON grados TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE area_curricular_grado ENABLE ROW LEVEL SECURITY;
ALTER TABLE area_curricular_grado FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON area_curricular_grado USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON area_curricular_grado TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE secciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE secciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON secciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON secciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE personal ENABLE ROW LEVEL SECURITY;
ALTER TABLE personal FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON personal USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON personal TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cargas_docentes ENABLE ROW LEVEL SECURITY;
ALTER TABLE cargas_docentes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cargas_docentes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cargas_docentes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bloques_horarios ENABLE ROW LEVEL SECURITY;
ALTER TABLE bloques_horarios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bloques_horarios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bloques_horarios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE horario_sesiones ENABLE ROW LEVEL SECURITY;
ALTER TABLE horario_sesiones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON horario_sesiones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON horario_sesiones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE alumnos ENABLE ROW LEVEL SECURITY;
ALTER TABLE alumnos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON alumnos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON alumnos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE apoderados ENABLE ROW LEVEL SECURITY;
ALTER TABLE apoderados FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON apoderados USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON apoderados TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE alumno_apoderado ENABLE ROW LEVEL SECURITY;
ALTER TABLE alumno_apoderado FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON alumno_apoderado USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON alumno_apoderado TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE admision_procesos ENABLE ROW LEVEL SECURITY;
ALTER TABLE admision_procesos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON admision_procesos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON admision_procesos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE vacantes ENABLE ROW LEVEL SECURITY;
ALTER TABLE vacantes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON vacantes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON vacantes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE postulantes ENABLE ROW LEVEL SECURITY;
ALTER TABLE postulantes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON postulantes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON postulantes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE postulante_eventos ENABLE ROW LEVEL SECURITY;
ALTER TABLE postulante_eventos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON postulante_eventos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON postulante_eventos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE matriculas ENABLE ROW LEVEL SECURITY;
ALTER TABLE matriculas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON matriculas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON matriculas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE condiciones_economicas ENABLE ROW LEVEL SECURITY;
ALTER TABLE condiciones_economicas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON condiciones_economicas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON condiciones_economicas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE condiciones_aceptaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE condiciones_aceptaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON condiciones_aceptaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON condiciones_aceptaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE movimientos_matricula ENABLE ROW LEVEL SECURITY;
ALTER TABLE movimientos_matricula FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON movimientos_matricula USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON movimientos_matricula TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE comunicados ENABLE ROW LEVEL SECURITY;
ALTER TABLE comunicados FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON comunicados USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON comunicados TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE comunicado_destinatarios ENABLE ROW LEVEL SECURITY;
ALTER TABLE comunicado_destinatarios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON comunicado_destinatarios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON comunicado_destinatarios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE conversaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON conversaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON conversaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE conversacion_participantes ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversacion_participantes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON conversacion_participantes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON conversacion_participantes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mensajes ENABLE ROW LEVEL SECURITY;
ALTER TABLE mensajes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mensajes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mensajes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE eventos_calendario ENABLE ROW LEVEL SECURITY;
ALTER TABLE eventos_calendario FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON eventos_calendario USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON eventos_calendario TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE citas ENABLE ROW LEVEL SECURITY;
ALTER TABLE citas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON citas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON citas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE reclamaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE reclamaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON reclamaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON reclamaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE reclamacion_respuestas ENABLE ROW LEVEL SECURITY;
ALTER TABLE reclamacion_respuestas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON reclamacion_respuestas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON reclamacion_respuestas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE notificaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE notificaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON notificaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON notificaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE conceptos_cobro ENABLE ROW LEVEL SECURITY;
ALTER TABLE conceptos_cobro FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON conceptos_cobro USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON conceptos_cobro TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cronogramas ENABLE ROW LEVEL SECURITY;
ALTER TABLE cronogramas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cronogramas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cronogramas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cronograma_cuotas ENABLE ROW LEVEL SECURITY;
ALTER TABLE cronograma_cuotas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cronograma_cuotas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cronograma_cuotas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE beneficios ENABLE ROW LEVEL SECURITY;
ALTER TABLE beneficios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON beneficios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON beneficios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE alumno_beneficios ENABLE ROW LEVEL SECURITY;
ALTER TABLE alumno_beneficios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON alumno_beneficios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON alumno_beneficios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cargos ENABLE ROW LEVEL SECURITY;
ALTER TABLE cargos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cargos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cargos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE pagos ENABLE ROW LEVEL SECURITY;
ALTER TABLE pagos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON pagos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON pagos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE pago_aplicaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE pago_aplicaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON pago_aplicaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON pago_aplicaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cajas ENABLE ROW LEVEL SECURITY;
ALTER TABLE cajas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cajas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cajas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE caja_sesiones ENABLE ROW LEVEL SECURITY;
ALTER TABLE caja_sesiones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON caja_sesiones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON caja_sesiones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE proveedores_facturacion ENABLE ROW LEVEL SECURITY;
ALTER TABLE proveedores_facturacion FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON proveedores_facturacion USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON proveedores_facturacion TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE comprobantes ENABLE ROW LEVEL SECURITY;
ALTER TABLE comprobantes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON comprobantes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON comprobantes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE comprobante_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE comprobante_items FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON comprobante_items USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON comprobante_items TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE pasarela_cuentas ENABLE ROW LEVEL SECURITY;
ALTER TABLE pasarela_cuentas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON pasarela_cuentas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON pasarela_cuentas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE pasarela_transacciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE pasarela_transacciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON pasarela_transacciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON pasarela_transacciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE recaudacion_archivos ENABLE ROW LEVEL SECURITY;
ALTER TABLE recaudacion_archivos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON recaudacion_archivos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON recaudacion_archivos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE recaudacion_lineas ENABLE ROW LEVEL SECURITY;
ALTER TABLE recaudacion_lineas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON recaudacion_lineas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON recaudacion_lineas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE compromisos_pago ENABLE ROW LEVEL SECURITY;
ALTER TABLE compromisos_pago FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON compromisos_pago USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON compromisos_pago TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE convenios_fraccionamiento ENABLE ROW LEVEL SECURITY;
ALTER TABLE convenios_fraccionamiento FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON convenios_fraccionamiento USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON convenios_fraccionamiento TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE convenio_cargos ENABLE ROW LEVEL SECURITY;
ALTER TABLE convenio_cargos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON convenio_cargos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON convenio_cargos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE convenio_cuotas ENABLE ROW LEVEL SECURITY;
ALTER TABLE convenio_cuotas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON convenio_cuotas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON convenio_cuotas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE gestiones_cobranza ENABLE ROW LEVEL SECURITY;
ALTER TABLE gestiones_cobranza FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON gestiones_cobranza USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON gestiones_cobranza TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE actividades_evaluacion ENABLE ROW LEVEL SECURITY;
ALTER TABLE actividades_evaluacion FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON actividades_evaluacion USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON actividades_evaluacion TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE notas_actividad ENABLE ROW LEVEL SECURITY;
ALTER TABLE notas_actividad FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON notas_actividad USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON notas_actividad TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE calificaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE calificaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON calificaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON calificaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE cierres_periodo ENABLE ROW LEVEL SECURITY;
ALTER TABLE cierres_periodo FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON cierres_periodo USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON cierres_periodo TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE asistencias ENABLE ROW LEVEL SECURITY;
ALTER TABLE asistencias FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON asistencias USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON asistencias TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE justificaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE justificaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON justificaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON justificaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE incidencias_conducta ENABLE ROW LEVEL SECURITY;
ALTER TABLE incidencias_conducta FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON incidencias_conducta USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON incidencias_conducta TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE entrevistas_tutoria ENABLE ROW LEVEL SECURITY;
ALTER TABLE entrevistas_tutoria FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON entrevistas_tutoria USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON entrevistas_tutoria TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE alertas_riesgo ENABLE ROW LEVEL SECURITY;
ALTER TABLE alertas_riesgo FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON alertas_riesgo USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON alertas_riesgo TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE libretas ENABLE ROW LEVEL SECURITY;
ALTER TABLE libretas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON libretas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON libretas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE evaluaciones_recuperacion ENABLE ROW LEVEL SECURITY;
ALTER TABLE evaluaciones_recuperacion FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON evaluaciones_recuperacion USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON evaluaciones_recuperacion TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE siagie_procesos ENABLE ROW LEVEL SECURITY;
ALTER TABLE siagie_procesos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON siagie_procesos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON siagie_procesos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE siagie_errores ENABLE ROW LEVEL SECURITY;
ALTER TABLE siagie_errores FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON siagie_errores USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON siagie_errores TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE siagie_mapeos ENABLE ROW LEVEL SECURITY;
ALTER TABLE siagie_mapeos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON siagie_mapeos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON siagie_mapeos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tramite_tipos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tramite_tipos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tramite_tipos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tramite_tipos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tramite_requisitos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tramite_requisitos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tramite_requisitos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tramite_requisitos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tramites ENABLE ROW LEVEL SECURITY;
ALTER TABLE tramites FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tramites USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tramites TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tramite_historial ENABLE ROW LEVEL SECURITY;
ALTER TABLE tramite_historial FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tramite_historial USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tramite_historial TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tramite_adjuntos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tramite_adjuntos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tramite_adjuntos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tramite_adjuntos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE plantillas_documento ENABLE ROW LEVEL SECURITY;
ALTER TABLE plantillas_documento FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON plantillas_documento USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON plantillas_documento TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE documentos_emitidos ENABLE ROW LEVEL SECURITY;
ALTER TABLE documentos_emitidos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON documentos_emitidos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON documentos_emitidos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE expediente_documentos ENABLE ROW LEVEL SECURITY;
ALTER TABLE expediente_documentos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON expediente_documentos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON expediente_documentos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE documentos_institucionales ENABLE ROW LEVEL SECURITY;
ALTER TABLE documentos_institucionales FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON documentos_institucionales USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON documentos_institucionales TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE documento_lecturas ENABLE ROW LEVEL SECURITY;
ALTER TABLE documento_lecturas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON documento_lecturas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON documento_lecturas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE autorizaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE autorizaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON autorizaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON autorizaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE autorizacion_firmas ENABLE ROW LEVEL SECURITY;
ALTER TABLE autorizacion_firmas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON autorizacion_firmas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON autorizacion_firmas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_cursos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_cursos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_cursos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_cursos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_unidades ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_unidades FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_unidades USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_unidades TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_recursos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_recursos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_recursos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_recursos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_tareas ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_tareas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_tareas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_tareas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_entregas ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_entregas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_entregas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_entregas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_bancos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_bancos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_bancos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_bancos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_preguntas ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_preguntas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_preguntas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_preguntas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_cuestionarios ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_cuestionarios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_cuestionarios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_cuestionarios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_cuestionario_preguntas ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_cuestionario_preguntas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_cuestionario_preguntas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_cuestionario_preguntas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_intentos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_intentos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_intentos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_intentos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_intentos_extra ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_intentos_extra FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_intentos_extra USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_intentos_extra TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_progreso ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_progreso FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_progreso USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_progreso TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_encuestas ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_encuestas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_encuestas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_encuestas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_observaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_observaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_observaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_observaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_avisos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_avisos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_avisos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_avisos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_foros ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_foros FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_foros USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_foros TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_foro_mensajes ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_foro_mensajes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_foro_mensajes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_foro_mensajes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_clases_vivo ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_clases_vivo FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_clases_vivo USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_clases_vivo TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE aula_accesos ENABLE ROW LEVEL SECURITY;
ALTER TABLE aula_accesos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON aula_accesos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON aula_accesos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_conexiones ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_conexiones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_conexiones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_conexiones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_cursos ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_cursos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_cursos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_cursos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_usuarios ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_usuarios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_usuarios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_usuarios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_sincronizaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_sincronizaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_sincronizaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_sincronizaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_tablas_conversion ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_tablas_conversion FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_tablas_conversion USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_tablas_conversion TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE moodle_notas_recibidas ENABLE ROW LEVEL SECURITY;
ALTER TABLE moodle_notas_recibidas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON moodle_notas_recibidas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON moodle_notas_recibidas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_libros ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_libros FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_libros USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_libros TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_ejemplares ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_ejemplares FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_ejemplares USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_ejemplares TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_prestamos ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_prestamos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_prestamos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_prestamos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_reservas ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_reservas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_reservas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_reservas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_plan_lector ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_plan_lector FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_plan_lector USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_plan_lector TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_plan_lector_avance ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_plan_lector_avance FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_plan_lector_avance USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_plan_lector_avance TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_lecturas_digitales ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_lecturas_digitales FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_lecturas_digitales USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_lecturas_digitales TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_reglas ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_reglas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_reglas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_reglas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE bib_suspensiones ENABLE ROW LEVEL SECURITY;
ALTER TABLE bib_suspensiones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON bib_suspensiones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON bib_suspensiones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_unidades ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_unidades FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_unidades USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_unidades TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_conductores ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_conductores FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_conductores USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_conductores TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_rutas ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_rutas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_rutas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_rutas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_paraderos ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_paraderos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_paraderos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_paraderos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_asignaciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_asignaciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_asignaciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_asignaciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_recorridos ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_recorridos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_recorridos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_recorridos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_posiciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_posiciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_posiciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_posiciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE mov_eventos ENABLE ROW LEVEL SECURITY;
ALTER TABLE mov_eventos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON mov_eventos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON mov_eventos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE com_menus ENABLE ROW LEVEL SECURITY;
ALTER TABLE com_menus FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON com_menus USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON com_menus TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE com_planes ENABLE ROW LEVEL SECURITY;
ALTER TABLE com_planes FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON com_planes USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON com_planes TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE com_inscripciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE com_inscripciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON com_inscripciones USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON com_inscripciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE com_consumos ENABLE ROW LEVEL SECURITY;
ALTER TABLE com_consumos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON com_consumos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON com_consumos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE salud.fichas ENABLE ROW LEVEL SECURITY;
ALTER TABLE salud.fichas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON salud.fichas USING (institucion_id = app_institucion() AND app_acceso_salud()) WITH CHECK (institucion_id = app_institucion() AND app_acceso_salud());
CREATE POLICY plataforma ON salud.fichas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE salud.atenciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE salud.atenciones FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON salud.atenciones USING (institucion_id = app_institucion() AND app_acceso_salud()) WITH CHECK (institucion_id = app_institucion() AND app_acceso_salud());
CREATE POLICY plataforma ON salud.atenciones TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE salud.campanas ENABLE ROW LEVEL SECURITY;
ALTER TABLE salud.campanas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON salud.campanas USING (institucion_id = app_institucion() AND app_acceso_salud()) WITH CHECK (institucion_id = app_institucion() AND app_acceso_salud());
CREATE POLICY plataforma ON salud.campanas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE salud.restricciones_alimentarias ENABLE ROW LEVEL SECURITY;
ALTER TABLE salud.restricciones_alimentarias FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON salud.restricciones_alimentarias USING (institucion_id = app_institucion() AND app_acceso_salud()) WITH CHECK (institucion_id = app_institucion() AND app_acceso_salud());
CREATE POLICY plataforma ON salud.restricciones_alimentarias TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE salud.mediciones_nutricionales ENABLE ROW LEVEL SECURITY;
ALTER TABLE salud.mediciones_nutricionales FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON salud.mediciones_nutricionales USING (institucion_id = app_institucion() AND app_acceso_salud()) WITH CHECK (institucion_id = app_institucion() AND app_acceso_salud());
CREATE POLICY plataforma ON salud.mediciones_nutricionales TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_productos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_productos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_productos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_productos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_pack_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_pack_items FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_pack_items USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_pack_items TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_listas ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_listas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_listas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_listas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_lista_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_lista_items FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_lista_items USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_lista_items TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_pedidos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_pedidos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_pedidos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_pedidos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_pedido_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_pedido_items FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_pedido_items USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_pedido_items TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE tienda_movimientos ENABLE ROW LEVEL SECURITY;
ALTER TABLE tienda_movimientos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON tienda_movimientos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON tienda_movimientos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE por_ingresos ENABLE ROW LEVEL SECURITY;
ALTER TABLE por_ingresos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON por_ingresos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON por_ingresos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE por_autorizados ENABLE ROW LEVEL SECURITY;
ALTER TABLE por_autorizados FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON por_autorizados USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON por_autorizados TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE por_salidas ENABLE ROW LEVEL SECURITY;
ALTER TABLE por_salidas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON por_salidas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON por_salidas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE por_visitas ENABLE ROW LEVEL SECURITY;
ALTER TABLE por_visitas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON por_visitas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON por_visitas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_espacios ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_espacios FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_espacios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_espacios TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_reservas ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_reservas FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_reservas USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_reservas TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_equipos ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_equipos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_equipos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_equipos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_prestamos ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_prestamos FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_prestamos USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_prestamos TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_materiales ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_materiales FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_materiales USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_materiales TO edunova_plataforma USING (true) WITH CHECK (true);
ALTER TABLE lab_incidencias ENABLE ROW LEVEL SECURITY;
ALTER TABLE lab_incidencias FORCE ROW LEVEL SECURITY;
CREATE POLICY aislamiento ON lab_incidencias USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
CREATE POLICY plataforma ON lab_incidencias TO edunova_plataforma USING (true) WITH CHECK (true);

-- instituciones: la app solo ve su propia fila y no puede crear ni borrar colegios.
ALTER TABLE instituciones ENABLE ROW LEVEL SECURITY;
ALTER TABLE instituciones FORCE ROW LEVEL SECURITY;
CREATE POLICY propia_lectura ON instituciones FOR SELECT USING (id = app_institucion());
CREATE POLICY propia_edicion ON instituciones FOR UPDATE USING (id = app_institucion()) WITH CHECK (id = app_institucion());
CREATE POLICY plataforma ON instituciones TO edunova_plataforma USING (true) WITH CHECK (true);

-- Login: listar las instituciones de un usuario antes de elegir una (sin abrir RLS).
CREATE OR REPLACE FUNCTION fn_instituciones_de_usuario(p_user bigint)
RETURNS TABLE (institucion_id bigint, nombre varchar, slug varchar, rol varchar)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
  SELECT i.id, i.nombre, i.slug, r.codigo
  FROM membresias m JOIN instituciones i ON i.id = m.institucion_id JOIN roles r ON r.id = m.rol_id
  WHERE m.user_id = p_user AND m.activo AND i.estado IN ('implementacion','activo')
$f$;
REVOKE ALL ON FUNCTION fn_instituciones_de_usuario(bigint) FROM PUBLIC;

-- Verificación pública de documentos por QR (sin sesión).
CREATE OR REPLACE FUNCTION fn_verificar_documento(p_codigo varchar)
RETURNS TABLE (institucion varchar, tipo varchar, emitido date, vigente boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
  SELECT i.nombre, coalesce(p.nombre, 'Documento'), d.firmado_at::date, d.anulado_at IS NULL
  FROM documentos_emitidos d JOIN instituciones i ON i.id = d.institucion_id
  LEFT JOIN plantillas_documento p ON p.id = d.plantilla_documento_id AND p.institucion_id = d.institucion_id
  WHERE d.codigo_verificacion = p_codigo
$f$;
REVOKE ALL ON FUNCTION fn_verificar_documento(varchar) FROM PUBLIC;

-- ============================ REGLAS DE NEGOCIO EN LA BASE
-- Auditoría inalterable.
CREATE OR REPLACE FUNCTION tg_solo_insert() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN RAISE EXCEPTION 'La auditoría no se puede modificar ni borrar'; END $f$;
CREATE TRIGGER trg_auditoria_inalterable BEFORE UPDATE OR DELETE ON auditoria FOR EACH ROW EXECUTE FUNCTION tg_solo_insert();

-- Notas: no se pueden cambiar si el periodo o la sección están cerrados.
CREATE OR REPLACE FUNCTION tg_calificacion_periodo_abierto() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE v_periodo bigint := coalesce(NEW.periodo_id, OLD.periodo_id);
        v_seccion bigint := coalesce(NEW.seccion_id, OLD.seccion_id);
BEGIN
  IF current_setting('app.reapertura', true) = 'on' THEN RETURN coalesce(NEW, OLD); END IF;
  IF EXISTS (SELECT 1 FROM periodos WHERE id = v_periodo AND estado = 'cerrado')
     OR EXISTS (SELECT 1 FROM cierres_periodo WHERE periodo_id = v_periodo AND seccion_id = v_seccion AND estado = 'cerrado') THEN
    RAISE EXCEPTION 'El periodo está cerrado: las notas no se pueden modificar' USING ERRCODE = 'check_violation';
  END IF;
  RETURN coalesce(NEW, OLD);
END $f$;
CREATE TRIGGER trg_calificaciones_cerrado BEFORE INSERT OR UPDATE OR DELETE ON calificaciones
  FOR EACH ROW EXECUTE FUNCTION tg_calificacion_periodo_abierto();

-- Pensiones: el vencimiento de una cuota no puede ser anterior al mes que cobra (no cobro adelantado).
ALTER TABLE cronograma_cuotas ADD CHECK (mes ~ '^[0-9]{4}-[0-9]{2}$');
ALTER TABLE cronograma_cuotas ADD CHECK (vencimiento >= to_date(mes || '-01', 'YYYY-MM-DD'));

-- Matrícula: la sección debe pertenecer al mismo año escolar.
CREATE OR REPLACE FUNCTION tg_matricula_anio() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM secciones s WHERE s.id = NEW.seccion_id AND s.anio_escolar_id = NEW.anio_escolar_id) THEN
    RAISE EXCEPTION 'La sección no pertenece al año escolar de la matrícula' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER trg_matriculas_anio BEFORE INSERT OR UPDATE ON matriculas FOR EACH ROW EXECUTE FUNCTION tg_matricula_anio();

-- Movilidad: solo se guardan posiciones de un recorrido en curso (privacidad de la ubicación).
CREATE OR REPLACE FUNCTION tg_posicion_recorrido_en_curso() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM mov_recorridos r WHERE r.id = NEW.mov_recorrido_id AND r.estado = 'en_curso') THEN
    RAISE EXCEPTION 'Solo se registran posiciones de un recorrido en curso' USING ERRCODE = 'check_violation';
  END IF;
  UPDATE mov_recorridos SET ultima_latitud = NEW.latitud, ultima_longitud = NEW.longitud, ultima_posicion_at = NEW.registrado_at
   WHERE id = NEW.mov_recorrido_id AND (ultima_posicion_at IS NULL OR ultima_posicion_at < NEW.registrado_at);
  RETURN NEW;
END $f$;
CREATE TRIGGER trg_mov_posiciones_en_curso BEFORE INSERT ON mov_posiciones FOR EACH ROW EXECUTE FUNCTION tg_posicion_recorrido_en_curso();

-- Movilidad: borrado de posiciones antiguas (tarea diaria con el rol de plataforma).
CREATE OR REPLACE FUNCTION fn_purgar_posiciones(p_dias int DEFAULT 30) RETURNS bigint
LANGUAGE sql AS $f$
  WITH b AS (DELETE FROM mov_posiciones WHERE registrado_at < now() - make_interval(days => p_dias) RETURNING 1)
  SELECT count(*) FROM b
$f$;
REVOKE ALL ON FUNCTION fn_purgar_posiciones(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION fn_purgar_posiciones(int) TO edunova_plataforma;

-- Convenio: las cuotas no pueden sumar más que el monto del convenio.
CREATE OR REPLACE FUNCTION tg_convenio_cuotas_total() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF (SELECT coalesce(sum(monto), 0) FROM convenio_cuotas WHERE convenio_fraccionamiento_id = NEW.convenio_fraccionamiento_id)
     > (SELECT monto_total FROM convenios_fraccionamiento WHERE id = NEW.convenio_fraccionamiento_id) THEN
    RAISE EXCEPTION 'Las cuotas superan el monto del convenio' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $f$;
CREATE CONSTRAINT TRIGGER trg_convenio_cuotas_total AFTER INSERT OR UPDATE ON convenio_cuotas
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION tg_convenio_cuotas_total();

-- ============================ PERMISOS DE ROLES DE BASE DE DATOS
GRANT USAGE ON SCHEMA public, salud TO edunova_app, edunova_plataforma;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public, salud TO edunova_app, edunova_plataforma;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public, salud TO edunova_app, edunova_plataforma;
REVOKE UPDATE, DELETE ON auditoria FROM edunova_app;
REVOKE INSERT, DELETE ON instituciones FROM edunova_app;
-- Catálogos globales: la app solo lee.
REVOKE INSERT, UPDATE, DELETE ON planes, modulos_catalogo, sla_politicas, roles, permisos, rol_permiso, areas_curriculares, competencias, parametros_legales FROM edunova_app;
GRANT EXECUTE ON FUNCTION fn_instituciones_de_usuario(bigint), fn_verificar_documento(varchar) TO edunova_app;
COMMIT;
