<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 17 · Cobro en caja, comprobantes electrónicos y pagos en línea.
 *
 * - EDUN-75 · Un pago puede combinar varios medios (pago_medios); el pago queda «mixto».
 * - EDUN-76 · Una sola sesión abierta por caja y justificación de la diferencia del arqueo.
 * - EDUN-77 / EDUN-78 · Reintentos con espera creciente y motivo de la nota de crédito.
 * - EDUN-88 · URL de webhook propia por cuenta de pasarela (token aleatorio).
 * - EDUN-73 / EDUN-74 · La orden en línea guarda los cargos elegidos y el pago que generó.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE pagos DROP CONSTRAINT IF EXISTS pagos_medio_check;
            ALTER TABLE pagos ADD CONSTRAINT pagos_medio_check
              CHECK (medio in ('efectivo','yape','plin','tarjeta','transferencia','recaudacion','pagoefectivo','mixto'));
            ALTER TABLE pagos
              ADD COLUMN anulado_at timestamptz,
              ADD COLUMN anulado_por bigint,
              ADD COLUMN motivo_anulacion varchar(300);

            CREATE TABLE pago_medios (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              institucion_id bigint NOT NULL REFERENCES instituciones(id) ON DELETE CASCADE,
              pago_id bigint NOT NULL,
              medio varchar(15) NOT NULL CHECK (medio in ('efectivo','yape','plin','tarjeta','transferencia')),
              monto numeric(10,2) NOT NULL CHECK (monto > 0),
              referencia varchar(80),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (institucion_id, id),
              FOREIGN KEY (institucion_id, pago_id) REFERENCES pagos (institucion_id, id) ON DELETE CASCADE
            );
            CREATE INDEX ON pago_medios (pago_id);
            ALTER TABLE pago_medios ENABLE ROW LEVEL SECURITY;
            ALTER TABLE pago_medios FORCE ROW LEVEL SECURITY;
            CREATE POLICY aislamiento ON pago_medios USING (institucion_id = app_institucion()) WITH CHECK (institucion_id = app_institucion());
            CREATE POLICY plataforma ON pago_medios TO edunova_plataforma USING (true) WITH CHECK (true);
            GRANT SELECT, INSERT, UPDATE, DELETE ON pago_medios TO edunova_app, edunova_plataforma;

            ALTER TABLE caja_sesiones ADD COLUMN justificacion varchar(500);
            CREATE UNIQUE INDEX caja_sesiones_una_abierta ON caja_sesiones (caja_id) WHERE cerrada_at IS NULL;

            ALTER TABLE comprobantes
              ADD COLUMN motivo varchar(300),
              ADD COLUMN siguiente_intento_at timestamptz,
              ADD COLUMN ultimo_error text,
              ADD COLUMN correo_enviado_at timestamptz,
              ADD COLUMN email_cliente varchar(150);
            ALTER TABLE comprobantes ADD CONSTRAINT comprobantes_nc_con_referencia CHECK (tipo <> '07' OR (comprobante_ref_id IS NOT NULL AND motivo IS NOT NULL));

            ALTER TABLE pasarela_cuentas ADD COLUMN webhook_token varchar(64);
            CREATE UNIQUE INDEX pasarela_cuentas_webhook_token ON pasarela_cuentas (webhook_token) WHERE webhook_token IS NOT NULL;
            CREATE UNIQUE INDEX pasarela_cuentas_predeterminada ON pasarela_cuentas (institucion_id) WHERE predeterminada;

            ALTER TABLE pasarela_transacciones
              ADD COLUMN cargos jsonb NOT NULL DEFAULT '[]'::jsonb,
              ADD COLUMN url_pago text,
              ADD COLUMN pago_id bigint,
              ADD COLUMN procesada_at timestamptz;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE pasarela_transacciones DROP COLUMN IF EXISTS cargos, DROP COLUMN IF EXISTS url_pago, DROP COLUMN IF EXISTS pago_id, DROP COLUMN IF EXISTS procesada_at;
            DROP INDEX IF EXISTS pasarela_cuentas_predeterminada;
            DROP INDEX IF EXISTS pasarela_cuentas_webhook_token;
            ALTER TABLE pasarela_cuentas DROP COLUMN IF EXISTS webhook_token;
            ALTER TABLE comprobantes DROP CONSTRAINT IF EXISTS comprobantes_nc_con_referencia;
            ALTER TABLE comprobantes DROP COLUMN IF EXISTS motivo, DROP COLUMN IF EXISTS siguiente_intento_at, DROP COLUMN IF EXISTS ultimo_error,
              DROP COLUMN IF EXISTS correo_enviado_at, DROP COLUMN IF EXISTS email_cliente;
            DROP INDEX IF EXISTS caja_sesiones_una_abierta;
            ALTER TABLE caja_sesiones DROP COLUMN IF EXISTS justificacion;
            DROP TABLE IF EXISTS pago_medios;
            ALTER TABLE pagos DROP COLUMN IF EXISTS anulado_at, DROP COLUMN IF EXISTS anulado_por, DROP COLUMN IF EXISTS motivo_anulacion;
            ALTER TABLE pagos DROP CONSTRAINT IF EXISTS pagos_medio_check;
            ALTER TABLE pagos ADD CONSTRAINT pagos_medio_check CHECK (medio in ('efectivo','yape','plin','tarjeta','transferencia','recaudacion'));
            SQL);
    }
};
