-- =====================================================================
-- Sprint 24 · Constructor visual de flujos del chatbot (EDUN-379 / SIT-028).
-- Idempotente: lo cargan la migración 0001_01_01_000021 e infra/pruebas-cms.sh.
-- =====================================================================

-- Historial de sesiones: cómo terminó cada conversación y en qué página empezó, para filtrar.
-- La posición de cada paso en el lienzo va dentro de cms.chatbot_nodos.contenido (posicion.x/y).
ALTER TABLE cms.chatbot_sesiones ADD COLUMN IF NOT EXISTS resultado varchar(12);
ALTER TABLE cms.chatbot_sesiones DROP CONSTRAINT IF EXISTS chatbot_sesiones_resultado;
ALTER TABLE cms.chatbot_sesiones ADD CONSTRAINT chatbot_sesiones_resultado CHECK (resultado IS NULL OR resultado IN ('captura', 'whatsapp', 'url', 'fin', 'abandono'));
ALTER TABLE cms.chatbot_sesiones ADD COLUMN IF NOT EXISTS pagina varchar(300);
UPDATE cms.chatbot_sesiones SET resultado = 'captura' WHERE resultado IS NULL AND lead_capturado;
CREATE INDEX IF NOT EXISTS chatbot_sesiones_fecha ON cms.chatbot_sesiones (created_at DESC);
