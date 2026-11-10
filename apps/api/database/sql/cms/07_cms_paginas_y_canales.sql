-- =====================================================================
-- Sprint 11 · Páginas públicas y canales: /funcionalidades (EDUN-347 / SIT-003), /demo largo
-- (EDUN-359 / SIT-064), blog desde WordPress (EDUN-363 / SIT-005), preguntas frecuentes
-- (EDUN-371 / SIT-007), chatbot (EDUN-375 / SIT-008) y tracking e integraciones (EDUN-403 /
-- SIT-033). Idempotente: lo cargan la migración 0001_01_01_000009 e infra/pruebas-cms.sh.
-- =====================================================================

-- /funcionalidades: descripción ampliada y beneficios de cada módulo; videos por módulo.
ALTER TABLE cms.modulos_web ADD COLUMN IF NOT EXISTS descripcion_larga text CHECK (descripcion_larga IS NULL OR length(descripcion_larga) <= 2000);
ALTER TABLE cms.modulos_web ADD COLUMN IF NOT EXISTS beneficios jsonb NOT NULL DEFAULT '[]'::jsonb;
ALTER TABLE cms.videos ADD COLUMN IF NOT EXISTS modulo_clave varchar(30) REFERENCES cms.modulos_web(clave) ON UPDATE CASCADE ON DELETE SET NULL;

-- /demo: sistema actual y urgencia para que el asesor llegue preparado.
ALTER TABLE cms.leads ADD COLUMN IF NOT EXISTS sistema_actual varchar(80);
ALTER TABLE cms.leads ADD COLUMN IF NOT EXISTS urgencia varchar(20);
ALTER TABLE cms.leads DROP CONSTRAINT IF EXISTS leads_urgencia;
ALTER TABLE cms.leads ADD CONSTRAINT leads_urgencia CHECK (urgencia IS NULL OR urgencia IN ('inmediata', 'este_mes', 'proximo_trimestre', 'explorando'));

-- Blog: el artículo completo (HTML limpiado por la API) para no depender de WordPress al leer.
ALTER TABLE cms.blog_wp_cache ADD COLUMN IF NOT EXISTS contenido text;
ALTER TABLE cms.blog_wp_cache ADD COLUMN IF NOT EXISTS actualizado_wp timestamptz;

-- Pantallas internas del panel con borrador (mismo flujo Guardar borrador / Publicar).
INSERT INTO cms.secciones (clave, nombre, orden, visible, config) VALUES
 ('_faq', 'Preguntas frecuentes', 0, false, '{}'),
 ('_chatbot', 'Chatbot', 0, false, '{}')
ON CONFLICT (clave) DO NOTHING;

-- Un solo flujo de chatbot activo a la vez.
CREATE UNIQUE INDEX IF NOT EXISTS chatbot_un_flujo_activo ON cms.chatbot_flujos (activo) WHERE activo;
