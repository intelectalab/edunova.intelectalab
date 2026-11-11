<?php

use App\Modulos\PAG\Services\GeneradorCargos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 16 · Configuración de pagos y base de la app móvil.
 *
 * - EDUN-69 · Conceptos: tipo de comprobante validado.
 * - EDUN-70 · Cronograma: un cronograma por año y nivel; ninguna cuota vence antes de su mes.
 * - EDUN-71 · Código de recaudación único por cargo (también para los cargos que ya existen).
 * - EDUN-72 · Beneficios con flujo de aprobación (pendiente → aprobado | rechazado).
 * - EDUN-90 · Proveedores de facturación: uno por proveedor, un solo predeterminado y series válidas.
 * - EDUN-196 · Preferencias de notificaciones push por tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE conceptos_cobro
              ADD CONSTRAINT conceptos_cobro_tipo_comprobante CHECK (tipo_comprobante in ('boleta','factura','ninguno'));

            ALTER TABLE cronogramas
              ADD CONSTRAINT cronogramas_nivel CHECK (nivel is null or nivel in ('inicial','primaria','secundaria'));
            CREATE UNIQUE INDEX cronogramas_anio_nivel ON cronogramas (institucion_id, anio_escolar_id, coalesce(nivel, ''));

            ALTER TABLE cronograma_cuotas
              ADD CONSTRAINT cronograma_cuotas_mes CHECK (mes ~ '^[0-9]{4}-(0[1-9]|1[0-2])$'),
              ADD CONSTRAINT cronograma_cuotas_sin_adelanto CHECK (vencimiento >= (mes || '-01')::date);

            ALTER TABLE alumno_beneficios
              ADD COLUMN estado varchar(10) NOT NULL DEFAULT 'aprobado' CHECK (estado in ('pendiente','aprobado','rechazado')),
              ADD COLUMN solicitado_por bigint,
              ADD COLUMN aprobado_at timestamptz,
              ADD COLUMN observacion varchar(300);

            CREATE UNIQUE INDEX cargos_codigo_recaudacion ON cargos (institucion_id, codigo_recaudacion) WHERE codigo_recaudacion IS NOT NULL;

            CREATE UNIQUE INDEX proveedores_facturacion_unico ON proveedores_facturacion (institucion_id, proveedor);
            CREATE UNIQUE INDEX proveedores_facturacion_predeterminado ON proveedores_facturacion (institucion_id) WHERE predeterminado AND activo;
            ALTER TABLE proveedores_facturacion ADD CONSTRAINT proveedores_facturacion_series CHECK (
              serie_boleta ~ '^B[A-Z0-9]{3}$' AND serie_factura ~ '^F[A-Z0-9]{3}$'
              AND (serie_nota_credito IS NULL OR serie_nota_credito ~ '^[BF][A-Z0-9]{3}$'));

            CREATE TABLE preferencias_notificacion (
              id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              tipo varchar(20) NOT NULL CHECK (tipo in ('pagos','notas','asistencia','comunicados','tareas','movilidad','soporte')),
              push boolean NOT NULL DEFAULT true,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              UNIQUE (user_id, tipo)
            );
            CREATE TRIGGER trg_preferencias_notificacion_upd BEFORE UPDATE ON preferencias_notificacion FOR EACH ROW EXECUTE FUNCTION tg_updated_at();
            GRANT SELECT, INSERT, UPDATE, DELETE ON preferencias_notificacion TO edunova_app, edunova_plataforma;
            SQL);

        DB::unprepared((string) file_get_contents(database_path('sql/04_permisos_adicionales.sql')));

        // Código de recaudación de los cargos que ya existían.
        DB::table('cargos')->whereNull('codigo_recaudacion')->orderBy('id')->select('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $f) {
                    DB::table('cargos')->where('id', $f->id)->update(['codigo_recaudacion' => GeneradorCargos::codigo((int) $f->id)]);
                }
            });
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS preferencias_notificacion;
            ALTER TABLE proveedores_facturacion DROP CONSTRAINT IF EXISTS proveedores_facturacion_series;
            DROP INDEX IF EXISTS proveedores_facturacion_predeterminado;
            DROP INDEX IF EXISTS proveedores_facturacion_unico;
            DROP INDEX IF EXISTS cargos_codigo_recaudacion;
            ALTER TABLE alumno_beneficios DROP COLUMN IF EXISTS estado, DROP COLUMN IF EXISTS solicitado_por,
              DROP COLUMN IF EXISTS aprobado_at, DROP COLUMN IF EXISTS observacion;
            ALTER TABLE cronograma_cuotas DROP CONSTRAINT IF EXISTS cronograma_cuotas_sin_adelanto, DROP CONSTRAINT IF EXISTS cronograma_cuotas_mes;
            DROP INDEX IF EXISTS cronogramas_anio_nivel;
            ALTER TABLE cronogramas DROP CONSTRAINT IF EXISTS cronogramas_nivel;
            ALTER TABLE conceptos_cobro DROP CONSTRAINT IF EXISTS conceptos_cobro_tipo_comprobante;
            SQL);
    }
};
