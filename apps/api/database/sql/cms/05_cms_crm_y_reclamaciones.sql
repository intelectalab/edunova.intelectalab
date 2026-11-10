-- =====================================================================
-- Sprint 8 · Envío de leads al CRM (EDUN-275 / SIT-052), exportar y sincronizar (EDUN-282 / SIT-027),
-- popup de salida (EDUN-271 / SIT-009) y gestión de reclamaciones en el CMS (EDUN-302 / SIT-031).
-- Idempotente: lo cargan la migración 0001_01_01_000007 e infra/pruebas-cms.sh.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Leads → CRM. cms.leads_pendientes es la bandeja de salida: una fila por lead aún no enviado,
-- con sus intentos (máx. 5) y el último error. Al enviarse, el lead guarda la fecha y la
-- referencia que devuelva el CRM y la fila se borra.
-- ---------------------------------------------------------------------
ALTER TABLE cms.leads ADD COLUMN IF NOT EXISTS crm_enviado_en timestamptz;
ALTER TABLE cms.leads ADD COLUMN IF NOT EXISTS crm_ref varchar(120);

DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'leads_pendientes_lead_unico') THEN
    ALTER TABLE cms.leads_pendientes ADD CONSTRAINT leads_pendientes_lead_unico UNIQUE (lead_id);
  END IF;
END $$;
CREATE INDEX IF NOT EXISTS leads_pendientes_proximo ON cms.leads_pendientes (proximo_intento) WHERE intentos < 5;

-- ---------------------------------------------------------------------
-- Popup de salida: el lead del popup trae correo o teléfono (CHECK de cms.leads) y nunca demo.
-- ---------------------------------------------------------------------
ALTER TABLE cms.leads DROP CONSTRAINT IF EXISTS leads_popup_sin_demo;
ALTER TABLE cms.leads ADD CONSTRAINT leads_popup_sin_demo CHECK (source <> 'popup' OR demo_fecha IS NULL);

-- ---------------------------------------------------------------------
-- Reclamaciones: el orden de los estados lo hace cumplir la base, no solo la API.
--   recibido → en_revision → respondido → cerrado (se puede responder sin pasar por revisión).
-- La respuesta al cliente es constancia legal: una vez respondida no cambia.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cms.validar_estado_reclamo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.estado <> OLD.estado AND NOT (
       (OLD.estado = 'recibido'    AND NEW.estado IN ('en_revision', 'respondido'))
    OR (OLD.estado = 'en_revision' AND NEW.estado = 'respondido')
    OR (OLD.estado = 'respondido'  AND NEW.estado = 'cerrado')
  ) THEN
    RAISE EXCEPTION 'Cambio de estado no permitido: % → %', OLD.estado, NEW.estado USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.respondido_en IS NOT NULL AND (NEW.respuesta_cliente IS DISTINCT FROM OLD.respuesta_cliente OR NEW.respondido_en IS DISTINCT FROM OLD.respondido_en) THEN
    RAISE EXCEPTION 'La respuesta enviada al cliente no se puede modificar' USING ERRCODE = 'check_violation';
  END IF;
  -- Los datos que dejó el reclamante tampoco se editan desde el CMS.
  IF (NEW.numero_expediente, NEW.tipo, NEW.nombre_reclamante, NEW.tipo_doc, NEW.numero_doc, NEW.email, NEW.descripcion, NEW.pedido, NEW.vence_en, NEW.created_at)
     IS DISTINCT FROM
     (OLD.numero_expediente, OLD.tipo, OLD.nombre_reclamante, OLD.tipo_doc, OLD.numero_doc, OLD.email, OLD.descripcion, OLD.pedido, OLD.vence_en, OLD.created_at) THEN
    RAISE EXCEPTION 'Los datos del reclamante no se pueden modificar' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS reclamaciones_estado ON cms.reclamaciones;
CREATE TRIGGER reclamaciones_estado BEFORE UPDATE ON cms.reclamaciones FOR EACH ROW EXECUTE FUNCTION cms.validar_estado_reclamo();

CREATE INDEX IF NOT EXISTS reclamaciones_estado_vence ON cms.reclamaciones (estado, vence_en);
