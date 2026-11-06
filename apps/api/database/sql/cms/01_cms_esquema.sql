-- =====================================================================
-- Edunova · Sitio web público + Panel CMS
-- Requiere database/schema.sql cargado antes (usa la tabla users).
-- Esquema `cms` — PostgreSQL 16
-- Base: edunova-escolar/sitio-web.md §12, ampliado con lo que piden las
-- pantallas del prototipo (módulos web, versiones, auditoría, medios,
-- consentimiento de testimonios y fecha de demo en leads).
--
-- El contenido del sitio es de Edunova (no de cada colegio): estas tablas
-- NO llevan institucion_id. El acceso se controla por rol de CMS con RLS:
--   app.rol_cms = 'superadmin' | 'publico'
-- Solo el superadmin del SaaS (roles.codigo = 'superadmin', ámbito plataforma) administra el CMS.
-- Los colegios no tienen CMS: ningún rol de colegio (incluido 'promotor', propietario del colegio)
-- tiene acceso; cualquier otro valor se trata como 'publico'.
-- La API fija estos valores al inicio de cada transacción:
--   SET LOCAL app.rol_cms = '...';  SET LOCAL app.usuario_id = '<users.id>';
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS cms;

-- ---------------------------------------------------------------------
-- Utilidades
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cms.rol_actual() RETURNS text
LANGUAGE sql STABLE AS $$ SELECT CASE WHEN current_setting('app.rol_cms', true) = 'superadmin' THEN 'superadmin' ELSE 'publico' END $$;

CREATE OR REPLACE FUNCTION cms.usuario_actual() RETURNS bigint
LANGUAGE sql STABLE AS $$ SELECT nullif(current_setting('app.usuario_id', true), '')::bigint $$;

CREATE OR REPLACE FUNCTION cms.tocar_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at := now(); RETURN NEW; END $$;

-- Estado editorial común: cada fila editable tiene una versión publicada y un borrador.
-- 'borrador' = cambios guardados sin publicar; 'publicado' = lo que ve el sitio.
CREATE TYPE cms.estado_editorial AS ENUM ('borrador', 'publicado');

-- ---------------------------------------------------------------------
-- Medios (biblioteca de imágenes, videos y PDF)
-- ---------------------------------------------------------------------
CREATE TABLE cms.medios (
  id            bigserial PRIMARY KEY,
  tipo          varchar(10)  NOT NULL CHECK (tipo IN ('imagen', 'video', 'pdf', 'svg')),
  nombre        varchar(200) NOT NULL,
  url           text         NOT NULL,                 -- almacenamiento S3/R2
  mime          varchar(100) NOT NULL,
  bytes         integer      NOT NULL CHECK (bytes > 0),
  ancho         integer,
  alto          integer,
  alt           varchar(250),                          -- texto alternativo (SEO/accesibilidad)
  subido_por    bigint REFERENCES users(id) ON DELETE SET NULL,
  created_at    timestamptz  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Secciones de la landing (orden, visibilidad y configuración)
-- ---------------------------------------------------------------------
CREATE TABLE cms.secciones (
  id          serial PRIMARY KEY,
  clave       varchar(40)  NOT NULL UNIQUE,            -- 'barra_superior','hero','cifras','retos','modulos','videos','pasos','casos_uso','testimonios','planes','cta_final','footer','logos'
  nombre      varchar(80)  NOT NULL,
  visible     boolean      NOT NULL DEFAULT true,
  orden       integer      NOT NULL,
  config      jsonb        NOT NULL DEFAULT '{}'::jsonb, -- publicado
  borrador    jsonb,                                    -- NULL si no hay cambios sin publicar
  updated_at  timestamptz  NOT NULL DEFAULT now(),
  updated_by  bigint REFERENCES users(id) ON DELETE SET NULL
);
CREATE TRIGGER secciones_upd BEFORE UPDATE ON cms.secciones FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

CREATE TABLE cms.menu_items (
  id        serial PRIMARY KEY,
  ubicacion varchar(10)  NOT NULL CHECK (ubicacion IN ('principal', 'footer')),
  columna   varchar(60),                               -- título de columna en el footer
  label     varchar(80)  NOT NULL,
  url       varchar(300) NOT NULL,
  orden     integer      NOT NULL,
  padre_id  integer REFERENCES cms.menu_items(id) ON DELETE CASCADE,
  visible   boolean      NOT NULL DEFAULT true,
  fijo      boolean      NOT NULL DEFAULT false,        -- privacidad, términos y libro: no ocultables
  target    varchar(10)  NOT NULL DEFAULT '_self',
  CHECK (NOT (fijo AND NOT visible))
);

-- ---------------------------------------------------------------------
-- Módulos que se muestran en la landing y en /funcionalidades
-- ---------------------------------------------------------------------
CREATE TABLE cms.modulos_web (
  id            serial PRIMARY KEY,
  clave         varchar(30)  NOT NULL UNIQUE,          -- slug: /funcionalidades#modulo-<clave>
  codigo_saas   varchar(10),                           -- código del módulo en el SaaS (ADM, PAG, CAL…)
  nombre        varchar(80)  NOT NULL,
  icono         varchar(60)  NOT NULL,                 -- Material Symbols
  descripcion   varchar(180) NOT NULL,
  etiqueta      varchar(60),                           -- texto inferior de la tarjeta (solo grid principal)
  imagen_id     bigint REFERENCES cms.medios(id) ON DELETE SET NULL,
  en_grid       boolean      NOT NULL DEFAULT false,   -- máx. 6 (ver trigger)
  visible       boolean      NOT NULL DEFAULT true,
  orden         integer      NOT NULL,
  estado        cms.estado_editorial NOT NULL DEFAULT 'publicado',
  updated_at    timestamptz  NOT NULL DEFAULT now(),
  updated_by    bigint REFERENCES users(id) ON DELETE SET NULL
);
CREATE TRIGGER modulos_web_upd BEFORE UPDATE ON cms.modulos_web FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

CREATE OR REPLACE FUNCTION cms.validar_grid_modulos() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF (SELECT count(*) FROM cms.modulos_web WHERE en_grid AND visible) > 6 THEN
    RAISE EXCEPTION 'El grid principal admite como máximo 6 módulos visibles' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER modulos_web_max_grid AFTER INSERT OR UPDATE ON cms.modulos_web
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION cms.validar_grid_modulos();

-- ---------------------------------------------------------------------
-- Planes y precios del sitio (espejo editable, independiente del módulo PAG)
-- ---------------------------------------------------------------------
CREATE TABLE cms.planes_sitio (
  id               serial PRIMARY KEY,
  clave            varchar(20)  NOT NULL UNIQUE CHECK (clave IN ('inicial', 'crecimiento', 'integral', 'institucional')),
  nombre           varchar(60)  NOT NULL,
  rango_texto      varchar(80)  NOT NULL,              -- 'Hasta 150 alumnos'
  alumnos_max      integer,                            -- NULL = sin tope (institucional)
  precio_mensual   numeric(10,2),                      -- NULL = 'Personalizado / A medida'
  descripcion      varchar(160),
  caracteristicas  jsonb        NOT NULL DEFAULT '[]'::jsonb,  -- [{"texto":"…","incluida":true}]
  recomendado      boolean      NOT NULL DEFAULT false,
  visible          boolean      NOT NULL DEFAULT true,
  cta_texto        varchar(40)  NOT NULL,
  cta_url          varchar(300) NOT NULL DEFAULT '#demo',
  orden            integer      NOT NULL,
  estado           cms.estado_editorial NOT NULL DEFAULT 'publicado',
  updated_at       timestamptz  NOT NULL DEFAULT now(),
  updated_by       bigint REFERENCES users(id) ON DELETE SET NULL,
  CHECK (precio_mensual IS NULL OR precio_mensual > 0)
);
CREATE UNIQUE INDEX planes_sitio_un_recomendado ON cms.planes_sitio ((true)) WHERE recomendado;
CREATE TRIGGER planes_sitio_upd BEFORE UPDATE ON cms.planes_sitio FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

-- Tramos y complementos que no tienen tarjeta propia
CREATE TABLE cms.precios_complementos (
  id         serial PRIMARY KEY,
  clave      varchar(30)  NOT NULL UNIQUE,             -- 'tramo_501_800','tramo_800_mas','modulo_extra','pack_4_modulos','pack_tie_por_lab'
  etiqueta   varchar(120) NOT NULL,
  precio     numeric(10,2),                            -- NULL = 'A medida'
  nota       varchar(120),
  visible    boolean      NOT NULL DEFAULT true,
  orden      integer      NOT NULL,
  updated_at timestamptz  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Testimonios y logos (Casos de Éxito) — Ley N° 29733
-- ---------------------------------------------------------------------
CREATE TABLE cms.testimonios (
  id                        serial PRIMARY KEY,
  nombre                    varchar(120),              -- opcional: solo con autorización
  cargo                     varchar(80)  NOT NULL,
  institucion               varchar(120) NOT NULL,     -- puede ser genérica: 'Colegio particular · Arequipa'
  avatar_id                 bigint REFERENCES cms.medios(id) ON DELETE SET NULL,
  texto                     varchar(320) NOT NULL CHECK (length(texto) >= 20),
  calificacion              smallint     NOT NULL CHECK (calificacion BETWEEN 1 AND 5),
  es_ejemplo                boolean      NOT NULL DEFAULT false, -- se muestra con la leyenda 'Testimonio de ejemplo'
  consentimiento_obtenido   boolean      NOT NULL DEFAULT false,
  consentimiento_fecha      date,
  consentimiento_doc_id     bigint REFERENCES cms.medios(id) ON DELETE RESTRICT,
  visible                   boolean      NOT NULL DEFAULT false,
  orden                     integer      NOT NULL DEFAULT 0,
  estado                    cms.estado_editorial NOT NULL DEFAULT 'borrador',
  updated_at                timestamptz  NOT NULL DEFAULT now(),
  updated_by                bigint REFERENCES users(id) ON DELETE SET NULL,
  -- Un testimonio real solo puede publicarse con consentimiento y documento firmado
  CONSTRAINT testimonio_requiere_consentimiento CHECK (
    es_ejemplo OR NOT visible OR (consentimiento_obtenido AND consentimiento_fecha IS NOT NULL AND consentimiento_doc_id IS NOT NULL)
  )
);
CREATE TRIGGER testimonios_upd BEFORE UPDATE ON cms.testimonios FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

CREATE TABLE cms.clientes (
  id                    serial PRIMARY KEY,
  nombre                varchar(120) NOT NULL,
  logo_id               bigint NOT NULL REFERENCES cms.medios(id) ON DELETE RESTRICT,
  sitio_web             varchar(300),
  autorizacion_uso_logo boolean      NOT NULL DEFAULT false,
  visible               boolean      NOT NULL DEFAULT false,
  orden                 integer      NOT NULL DEFAULT 0,
  CHECK (NOT visible OR autorizacion_uso_logo)
);

-- ---------------------------------------------------------------------
-- Videos, contadores, FAQ
-- ---------------------------------------------------------------------
CREATE TABLE cms.videos (
  id             serial PRIMARY KEY,
  titulo         varchar(100) NOT NULL,
  descripcion    varchar(200),
  proveedor      varchar(10)  NOT NULL CHECK (proveedor IN ('youtube', 'vimeo', 's3')),
  embed_url      text         NOT NULL,
  duracion_seg   integer,
  thumbnail_id   bigint REFERENCES cms.medios(id) ON DELETE SET NULL,
  en_landing     boolean      NOT NULL DEFAULT true,   -- máx. 3 en la landing
  visible        boolean      NOT NULL DEFAULT true,
  orden          integer      NOT NULL
);

CREATE TABLE cms.contadores (
  id       serial PRIMARY KEY,
  label    varchar(120) NOT NULL,
  valor    numeric      NOT NULL,
  prefijo  varchar(10),
  sufijo   varchar(10),                                -- '/7', ' apps', '%', '+'
  visible  boolean      NOT NULL DEFAULT true,
  orden    integer      NOT NULL
);

CREATE TABLE cms.faq (
  id         serial PRIMARY KEY,
  pregunta   varchar(200) NOT NULL,
  respuesta  text         NOT NULL,
  categoria  varchar(60),
  orden      integer      NOT NULL,
  visible    boolean      NOT NULL DEFAULT true
);

-- ---------------------------------------------------------------------
-- Leads, demos y envío al CRM
-- ---------------------------------------------------------------------
CREATE TABLE cms.leads (
  id                      bigserial PRIMARY KEY,
  source                  varchar(15)  NOT NULL CHECK (source IN ('landing', 'demo', 'popup', 'chatbot', 'blog', 'newsletter')),
  utm_source              varchar(100),
  utm_medium              varchar(100),
  utm_campaign            varchar(100),
  utm_content             varchar(100),
  utm_term                varchar(100),
  page_url                text,
  nombre                  varchar(120),
  cargo                   varchar(80),
  email                   varchar(160),
  telefono                varchar(20),
  colegio                 varchar(160),
  ciudad                  varchar(80),
  alumnos                 integer CHECK (alumnos IS NULL OR alumnos >= 0),
  plan_interes            varchar(20) CHECK (plan_interes IS NULL OR plan_interes IN ('inicial', 'crecimiento', 'integral', 'institucional')),
  mensaje                 text,
  demo_fecha              date,
  demo_hora               time,
  consentimiento_version  varchar(30)  NOT NULL,
  estado                  varchar(15)  NOT NULL DEFAULT 'nuevo' CHECK (estado IN ('nuevo', 'contactado', 'en_proceso', 'ganado', 'perdido')),
  asignado_a              bigint REFERENCES users(id) ON DELETE SET NULL,
  crm_id                  uuid,
  created_at              timestamptz  NOT NULL DEFAULT now(),
  CHECK (email IS NOT NULL OR telefono IS NOT NULL),
  CHECK (demo_fecha IS NULL OR extract(isodow FROM demo_fecha) BETWEEN 1 AND 5)
);
CREATE INDEX leads_estado_fecha ON cms.leads (estado, created_at DESC);
CREATE INDEX leads_source ON cms.leads (source);

CREATE TABLE cms.leads_pendientes (
  id               bigserial PRIMARY KEY,
  lead_id          bigint NOT NULL REFERENCES cms.leads(id) ON DELETE CASCADE,
  payload          jsonb  NOT NULL,
  intentos         integer NOT NULL DEFAULT 0 CHECK (intentos BETWEEN 0 AND 5),
  ultimo_error     text,
  proximo_intento  timestamptz NOT NULL DEFAULT now(),
  created_at       timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE cms.popup_config (
  id             smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1), -- una sola fila
  activo         boolean      NOT NULL DEFAULT true,
  headline       varchar(100) NOT NULL,
  subheadline    varchar(200),
  imagen_id      bigint REFERENCES cms.medios(id) ON DELETE SET NULL,
  cta_texto      varchar(40)  NOT NULL,
  capture_field  varchar(10)  NOT NULL DEFAULT 'ambos' CHECK (capture_field IN ('email', 'telefono', 'ambos')),
  delay_seg      integer      NOT NULL DEFAULT 30 CHECK (delay_seg >= 0),
  inactividad_seg integer     NOT NULL DEFAULT 45 CHECK (inactividad_seg >= 0),
  frecuencia     varchar(10)  NOT NULL DEFAULT 'sesion' CHECK (frecuencia IN ('sesion', 'dia', 'siempre'))
);

-- ---------------------------------------------------------------------
-- Libro de Reclamaciones — Ley N° 29571 y D.S. 011-2011-PCM
-- ---------------------------------------------------------------------
CREATE SEQUENCE cms.reclamaciones_correlativo;
CREATE TABLE cms.reclamaciones (
  id                    bigserial PRIMARY KEY,
  numero_expediente     varchar(20)  NOT NULL UNIQUE,   -- REC-YYYY-NNNN (lo genera la API con la secuencia)
  tipo                  varchar(10)  NOT NULL CHECK (tipo IN ('queja', 'reclamo')),
  nombre_reclamante     varchar(160) NOT NULL,
  tipo_doc              varchar(5)   NOT NULL CHECK (tipo_doc IN ('DNI', 'RUC', 'CE', 'PAS')),
  numero_doc            varchar(15)  NOT NULL,
  email                 varchar(160) NOT NULL,
  telefono              varchar(20),
  direccion             varchar(250),
  producto_descripcion  varchar(250) NOT NULL,
  monto_reclamado       numeric(10,2),
  descripcion           text         NOT NULL CHECK (length(descripcion) >= 50),
  pedido                text         NOT NULL,
  adjuntos              jsonb        NOT NULL DEFAULT '[]'::jsonb,   -- [medios.id] máx. 5 MB c/u
  estado                varchar(15)  NOT NULL DEFAULT 'recibido' CHECK (estado IN ('recibido', 'en_revision', 'respondido', 'cerrado')),
  notas_internas        text,
  respuesta_cliente     text,
  respondido_en         timestamptz,
  vence_en              date         NOT NULL,          -- 15 días hábiles desde created_at (lo calcula la API)
  created_at            timestamptz  NOT NULL DEFAULT now(),
  updated_at            timestamptz  NOT NULL DEFAULT now(),
  CHECK (estado <> 'respondido' OR (respuesta_cliente IS NOT NULL AND respondido_en IS NOT NULL))
);
CREATE TRIGGER reclamaciones_upd BEFORE UPDATE ON cms.reclamaciones FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

-- ---------------------------------------------------------------------
-- SEO, configuración, tracking, políticas y consentimientos
-- ---------------------------------------------------------------------
CREATE TABLE cms.seo_paginas (
  id                serial PRIMARY KEY,
  slug              varchar(120) NOT NULL UNIQUE,
  meta_titulo       varchar(70)  NOT NULL,
  meta_descripcion  varchar(160) NOT NULL,
  canonical_url     varchar(300),
  robots            varchar(20)  NOT NULL DEFAULT 'index,follow' CHECK (robots IN ('index,follow', 'noindex,follow', 'noindex,nofollow')),
  keywords          text[]       NOT NULL DEFAULT '{}',
  og_titulo         varchar(90),
  og_descripcion    varchar(200),
  og_imagen_id      bigint REFERENCES cms.medios(id) ON DELETE SET NULL,
  schema_json       jsonb,
  estado            cms.estado_editorial NOT NULL DEFAULT 'publicado',
  updated_at        timestamptz  NOT NULL DEFAULT now()
);
CREATE TRIGGER seo_paginas_upd BEFORE UPDATE ON cms.seo_paginas FOR EACH ROW EXECUTE FUNCTION cms.tocar_updated_at();

CREATE TABLE cms.config (
  clave       varchar(60) PRIMARY KEY,                  -- 'razon_social','ruc','direccion','telefono_central','email_contacto','whatsapp','crm_endpoint','blog_wp_url','descuento_anual_pct', …
  valor       jsonb       NOT NULL,
  updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE cms.tracking (
  id      serial PRIMARY KEY,
  nombre  varchar(40) NOT NULL UNIQUE,                  -- 'gtm','ga4','meta_pixel','linkedin','clarity','search_console'
  valor   text,
  activo  boolean     NOT NULL DEFAULT false
);

CREATE TABLE cms.politicas (
  id             serial PRIMARY KEY,
  tipo           varchar(15) NOT NULL CHECK (tipo IN ('privacidad', 'terminos', 'seguridad', 'cookies')),
  version        varchar(15) NOT NULL,
  contenido      text        NOT NULL,                  -- HTML sanitizado
  vigente_desde  date        NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tipo, version)
);

CREATE TABLE cms.consentimientos (
  id                bigserial PRIMARY KEY,
  tipo              varchar(20) NOT NULL CHECK (tipo IN ('formulario', 'cookies')),
  version_politica  varchar(30) NOT NULL,
  categorias        text[],                             -- cookies: esenciales, analitica, marketing, personalizacion
  lead_id           bigint REFERENCES cms.leads(id) ON DELETE SET NULL,
  ip                inet,
  user_agent        text,
  created_at        timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Blog (solo caché de lectura de WordPress) y chatbot
-- ---------------------------------------------------------------------
CREATE TABLE cms.categorias_blog (id serial PRIMARY KEY, wp_cat_id integer NOT NULL UNIQUE, nombre varchar(80) NOT NULL, slug varchar(80) NOT NULL, descripcion text);
CREATE TABLE cms.blog_wp_cache (
  id            serial PRIMARY KEY,
  wp_post_id    integer      NOT NULL UNIQUE,
  slug          varchar(200) NOT NULL UNIQUE,
  titulo        varchar(250) NOT NULL,
  resumen       text,
  imagen_url    text,
  categoria_id  integer REFERENCES cms.categorias_blog(id) ON DELETE SET NULL,
  autor_wp      varchar(120),
  publicado_en  timestamptz,
  wp_url        text,
  synced_at     timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE cms.chatbot_flujos (id serial PRIMARY KEY, nombre varchar(80) NOT NULL, activo boolean NOT NULL DEFAULT false);
CREATE TABLE cms.chatbot_nodos (
  id           serial PRIMARY KEY,
  flujo_id     integer NOT NULL REFERENCES cms.chatbot_flujos(id) ON DELETE CASCADE,
  tipo         varchar(15) NOT NULL CHECK (tipo IN ('mensaje', 'opciones', 'captura', 'url', 'whatsapp', 'fin')),
  contenido    jsonb   NOT NULL,
  siguiente_id integer REFERENCES cms.chatbot_nodos(id) ON DELETE SET NULL,
  opciones     jsonb,
  orden        integer NOT NULL
);
CREATE TABLE cms.chatbot_sesiones (
  id               bigserial PRIMARY KEY,
  sesion_uuid      uuid    NOT NULL UNIQUE,
  nodos_visitados  jsonb   NOT NULL DEFAULT '[]'::jsonb,
  lead_capturado   boolean NOT NULL DEFAULT false,
  lead_id          bigint REFERENCES cms.leads(id) ON DELETE SET NULL,
  created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE cms.chatbot_mensajes (
  id          bigserial PRIMARY KEY,
  sesion_id   bigint NOT NULL REFERENCES cms.chatbot_sesiones(id) ON DELETE CASCADE,
  rol         varchar(10) NOT NULL CHECK (rol IN ('bot', 'visitante', 'asesor')),
  contenido   text   NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Versiones publicadas y auditoría de cambios (pantalla Auditoría)
-- ---------------------------------------------------------------------
CREATE TABLE cms.versiones (
  id            bigserial PRIMARY KEY,
  seccion       varchar(40) NOT NULL,                   -- 'hero','planes','modulos','casos','seo', …
  entidad       varchar(40) NOT NULL,                   -- tabla afectada
  entidad_id    text        NOT NULL,
  contenido     jsonb       NOT NULL,                   -- foto completa de la fila publicada
  publicado_por bigint REFERENCES users(id) ON DELETE SET NULL,
  publicado_en  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX versiones_entidad ON cms.versiones (entidad, entidad_id, publicado_en DESC);

CREATE TABLE cms.auditoria (
  id           bigserial PRIMARY KEY,
  usuario_id   bigint REFERENCES users(id) ON DELETE SET NULL,
  usuario_rol  varchar(20) NOT NULL,
  seccion      varchar(40) NOT NULL,
  accion       varchar(12) NOT NULL CHECK (accion IN ('editado', 'borrador', 'publicado', 'ocultado', 'restaurado', 'eliminado')),
  entidad      varchar(40) NOT NULL,
  entidad_id   text        NOT NULL,
  campo        varchar(80),
  detalle      varchar(250) NOT NULL,                   -- 'Plan Crecimiento · precio mensual'
  valor_antes  jsonb,
  valor_despues jsonb,
  ip           inet,
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX auditoria_fecha ON cms.auditoria (created_at DESC);
CREATE INDEX auditoria_filtros ON cms.auditoria (seccion, accion, usuario_id);

-- La auditoría es solo de inserción: nadie la edita ni la borra.
CREATE OR REPLACE FUNCTION cms.auditoria_inmutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'cms.auditoria es de solo inserción'; END $$;
CREATE TRIGGER auditoria_sin_cambios BEFORE UPDATE OR DELETE ON cms.auditoria FOR EACH ROW EXECUTE FUNCTION cms.auditoria_inmutable();

-- Se conservan las últimas 5 versiones publicadas por entidad
CREATE OR REPLACE FUNCTION cms.podar_versiones() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  DELETE FROM cms.versiones v
   WHERE v.entidad = NEW.entidad AND v.entidad_id = NEW.entidad_id
     AND v.id NOT IN (SELECT id FROM cms.versiones
                       WHERE entidad = NEW.entidad AND entidad_id = NEW.entidad_id
                       ORDER BY publicado_en DESC, id DESC LIMIT 5);
  RETURN NULL;
END $$;
CREATE TRIGGER versiones_podar AFTER INSERT ON cms.versiones FOR EACH ROW EXECUTE FUNCTION cms.podar_versiones();

-- ---------------------------------------------------------------------
-- Seguridad por rol (RLS)
--   publico     : lee contenido publicado y visible; inserta leads, consentimientos y reclamaciones
--   superadmin  : todo (es el superadmin del SaaS; los colegios no tienen CMS)
-- ---------------------------------------------------------------------
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['secciones','menu_items','modulos_web','planes_sitio','precios_complementos','testimonios','clientes',
                           'videos','contadores','faq','seo_paginas','politicas','blog_wp_cache','categorias_blog','medios']
  LOOP
    EXECUTE format('ALTER TABLE cms.%I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY %I ON cms.%I FOR SELECT USING (true)', t || '_lectura', t);
    EXECUTE format('CREATE POLICY %I ON cms.%I FOR ALL USING (cms.rol_actual() = ''superadmin'') WITH CHECK (cms.rol_actual() = ''superadmin'')', t || '_edicion', t);
  END LOOP;

  FOREACH t IN ARRAY ARRAY['config','tracking','popup_config','chatbot_flujos','chatbot_nodos']
  LOOP
    EXECUTE format('ALTER TABLE cms.%I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('CREATE POLICY %I ON cms.%I FOR SELECT USING (true)', t || '_lectura', t);
    EXECUTE format('CREATE POLICY %I ON cms.%I FOR ALL USING (cms.rol_actual() = ''superadmin'') WITH CHECK (cms.rol_actual() = ''superadmin'')', t || '_edicion', t);
  END LOOP;
END $$;


-- Leads: el público solo inserta; el equipo lee y gestiona
ALTER TABLE cms.leads ENABLE ROW LEVEL SECURITY;
CREATE POLICY leads_insertar ON cms.leads FOR INSERT WITH CHECK (true);
CREATE POLICY leads_equipo ON cms.leads FOR SELECT USING (cms.rol_actual() = 'superadmin');
CREATE POLICY leads_gestion ON cms.leads FOR UPDATE USING (cms.rol_actual() = 'superadmin');

ALTER TABLE cms.leads_pendientes ENABLE ROW LEVEL SECURITY;
CREATE POLICY leads_pendientes_soporte ON cms.leads_pendientes FOR ALL USING (cms.rol_actual() = 'superadmin');
CREATE POLICY leads_pendientes_insertar ON cms.leads_pendientes FOR INSERT WITH CHECK (true);

ALTER TABLE cms.consentimientos ENABLE ROW LEVEL SECURITY;
CREATE POLICY consentimientos_insertar ON cms.consentimientos FOR INSERT WITH CHECK (true);
CREATE POLICY consentimientos_equipo ON cms.consentimientos FOR SELECT USING (cms.rol_actual() = 'superadmin');

-- Reclamaciones: el público inserta; el seguimiento público va por la API (número de expediente + documento), nunca por SELECT directo
ALTER TABLE cms.reclamaciones ENABLE ROW LEVEL SECURITY;
CREATE POLICY reclamaciones_insertar ON cms.reclamaciones FOR INSERT WITH CHECK (true);
CREATE POLICY reclamaciones_leer ON cms.reclamaciones FOR SELECT USING (cms.rol_actual() = 'superadmin');
CREATE POLICY reclamaciones_gestion ON cms.reclamaciones FOR UPDATE USING (cms.rol_actual() = 'superadmin');

ALTER TABLE cms.chatbot_sesiones ENABLE ROW LEVEL SECURITY;
CREATE POLICY chatbot_sesiones_insertar ON cms.chatbot_sesiones FOR INSERT WITH CHECK (true);
CREATE POLICY chatbot_sesiones_equipo ON cms.chatbot_sesiones FOR SELECT USING (cms.rol_actual() = 'superadmin');
ALTER TABLE cms.chatbot_mensajes ENABLE ROW LEVEL SECURITY;
CREATE POLICY chatbot_mensajes_insertar ON cms.chatbot_mensajes FOR INSERT WITH CHECK (true);
CREATE POLICY chatbot_mensajes_equipo ON cms.chatbot_mensajes FOR SELECT USING (cms.rol_actual() = 'superadmin');

ALTER TABLE cms.versiones ENABLE ROW LEVEL SECURITY;
CREATE POLICY versiones_equipo ON cms.versiones FOR ALL USING (cms.rol_actual() = 'superadmin');
ALTER TABLE cms.auditoria ENABLE ROW LEVEL SECURITY;
CREATE POLICY auditoria_insertar ON cms.auditoria FOR INSERT WITH CHECK (cms.rol_actual() = 'superadmin');
CREATE POLICY auditoria_leer ON cms.auditoria FOR SELECT USING (cms.rol_actual() = 'superadmin');

