<?php

namespace App\Modulos\PAG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\PAG\Correo\ComprobanteEmitido;
use App\Modulos\PAG\Facturacion\FacturacionSimulada;
use App\Modulos\PAG\Facturacion\ProveedorFacturacion;
use App\Modulos\PAG\Jobs\EmitirComprobante;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-77 · PAG-H09 Comprobante electrónico de cada pago y EDUN-78 · PAG-H10 nota de crédito.
 *
 * - Usa el proveedor predeterminado del colegio; numera por serie sin saltos (bloqueo por serie).
 * - Guarda el XML, la representación impresa en PDF y la respuesta del proveedor.
 * - El envío va en cola y se reintenta con espera creciente; si se agotan los intentos queda
 *   «pendiente» con el error a la vista para reenviarlo.
 * - La nota de crédito referencia al comprobante, exige motivo y revierte la aplicación del pago.
 * - El costo de emitir lo asume el colegio: nunca se cobra a la familia (regla 11).
 */
class Comprobantes
{
    public const TIPOS = ['03' => 'Boleta de venta electrónica', '01' => 'Factura electrónica', '07' => 'Nota de crédito electrónica'];

    /** Espera entre intentos (segundos): 1, 5, 15, 30 y 60 minutos. */
    public const ESPERAS = [60, 300, 900, 1800, 3600];

    public function __construct(private Auditoria $auditoria, private AlmacenArchivos $almacen, private ProveedoresFacturacion $proveedores, private Cobros $cobros) {}

    public static function adaptador(string $proveedor): ProveedorFacturacion
    {
        $clase = config('services.facturacion.simular') ? FacturacionSimulada::class : (config('services.facturacion.adaptadores')[$proveedor] ?? null);
        if (! $clase) {
            throw new \RuntimeException("No hay conector para el proveedor de facturación «{$proveedor}».");
        }

        return app($clase);
    }

    /**
     * Crea el comprobante del pago y lo deja en cola para enviarlo.
     *
     * @param  array{tipo_doc: string, numero_doc: string, nombre: string, email?: string|null}  $cliente
     */
    public function emitirParaPago(int $pagoId, string $tipo, array $cliente): int
    {
        $proveedor = $this->proveedores->predeterminado();
        if (! $proveedor) {
            throw ValidationException::withMessages(['comprobante' => ['Configura un proveedor de facturación predeterminado antes de cobrar.']]);
        }
        if ($tipo === '01' && ! preg_match('/^(10|20)\d{9}$/', $cliente['numero_doc'])) {
            throw ValidationException::withMessages(['cliente.numero_doc' => ['La factura necesita un RUC válido de 11 dígitos.']]);
        }
        if (DB::table('comprobantes')->where('pago_id', $pagoId)->whereIn('tipo', ['01', '03'])->exists()) {
            throw ValidationException::withMessages(['comprobante' => ['El pago ya tiene comprobante.']]);
        }
        $items = DB::table('pago_aplicaciones as pa')->join('cargos as c', 'c.id', '=', 'pa.cargo_id')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')
            ->where('pa.pago_id', $pagoId)->orderBy('c.vencimiento')
            ->get(['pa.cargo_id', 'pa.monto', 'c.descripcion', 'cp.afecto_igv'])
            ->map(fn ($i) => self::item((int) $i->cargo_id, $i->descripcion, (float) $i->monto, (bool) $i->afecto_igv))->all();

        return $this->crear($proveedor, $tipo, $cliente, $items, ['pago_id' => $pagoId]);
    }

    /** Nota de crédito total sobre un comprobante aceptado: revierte la aplicación del pago. */
    public function notaCredito(int $comprobanteId, string $motivo, int $usuarioId): int
    {
        $ref = DB::table('comprobantes')->find($comprobanteId);
        abort_if(! $ref, 404);
        if ($ref->tipo === '07') {
            throw ValidationException::withMessages(['comprobante' => ['No se emite nota de crédito sobre otra nota de crédito.']]);
        }
        if (! in_array($ref->estado_sunat, ['aceptado', 'observado'], true)) {
            throw ValidationException::withMessages(['comprobante' => ['Solo se emite nota de crédito sobre un comprobante aceptado. Si aún no se envió, anúlalo.']]);
        }
        if (DB::table('comprobantes')->where('comprobante_ref_id', $comprobanteId)->where('tipo', '07')->where('estado_sunat', '<>', 'anulado')->exists()) {
            throw ValidationException::withMessages(['comprobante' => ['Ese comprobante ya tiene nota de crédito.']]);
        }
        $proveedor = DB::table('proveedores_facturacion')->find($ref->proveedor_facturacion_id);
        $items = DB::table('comprobante_items')->where('comprobante_id', $comprobanteId)->get()
            ->map(fn ($i) => self::item($i->cargo_id ? (int) $i->cargo_id : null, $i->descripcion, (float) $i->total, $i->afectacion_igv === '10'))->all();

        return DB::transaction(function () use ($ref, $proveedor, $items, $motivo, $usuarioId) {
            if ($ref->pago_id) {
                $this->cobros->anular((int) $ref->pago_id, "Nota de crédito: {$motivo}", $usuarioId);
            }

            return $this->crear($proveedor, '07', ['tipo_doc' => $ref->cliente_tipo_doc, 'numero_doc' => $ref->cliente_numero_doc, 'nombre' => $ref->cliente_nombre, 'email' => $ref->email_cliente],
                $items, ['comprobante_ref_id' => $ref->id, 'motivo' => $motivo, 'pago_id' => $ref->pago_id]);
        });
    }

    /** Anula un comprobante que aún no fue aceptado (no llegó a SUNAT) y el pago asociado. */
    public function anularNoEnviado(int $comprobanteId, string $motivo, int $usuarioId): void
    {
        $c = DB::table('comprobantes')->find($comprobanteId);
        abort_if(! $c, 404);
        if (! in_array($c->estado_sunat, ['pendiente', 'rechazado'], true)) {
            throw ValidationException::withMessages(['comprobante' => ['El comprobante ya fue aceptado: emite una nota de crédito.']]);
        }
        DB::transaction(function () use ($c, $motivo, $usuarioId) {
            DB::table('comprobantes')->where('id', $c->id)->update(['estado_sunat' => 'anulado', 'motivo' => $motivo]);
            if ($c->pago_id && $c->tipo !== '07') {
                $this->cobros->anular((int) $c->pago_id, $motivo, $usuarioId);
            }
            $this->auditoria->registrar('comprobante_anulado', 'comprobantes', (int) $c->id, ['estado_sunat' => $c->estado_sunat], ['estado_sunat' => 'anulado', 'motivo' => $motivo]);
        });
    }

    /** Lo llama la cola: envía al proveedor y guarda XML, PDF y respuesta. Devuelve «pendiente» si hay que reintentar. */
    public function enviar(int $comprobanteId): string
    {
        $c = DB::table('comprobantes')->find($comprobanteId);
        if (! $c || in_array($c->estado_sunat, ['aceptado', 'observado', 'anulado'], true)) {
            return $c->estado_sunat ?? 'inexistente';
        }
        $proveedor = DB::table('proveedores_facturacion')->find($c->proveedor_facturacion_id);
        DB::table('comprobantes')->where('id', $c->id)->update(['intentos' => DB::raw('intentos + 1'), 'estado_sunat' => 'enviado']);
        $documento = $this->documento($c);
        try {
            $credenciales = json_decode(Crypt::decryptString($proveedor->credenciales_cifradas), true) ?: [];
        } catch (\Throwable) {
            $credenciales = [];
        }
        try {
            $r = self::adaptador($proveedor->proveedor)->emitir($documento, $credenciales, $proveedor->ambiente);
        } catch (\Throwable $e) {
            $intentos = (int) $c->intentos + 1;
            DB::table('comprobantes')->where('id', $c->id)->update([
                'estado_sunat' => 'pendiente', 'ultimo_error' => mb_substr($e->getMessage(), 0, 1000),
                'siguiente_intento_at' => isset(self::ESPERAS[$intentos - 1]) ? now()->addSeconds(self::ESPERAS[$intentos - 1]) : null,
            ]);
            Log::warning("Comprobante {$c->serie}-{$c->numero}: el proveedor falló (intento {$intentos}): {$e->getMessage()}");

            return 'pendiente';
        }
        $nombre = "{$c->serie}-{$c->numero}";
        $xml = $this->almacen->guardarContenido($r['xml'], "$nombre.xml", 'application/xml');
        $pdf = $this->almacen->guardarContenido($this->pdf($documento, $r), "$nombre.pdf", 'application/pdf');
        DB::table('comprobantes')->where('id', $c->id)->update([
            'estado_sunat' => $r['estado'], 'respuesta_proveedor' => json_encode(['mensaje' => $r['mensaje']] + $r['respuesta']),
            'xml_archivo_id' => $xml->id, 'pdf_archivo_id' => $pdf->id, 'ultimo_error' => null, 'siguiente_intento_at' => null,
        ]);
        if ($r['estado'] !== 'rechazado' && $c->email_cliente && ! $c->correo_enviado_at) {
            $this->enviarCorreo((int) $c->id);
        }

        return $r['estado'];
    }

    public function enviarCorreo(int $comprobanteId, ?string $email = null): void
    {
        $c = DB::table('comprobantes')->find($comprobanteId);
        $destino = $email ?: $c?->email_cliente;
        if (! $c || ! $destino || ! $c->pdf_archivo_id) {
            throw ValidationException::withMessages(['email' => ['El comprobante aún no tiene PDF o no hay correo de destino.']]);
        }
        $colegio = DB::table('instituciones')->where('id', $c->institucion_id)->value('nombre');
        $archivo = Archivo::query()->findOrFail($c->pdf_archivo_id);
        try {
            app(CorreoTransaccional::class)->enviar(new ComprobanteEmitido($colegio, self::TIPOS[$c->tipo], "{$c->serie}-{$c->numero}", (float) $c->total, $archivo),
                $destino, 'comprobante_emitido', (int) $c->institucion_id);
            DB::table('comprobantes')->where('id', $c->id)->update(['correo_enviado_at' => now(), 'email_cliente' => $destino]);
        } catch (\Throwable $e) {
            Log::warning("Comprobante {$c->serie}-{$c->numero}: no se pudo enviar el correo: {$e->getMessage()}");
        }
    }

    public function listar(array $filtros): array
    {
        return DB::table('comprobantes as c')->leftJoin('comprobantes as r', 'r.id', '=', 'c.comprobante_ref_id')
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('c.estado_sunat', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('c.tipo', $v))
            ->when($filtros['buscar'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('c.cliente_nombre', 'ilike', "%$v%")->orWhere('c.cliente_numero_doc', 'like', "%$v%")
                ->orWhereRaw("c.serie || '-' || c.numero = ?", [$v])))
            ->orderByDesc('c.id')->limit(300)
            ->get(['c.id', 'c.tipo', 'c.serie', 'c.numero', 'c.fecha_emision', 'c.cliente_nombre', 'c.cliente_numero_doc', 'c.total', 'c.estado_sunat', 'c.intentos',
                'c.ultimo_error', 'c.siguiente_intento_at', 'c.pago_id', 'c.motivo', 'c.pdf_archivo_id', 'c.xml_archivo_id', 'c.email_cliente', 'c.correo_enviado_at',
                DB::raw("r.serie || '-' || r.numero as referencia"),
                DB::raw("exists (select 1 from comprobantes n where n.comprobante_ref_id = c.id and n.tipo = '07' and n.estado_sunat <> 'anulado') as tiene_nota")])
            ->map(fn ($c) => array_merge((array) $c, ['total' => (float) $c->total, 'nombre_tipo' => self::TIPOS[$c->tipo]]))->all();
    }

    /** @param array<string, mixed> $extra */
    private function crear(object $proveedor, string $tipo, array $cliente, array $items, array $extra): int
    {
        $serie = match ($tipo) {
            '01' => $proveedor->serie_factura,
            '03' => $proveedor->serie_boleta,
            '07' => $proveedor->serie_nota_credito ?: throw ValidationException::withMessages(['comprobante' => ['Configura la serie de notas de crédito del proveedor.']]),
        };
        $totales = ['gravado' => 0.0, 'exonerado' => 0.0, 'igv' => 0.0, 'total' => 0.0];
        foreach ($items as $i) {
            $totales[$i['afectacion_igv'] === '10' ? 'gravado' : 'exonerado'] += $i['valor_unitario'];
            $totales['igv'] += $i['igv'];
            $totales['total'] += $i['total'];
        }
        $totales = array_map(fn ($v) => round($v, 2), $totales);
        $cliente = self::clienteNormalizado($cliente, $tipo, $totales['total']);

        return DB::transaction(function () use ($proveedor, $tipo, $serie, $cliente, $items, $totales, $extra) {
            $inst = ContextoInstitucion::requerido();
            // Correlativo sin saltos: bloqueo transaccional por colegio + serie.
            DB::select('select pg_advisory_xact_lock(?, ?)', [$inst, crc32($tipo.$serie) & 0x7FFFFFFF]);
            $numero = (int) DB::table('comprobantes')->where('tipo', $tipo)->where('serie', $serie)->max('numero') + 1;
            $id = (int) DB::table('comprobantes')->insertGetId([
                'institucion_id' => $inst, 'proveedor_facturacion_id' => $proveedor->id, 'pago_id' => $extra['pago_id'] ?? null, 'tipo' => $tipo,
                'serie' => $serie, 'numero' => $numero, 'fecha_emision' => now()->toDateString(),
                'cliente_tipo_doc' => $cliente['tipo_doc'], 'cliente_numero_doc' => $cliente['numero_doc'], 'cliente_nombre' => $cliente['nombre'],
                'email_cliente' => $cliente['email'] ?? null, 'comprobante_ref_id' => $extra['comprobante_ref_id'] ?? null, 'motivo' => $extra['motivo'] ?? null,
            ] + $totales);
            foreach ($items as $i) {
                DB::table('comprobante_items')->insert([
                    'institucion_id' => $inst, 'comprobante_id' => $id, 'cargo_id' => $i['cargo_id'], 'descripcion' => mb_substr($i['descripcion'], 0, 250),
                    'valor_unitario' => $i['valor_unitario'], 'afectacion_igv' => $i['afectacion_igv'], 'total' => $i['total'],
                ]);
            }
            $this->auditoria->registrar($tipo === '07' ? 'nota_credito_emitida' : 'comprobante_creado', 'comprobantes', $id, null, ['serie' => "$serie-$numero", 'total' => $totales['total']] + array_intersect_key($extra, ['motivo' => 1]));
            DB::afterCommit(fn () => EmitirComprobante::dispatch($inst, $id));

            return $id;
        });
    }

    /** Ítem con IGV incluido en el precio: los servicios educativos están exonerados (código 20). */
    private static function item(?int $cargoId, string $descripcion, float $total, bool $afecto): array
    {
        $valor = $afecto ? round($total / 1.18, 2) : round($total, 2);

        return ['cargo_id' => $cargoId, 'descripcion' => $descripcion, 'valor_unitario' => $valor, 'igv' => round($total - $valor, 2), 'total' => round($total, 2), 'afectacion_igv' => $afecto ? '10' : '20'];
    }

    /** Boletas de menos de S/ 700 pueden ir sin documento (SUNAT): «-» con tipo 0. */
    private static function clienteNormalizado(array $c, string $tipo, float $total): array
    {
        $numero = preg_replace('/\s+/', '', (string) ($c['numero_doc'] ?? ''));
        $tipoDoc = match (true) {
            $tipo === '01' || strlen($numero) === 11 => '6',
            preg_match('/^\d{8}$/', $numero) === 1 => '1',
            $numero !== '' && $numero !== '-' => '4',
            default => '0',
        };
        if ($tipoDoc === '0' && $total >= 700) {
            throw ValidationException::withMessages(['cliente.numero_doc' => ['Desde S/ 700 la boleta debe llevar el DNI o documento del cliente.']]);
        }

        return ['tipo_doc' => $tipoDoc, 'numero_doc' => $tipoDoc === '0' ? '-' : $numero, 'nombre' => trim((string) ($c['nombre'] ?? '')) ?: 'CLIENTES VARIOS', 'email' => $c['email'] ?? null];
    }

    private function documento(object $c): array
    {
        $inst = DB::table('instituciones')->where('id', $c->institucion_id)->first(['nombre', 'razon_social', 'ruc', 'direccion']);

        return [
            'tipo' => $c->tipo, 'nombre_tipo' => self::TIPOS[$c->tipo], 'serie' => $c->serie, 'numero' => $c->numero, 'fecha_emision' => (string) $c->fecha_emision,
            'emisor' => ['ruc' => $inst->ruc ?? '', 'razon_social' => $inst->razon_social ?: $inst->nombre, 'nombre_comercial' => $inst->nombre, 'direccion' => $inst->direccion],
            'cliente' => ['tipo_doc' => $c->cliente_tipo_doc, 'numero_doc' => $c->cliente_numero_doc, 'nombre' => $c->cliente_nombre],
            'items' => DB::table('comprobante_items')->where('comprobante_id', $c->id)->orderBy('id')->get()->map(fn ($i) => ['descripcion' => $i->descripcion, 'total' => (float) $i->total, 'valor_unitario' => (float) $i->valor_unitario, 'afectacion_igv' => $i->afectacion_igv])->all(),
            'gravado' => (float) $c->gravado, 'exonerado' => (float) $c->exonerado, 'igv' => (float) $c->igv, 'total' => (float) $c->total,
            'referencia' => $c->comprobante_ref_id ? DB::table('comprobantes')->where('id', $c->comprobante_ref_id)->selectRaw("serie || '-' || numero as r")->value('r') : null,
            'motivo' => $c->motivo,
        ];
    }

    private function pdf(array $d, array $r): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $s = fn (float $n) => 'S/ '.number_format($n, 2);
        $filas = implode('', array_map(fn ($i) => '<tr><td>'.$e($i['descripcion']).'</td><td class="n">'.$s($i['total']).'</td></tr>', $d['items']));
        $ref = $d['referencia'] ? '<p>Documento que modifica: <b>'.$e($d['referencia']).'</b> · Motivo: '.$e($d['motivo']).'</p>' : '';
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}h1{font-size:15px;margin:0}
            .caja{border:1px solid #333;padding:8px;text-align:center;width:220px;float:right}table{width:100%;border-collapse:collapse;margin-top:14px}
            td,th{border-bottom:1px solid #ddd;padding:5px;text-align:left}.n{text-align:right}.pie{margin-top:18px;font-size:9px;color:#555}</style></head><body>'
            .'<div class="caja"><b>RUC '.$e($d['emisor']['ruc']).'</b><br>'.$e(mb_strtoupper($d['nombre_tipo'])).'<br><b>'.$e($d['serie'].'-'.$d['numero']).'</b></div>'
            .'<h1>'.$e($d['emisor']['razon_social']).'</h1><p>'.$e($d['emisor']['nombre_comercial']).'<br>'.$e($d['emisor']['direccion']).'</p><div style="clear:both"></div>'
            .'<p>Fecha de emisión: '.$e(Carbon::parse($d['fecha_emision'])->format('d/m/Y')).'<br>Cliente: '.$e($d['cliente']['nombre']).' · Doc.: '.$e($d['cliente']['numero_doc']).'</p>'.$ref
            .'<table><thead><tr><th>Descripción</th><th class="n">Importe</th></tr></thead><tbody>'.$filas.'</tbody></table>'
            .'<table><tr><td>Op. exonerada</td><td class="n">'.$s($d['exonerado']).'</td></tr><tr><td>Op. gravada</td><td class="n">'.$s($d['gravado']).'</td></tr>'
            .'<tr><td>IGV</td><td class="n">'.$s($d['igv']).'</td></tr><tr><td><b>Total</b></td><td class="n"><b>'.$s($d['total']).'</b></td></tr></table>'
            .'<p class="pie">Representación impresa de la '.$e(mb_strtolower($d['nombre_tipo'])).'. '.$e($r['mensaje']).(isset($r['respuesta']['hash']) ? ' Resumen: '.$e($r['respuesta']['hash']) : '').'</p></body></html>';
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('A5');
        $pdf->render();

        return (string) $pdf->output();
    }
}
