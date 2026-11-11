-- =====================================================================
-- Sprint 23 · Previsualización del blog en el CMS (EDUN-367 / SIT-023) y asistente SEO con IA
-- (EDUN-411 / SIT-030). Idempotente: lo cargan la migración 0001_01_01_000020 e infra/pruebas-cms.sh.
-- =====================================================================

-- Blog: el editor revisa cada post de WordPress antes de difundirlo y puede ocultarlo del sitio
-- sin tocar WordPress (lo oculto no sale en /blog, en el sitemap ni en los relacionados).
ALTER TABLE cms.blog_wp_cache ADD COLUMN IF NOT EXISTS oculto boolean NOT NULL DEFAULT false;
ALTER TABLE cms.blog_wp_cache ADD COLUMN IF NOT EXISTS revisado_at timestamptz;
ALTER TABLE cms.blog_wp_cache ADD COLUMN IF NOT EXISTS revisado_por bigint;

-- Asistente SEO: cada sugerencia pedida a la IA queda registrada (quién, para qué página, con qué
-- proveedor y modelo, y cuántos tokens), para controlar el uso y el costo. Solo el equipo la lee.
CREATE TABLE IF NOT EXISTS cms.seo_sugerencias (
  id            bigserial PRIMARY KEY,
  slug          varchar(120) NOT NULL,
  proveedor     varchar(20)  NOT NULL CHECK (proveedor IN ('simulado', 'claude')),
  modelo        varchar(60),
  entrada       jsonb        NOT NULL,
  salida        jsonb,
  error         varchar(300),
  tokens_entrada integer,
  tokens_salida  integer,
  usuario_id    bigint,
  created_at    timestamptz  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS seo_sugerencias_slug ON cms.seo_sugerencias (slug, created_at DESC);
ALTER TABLE cms.seo_sugerencias ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS seo_sugerencias_equipo ON cms.seo_sugerencias;
CREATE POLICY seo_sugerencias_equipo ON cms.seo_sugerencias FOR ALL USING (cms.rol_actual() = 'superadmin') WITH CHECK (cms.rol_actual() = 'superadmin');

-- Proveedor del asistente (D-SIT-04): «simulado» hasta que se configure la clave de la IA en el
-- servidor. La clave nunca se guarda aquí: vive en el entorno del servidor.
INSERT INTO cms.config (clave, valor) VALUES ('asistente_seo', '{"proveedor": "simulado", "modelo": "claude-opus-5-5"}')
ON CONFLICT (clave) DO NOTHING;

DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_app') THEN
    GRANT SELECT, INSERT ON cms.seo_sugerencias TO edunova_app;
    GRANT USAGE, SELECT ON SEQUENCE cms.seo_sugerencias_id_seq TO edunova_app;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'edunova_plataforma') THEN
    GRANT SELECT, INSERT ON cms.seo_sugerencias TO edunova_plataforma;
    GRANT USAGE, SELECT ON SEQUENCE cms.seo_sugerencias_id_seq TO edunova_plataforma;
  END IF;
END $$;
