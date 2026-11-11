<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 21 · Comunicación y cobranza.
 *
 * - EDUN-60 · Comunicado anulado con motivo (un enviado no se edita).
 * - EDUN-63 · Mensajes fuera del horario de atención: se entregan al día siguiente.
 * - EDUN-64 · Recordatorio automático del evento (una sola vez).
 * - EDUN-67 · Alerta del reclamo por vencer (una sola vez) y el aviso queda registrado.
 * - EDUN-81 · Gestión de cobranza con fecha y compromiso de pago enlazado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE comunicados ADD COLUMN motivo_anulacion varchar(300);
            ALTER TABLE mensajes ADD COLUMN entregar_at timestamptz NOT NULL DEFAULT now();
            ALTER TABLE eventos_calendario ADD COLUMN recordatorio_enviado_at timestamptz;
            ALTER TABLE reclamaciones ADD COLUMN alerta_enviada_at timestamptz;
            ALTER TABLE gestiones_cobranza ADD COLUMN compromiso_pago_id bigint, ADD COLUMN detalle text;
            ALTER TABLE notificaciones ALTER COLUMN user_id DROP NOT NULL;
            ALTER TABLE notificaciones ADD COLUMN destino varchar(150);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE notificaciones DROP COLUMN IF EXISTS destino;
            ALTER TABLE gestiones_cobranza DROP COLUMN IF EXISTS compromiso_pago_id, DROP COLUMN IF EXISTS detalle;
            ALTER TABLE reclamaciones DROP COLUMN IF EXISTS alerta_enviada_at;
            ALTER TABLE eventos_calendario DROP COLUMN IF EXISTS recordatorio_enviado_at;
            ALTER TABLE mensajes DROP COLUMN IF EXISTS entregar_at;
            ALTER TABLE comunicados DROP COLUMN IF EXISTS motivo_anulacion;
            SQL);
    }
};
