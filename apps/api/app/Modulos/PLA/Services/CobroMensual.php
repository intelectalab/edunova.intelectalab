<?php

namespace App\Modulos\PLA\Services;

use App\Modulos\PAG\Facturacion\FacturacionSimulada;
use App\Modulos\PAG\Facturacion\ProveedorFacturacion;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-27 · PLA-H04 Cobro mensual de Edunova a cada colegio.
 *
 * - Cuenta los alumnos matriculados al día 1 del mes (en el año escolar vigente ese día).
 * - Respeta los meses gratis y el precio congelado de la suscripción; si no, usa la tarifa del
 *   plan que corresponde a esa cantidad de alumnos.
 * - Agrega una línea por los comprobantes electrónicos que el colegio emitió en el mes, menos los
 *   incluidos en su plan, al precio por comprobante del plan (regla 11 de PAG).
 * - Emite el comprobante con el proveedor de facturación de Edunova (simulado hasta conectarlo).
 * Los precios viven en la tabla `planes` de cada instalación: nunca en el código.
 */
class CobroMensual
{
    public const CONEXION = 'plataforma';

    public function __construct(private Auditoria $auditoria) {}

    private function db()
    {
        return DB::connection(self::CONEXION);
    }

    /** @return list<array<string, mixed>> */
    public function calcular(string $periodo): array
    {
        $dia1 = Carbon::parse("$periodo-01")->toDateString();
        $finMes = Carbon::parse($dia1)->endOfMonth()->toDateString();
        $planes = $this->db()->table('planes')->where('activo', true)->orderBy('alumnos_min')->get();
        $filas = [];
        $suscripciones = $this->db()->table('suscripciones as s')->join('instituciones as i', 'i.id', '=', 's.institucion_id')->join('planes as p', 'p.id', '=', 's.plan_id')
            ->whereIn('s.estado', ['activa', 'vencida'])->whereIn('i.estado', ['activo', 'suspendido'])->where('s.fecha_inicio', '<=', $finMes)
            ->where(fn ($q) => $q->whereNull('s.fecha_fin')->orWhere('s.fecha_fin', '>=', $dia1))
            ->orderBy('i.nombre')->get(['s.*', 'i.nombre as colegio', 'i.ruc', 'i.razon_social', 'p.codigo as plan_codigo']);
        foreach ($suscripciones as $s) {
            $alumnos = $this->alumnosAlDia1((int) $s->institucion_id, $dia1);
            $tarifa = $planes->first(fn ($p) => $alumnos >= $p->alumnos_min && ($p->alumnos_max === null || $alumnos <= $p->alumnos_max))
                ?? $planes->firstWhere('id', $s->plan_id);
            $gratisHasta = $s->meses_gratis > 0 ? Carbon::parse($s->fecha_inicio)->startOfMonth()->addMonthsNoOverflow((int) $s->meses_gratis)->toDateString() : null;
            $congelado = $s->precio_congelado_hasta && $s->precio_congelado_hasta >= $dia1 && $s->precio_pactado !== null;
            $precio = $congelado ? (float) $s->precio_pactado : ($s->precio_pactado !== null ? (float) $s->precio_pactado : ($tarifa?->precio_mensual !== null ? (float) $tarifa->precio_mensual : null));
            $gratis = $gratisHasta && $dia1 < $gratisHasta;

            $emitidos = (int) $this->db()->table('comprobantes')->where('institucion_id', $s->institucion_id)->whereBetween('fecha_emision', [$dia1, $finMes])
                ->where('estado_sunat', '<>', 'anulado')->count();
            $incluidos = (int) ($tarifa->comprobantes_incluidos ?? 0);
            $cobrados = max(0, $emitidos - $incluidos);
            $precioComprobante = $tarifa?->precio_comprobante !== null ? (float) $tarifa->precio_comprobante : 0.0;

            $lineas = [];
            $montoSuscripcion = $gratis ? 0.0 : (float) ($precio ?? 0);
            $lineas[] = ['descripcion' => $gratis ? "Suscripción {$periodo} · mes gratis" : "Suscripción {$periodo} · plan ".($tarifa->nombre ?? $s->plan_codigo).($congelado ? ' (precio congelado)' : '')." · {$alumnos} alumnos",
                'cantidad' => 1, 'monto' => round($montoSuscripcion, 2)];
            if ($cobrados > 0) {
                $lineas[] = ['descripcion' => "Comprobantes electrónicos emitidos en {$periodo}: {$emitidos} (incluidos {$incluidos})", 'cantidad' => $cobrados,
                    'monto' => round($cobrados * $precioComprobante, 2)];
            }
            $existente = $this->db()->table('facturas_saas')->where('suscripcion_id', $s->id)->where('periodo', $periodo)->first(['id', 'estado', 'comprobante_serie_numero']);
            $filas[] = [
                'institucion_id' => $s->institucion_id, 'suscripcion_id' => $s->id, 'colegio' => $s->colegio, 'alumnos' => $alumnos, 'plan' => $tarifa->nombre ?? $s->plan_codigo,
                'gratis' => $gratis, 'congelado' => $congelado, 'sin_precio' => ! $gratis && $precio === null,
                'comprobantes_emitidos' => $emitidos, 'comprobantes_cobrados' => $cobrados, 'monto_suscripcion' => round($montoSuscripcion, 2),
                'monto_comprobantes' => round($cobrados * $precioComprobante, 2), 'total' => round(array_sum(array_column($lineas, 'monto')), 2), 'lineas' => $lineas,
                'factura' => $existente ? (array) $existente : null, 'ruc' => $s->ruc, 'razon_social' => $s->razon_social ?: $s->colegio,
            ];
        }

        return $filas;
    }

    /** Matriculados al día 1 en el año escolar vigente ese día (o el último que terminó, en vacaciones). */
    private function alumnosAlDia1(int $institucionId, string $dia1): int
    {
        $anio = $this->db()->table('anios_escolares')->where('institucion_id', $institucionId)->where('fecha_inicio', '<=', $dia1)
            ->orderByRaw('fecha_fin >= ? desc', [$dia1])->orderByDesc('fecha_inicio')->value('id');
        if (! $anio) {
            return 0;
        }

        return $this->db()->table('matriculas')->where('institucion_id', $institucionId)->where('anio_escolar_id', $anio)->where('estado', 'matriculado')
            ->where('fecha_matricula', '<=', $dia1)->count();
    }

    /** Emite los cobros del mes que aún no tienen factura. */
    public function emitir(string $periodo, int $usuarioId): array
    {
        $emitidas = [];
        $omitidas = [];
        foreach ($this->calcular($periodo) as $f) {
            if ($f['factura']) {
                continue;
            }
            if ($f['sin_precio']) {
                $omitidas[] = "{$f['colegio']}: el plan no tiene precio configurado.";

                continue;
            }
            if ($f['total'] <= 0) {
                $omitidas[] = "{$f['colegio']}: sin monto (mes gratis).";

                continue;
            }
            $emitidas[] = $this->emitirUna($f, $periodo, $usuarioId);
        }

        return ['emitidas' => $emitidas, 'omitidas' => $omitidas];
    }

    private function emitirUna(array $f, string $periodo, int $usuarioId): array
    {
        $tipo = $f['ruc'] ? '01' : '03';
        $serie = $tipo === '01' ? (string) config('edunova.facturacion_saas.serie_factura') : (string) config('edunova.facturacion_saas.serie_boleta');

        return $this->db()->transaction(function () use ($f, $periodo, $tipo, $serie) {
            $this->db()->select('select pg_advisory_xact_lock(?, ?)', [0, crc32('saas'.$serie) & 0x7FFFFFFF]);
            $numero = 1 + (int) $this->db()->table('facturas_saas')->where('comprobante_serie_numero', 'like', "$serie-%")
                ->max(DB::raw("cast(split_part(comprobante_serie_numero, '-', 2) as int)"));
            $documento = [
                'tipo' => $tipo, 'nombre_tipo' => $tipo === '01' ? 'Factura electrónica' : 'Boleta de venta electrónica', 'serie' => $serie, 'numero' => $numero,
                'fecha_emision' => now()->toDateString(),
                'emisor' => ['ruc' => (string) config('edunova.facturacion_saas.ruc'), 'razon_social' => (string) config('edunova.facturacion_saas.razon_social'), 'nombre_comercial' => 'Edunova', 'direccion' => null],
                'cliente' => ['tipo_doc' => $f['ruc'] ? '6' : '0', 'numero_doc' => $f['ruc'] ?: '-', 'nombre' => $f['razon_social']],
                'items' => array_map(fn ($l) => ['descripcion' => $l['descripcion'], 'total' => $l['monto'], 'valor_unitario' => round($l['monto'] / 1.18, 2), 'afectacion_igv' => '10'], $f['lineas']),
                'gravado' => round($f['total'] / 1.18, 2), 'exonerado' => 0.0, 'igv' => round($f['total'] - $f['total'] / 1.18, 2), 'total' => $f['total'],
            ];
            $r = self::adaptador()->emitir($documento, [], (string) config('edunova.facturacion_saas.ambiente'));
            if ($r['estado'] === 'rechazado') {
                throw ValidationException::withMessages(['periodo' => ["{$f['colegio']}: el proveedor rechazó el comprobante ({$r['mensaje']})."]]);
            }
            $id = (int) $this->db()->table('facturas_saas')->insertGetId([
                'institucion_id' => $f['institucion_id'], 'suscripcion_id' => $f['suscripcion_id'], 'periodo' => $periodo, 'alumnos_activos' => $f['alumnos'],
                'monto' => $f['total'], 'monto_suscripcion' => $f['monto_suscripcion'], 'comprobantes_emitidos' => $f['comprobantes_emitidos'],
                'comprobantes_cobrados' => $f['comprobantes_cobrados'], 'monto_comprobantes' => $f['monto_comprobantes'], 'detalle' => json_encode($f['lineas']),
                'comprobante_serie_numero' => "$serie-$numero", 'comprobante_tipo' => $tipo, 'respuesta' => json_encode(['mensaje' => $r['mensaje']] + $r['respuesta']), 'emitida_at' => now(),
            ]);
            $this->auditoria->registrar('cobro_mensual_emitido', 'facturas_saas', $id, null, ['periodo' => $periodo, 'monto' => $f['total'], 'comprobante' => "$serie-$numero"], (int) $f['institucion_id'], self::CONEXION);

            return ['id' => $id, 'colegio' => $f['colegio'], 'comprobante' => "$serie-$numero", 'total' => $f['total']];
        });
    }

    public static function adaptador(): ProveedorFacturacion
    {
        $clase = config('services.facturacion.simular') ? FacturacionSimulada::class : config('edunova.facturacion_saas.adaptador');
        if (! $clase) {
            throw ValidationException::withMessages(['periodo' => ['Falta configurar el proveedor de facturación de Edunova.']]);
        }

        return app($clase);
    }

    public function listar(?string $periodo): array
    {
        return $this->db()->table('facturas_saas as f')->join('instituciones as i', 'i.id', '=', 'f.institucion_id')
            ->when($periodo, fn ($q) => $q->where('f.periodo', $periodo))->orderByDesc('f.periodo')->orderBy('i.nombre')->limit(500)
            ->get(['f.id', 'f.periodo', 'i.nombre as colegio', 'f.alumnos_activos', 'f.monto', 'f.monto_suscripcion', 'f.monto_comprobantes', 'f.comprobantes_cobrados',
                'f.comprobante_serie_numero', 'f.estado', 'f.emitida_at', 'f.pagada_at', 'f.detalle'])
            ->map(fn ($f) => array_merge((array) $f, ['monto' => (float) $f->monto, 'detalle' => json_decode($f->detalle, true)]))->all();
    }

    public function marcar(int $id, string $estado): void
    {
        abort_unless(in_array($estado, ['pagada', 'anulada', 'emitida'], true), 422);
        $f = $this->db()->table('facturas_saas')->find($id);
        abort_if(! $f, 404);
        $this->db()->table('facturas_saas')->where('id', $id)->update(['estado' => $estado, 'pagada_at' => $estado === 'pagada' ? now() : null]);
        $this->auditoria->registrar('cobro_mensual_'.$estado, 'facturas_saas', $id, ['estado' => $f->estado], ['estado' => $estado], (int) $f->institucion_id, self::CONEXION);
    }
}
