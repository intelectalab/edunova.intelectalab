<?php

namespace App\Modulos\PAG\Http\Controllers;

use App\Modulos\PAG\Services\Caja;
use App\Modulos\PAG\Services\Cobros;
use App\Modulos\PAG\Services\Comprobantes;
use App\Modulos\PAG\Services\Morosidad;
use App\Modulos\PAG\Services\PagosEnLinea;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Sprint 17 · Caja, comprobantes, pasarela del colegio y pagos en línea de la familia. */
class CobranzaController
{
    public function __construct(private Caja $caja, private Comprobantes $comprobantes, private PagosEnLinea $enLinea) {}

    // ── EDUN-75 / EDUN-76 · Caja

    public function caja(Request $request): JsonResponse
    {
        $sesion = $this->caja->sesionAbierta((int) $request->user()->id);

        return response()->json([
            'cajas' => $this->caja->cajas(), 'sesion' => $sesion ? $this->caja->resumen((int) $sesion->id) : null,
            'medios' => Cobros::MEDIOS, 'tipos' => ['03' => 'Boleta', '01' => 'Factura'],
        ]);
    }

    public function crearCaja(Request $request): JsonResponse
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:60']], ['nombre.required' => 'Ponle un nombre a la caja.']);

        return response()->json(['id' => $this->caja->crearCaja($d['nombre']), 'cajas' => $this->caja->cajas()], 201);
    }

    public function abrir(Request $request): JsonResponse
    {
        $d = $request->validate(['caja_id' => ['required', 'integer'], 'monto_inicial' => ['required', 'numeric', 'min:0', 'max:999999']],
            ['monto_inicial.required' => 'Indica el monto inicial en efectivo (puede ser 0).']);
        $id = $this->caja->abrir((int) $d['caja_id'], (float) $d['monto_inicial'], (int) $request->user()->id);

        return response()->json($this->caja->resumen($id), 201);
    }

    public function buscar(Request $request): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:3', 'max:80']], ['q.min' => 'Escribe al menos 3 letras o el DNI.']);

        return response()->json(['data' => $this->caja->buscar($d['q'])]);
    }

    public function cobrar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'cargos' => ['required', 'array', 'min:1', 'max:30'], 'cargos.*.id' => ['required', 'integer', 'distinct'], 'cargos.*.monto' => ['required', 'numeric', 'gt:0'],
            'medios' => ['required', 'array', 'min:1', 'max:5'], 'medios.*.medio' => ['required', Rule::in(array_keys(Cobros::MEDIOS))],
            'medios.*.monto' => ['required', 'numeric', 'gt:0'], 'medios.*.referencia' => ['nullable', 'string', 'max:80'],
            'apoderado_id' => ['nullable', 'integer'], 'tipo_comprobante' => ['required', Rule::in(['01', '03'])],
            'cliente.numero_doc' => ['nullable', 'string', 'max:15'], 'cliente.nombre' => ['nullable', 'string', 'max:200'], 'cliente.email' => ['nullable', 'email', 'max:150'],
        ], ['cargos.required' => 'Elige al menos un cargo.', 'medios.required' => 'Indica cómo paga.']);
        $r = $this->caja->cobrar((int) $request->user()->id, $d['cargos'], $d['medios'], $d['apoderado_id'] ?? null, $d['tipo_comprobante'], $d['cliente'] ?? ['numero_doc' => '', 'nombre' => '']);

        return response()->json($r + ['comprobante' => collect($this->comprobantes->listar([]))->firstWhere('id', $r['comprobante_id'])], 201);
    }

    public function cerrar(Request $request): JsonResponse
    {
        $d = $request->validate(['contado' => ['required', 'array'], 'contado.*' => ['numeric', 'min:0'], 'justificacion' => ['nullable', 'string', 'max:500']]);
        $sesion = $this->caja->sesionAbierta((int) $request->user()->id);
        abort_if(! $sesion, 422, 'No tienes una caja abierta.');

        return response()->json($this->caja->cerrar((int) $sesion->id, $d['contado'], $d['justificacion'] ?? null, (int) $request->user()->id) + ['sesion_id' => $sesion->id]);
    }

    public function reporteCaja(int $sesion): Response
    {
        return response($this->caja->reportePdf($sesion), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"arqueo-{$sesion}.pdf\""]);
    }

    // ── EDUN-77 / EDUN-78 · Comprobantes

    public function comprobantes(Request $request): JsonResponse
    {
        $f = $request->validate(['estado' => ['nullable', 'string'], 'tipo' => ['nullable', Rule::in(['01', '03', '07'])], 'buscar' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->comprobantes->listar($f), 'tipos' => Comprobantes::TIPOS]);
    }

    public function archivoComprobante(Request $request, int $comprobante, string $formato, AlmacenArchivos $almacen): JsonResponse
    {
        $c = DB::table('comprobantes')->find($comprobante);
        abort_if(! $c, 404);
        $id = $formato === 'xml' ? $c->xml_archivo_id : $c->pdf_archivo_id;
        abort_if(! $id, 404, 'El comprobante aún no tiene ese archivo.');

        return response()->json(['url' => $almacen->urlTemporal(Archivo::query()->findOrFail($id))]);
    }

    public function reenviar(int $comprobante): JsonResponse
    {
        $c = DB::table('comprobantes')->find($comprobante);
        abort_if(! $c, 404);
        $estado = $c->estado_sunat === 'pendiente' ? $this->comprobantes->enviar($comprobante) : $c->estado_sunat;

        return response()->json(['estado' => $estado, 'comprobante' => collect($this->comprobantes->listar([]))->firstWhere('id', $comprobante)]);
    }

    public function enviarCorreo(Request $request, int $comprobante): JsonResponse
    {
        $d = $request->validate(['email' => ['required', 'email', 'max:150']], ['email.required' => 'Escribe el correo.']);
        $this->comprobantes->enviarCorreo($comprobante, $d['email']);

        return response()->json(['message' => 'Comprobante enviado.']);
    }

    public function notaCredito(Request $request, int $comprobante): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']], ['motivo.required' => 'La nota de crédito necesita un motivo.', 'motivo.min' => 'Explica el motivo.']);
        $id = $this->comprobantes->notaCredito($comprobante, $d['motivo'], (int) $request->user()->id);

        return response()->json(['id' => $id, 'comprobante' => collect($this->comprobantes->listar([]))->firstWhere('id', $id)], 201);
    }

    public function anular(Request $request, int $comprobante): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']], ['motivo.required' => 'Indica el motivo de la anulación.']);
        $this->comprobantes->anularNoEnviado($comprobante, $d['motivo'], (int) $request->user()->id);

        return response()->json(['message' => 'Comprobante y pago anulados.']);
    }

    // ── EDUN-88 · Cuenta de pasarela del colegio

    public function pasarela(): JsonResponse
    {
        return response()->json(['data' => $this->enLinea->cuentas(), 'proveedores' => PagosEnLinea::PROVEEDORES, 'medios' => PagosEnLinea::MEDIOS, 'simulada' => (bool) config('services.pasarela.simular')]);
    }

    public function guardarPasarela(Request $request, ?int $cuenta = null): JsonResponse
    {
        $d = $request->validate([
            'proveedor' => ['required', Rule::in(array_keys(PagosEnLinea::PROVEEDORES))], 'ambiente' => ['required', Rule::in(['pruebas', 'produccion'])],
            'llave_publica' => ['nullable', 'string', 'max:200'], 'llave_secreta' => ['nullable', 'string', 'max:500'], 'secreto_webhook' => ['nullable', 'string', 'max:200'],
            'medios' => ['required', 'array'], 'medios.*' => [Rule::in(array_keys(PagosEnLinea::MEDIOS))],
        ]);
        $id = $this->enLinea->guardarCuenta($d, (int) $request->user()->id, $cuenta);

        return response()->json(['id' => $id, 'data' => $this->enLinea->cuentas()], $cuenta ? 200 : 201);
    }

    public function probarPasarela(int $cuenta): JsonResponse
    {
        return response()->json($this->enLinea->probar($cuenta) + ['data' => $this->enLinea->cuentas()]);
    }

    /** EDUN-74 · Aviso de la pasarela (sin sesión; la URL lleva el token de la cuenta del colegio). */
    public function webhook(Request $request, string $token): JsonResponse
    {
        $r = $this->enLinea->webhook($token, $request->getContent(), collect($request->headers->all())->map(fn ($v) => $v[0] ?? '')->all());

        return response()->json(['estado' => $r['estado']], $r['http']);
    }

    // ── EDUN-73 · La familia paga en línea

    public function misPagos(Request $request): JsonResponse
    {
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);
        $cuenta = $this->enLinea->cuentaActiva();
        $apoderado = DB::table('apoderados')->where('user_id', $request->user()->id)->pluck('id');
        $comprobantes = DB::table('comprobantes as c')->join('pagos as p', 'p.id', '=', 'c.pago_id')
            ->where(fn ($q) => $q->whereIn('p.apoderado_id', $apoderado)->orWhereIn('p.id', DB::table('pago_aplicaciones as pa')->join('cargos as g', 'g.id', '=', 'pa.cargo_id')->whereIn('g.alumno_id', $hijos)->select('pa.pago_id')))
            ->orderByDesc('c.id')->limit(50)->get(['c.id', 'c.tipo', 'c.serie', 'c.numero', 'c.fecha_emision', 'c.total', 'c.estado_sunat', 'c.pdf_archivo_id'])
            ->map(fn ($c) => array_merge((array) $c, ['total' => (float) $c->total, 'nombre_tipo' => Comprobantes::TIPOS[$c->tipo]]));

        return response()->json([
            'cargos' => app(Cobros::class)->pendientes($hijos, soloEmitidos: true),
            'en_linea' => $cuenta ? ['medios' => json_decode($cuenta->medios, true) ?: [], 'nombres' => PagosEnLinea::MEDIOS, 'pruebas' => $cuenta->ambiente === 'pruebas'] : null,
            'comprobantes' => $comprobantes,
        ]);
    }

    public function crearOrden(Request $request): JsonResponse
    {
        $d = $request->validate(['cargos' => ['required', 'array', 'min:1', 'max:30'], 'cargos.*' => ['integer']], ['cargos.required' => 'Elige qué cargos vas a pagar.']);

        return response()->json($this->enLinea->crearOrden($d['cargos'], FamiliaController::alumnosDe((int) $request->user()->id), (int) $request->user()->id, $request->user()->email), 201);
    }

    public function orden(Request $request, string $orden): JsonResponse
    {
        $t = $this->enLinea->transaccion($orden);
        abort_if(! $t, 404);
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);
        abort_unless(DB::table('cargos')->whereIn('id', array_column($t['cargos'], 'id'))->whereNotIn('alumno_id', $hijos)->doesntExist(), 403);

        return response()->json($t);
    }

    public function simular(Request $request, string $orden): JsonResponse
    {
        $d = $request->validate(['resultado' => ['required', Rule::in(['pagada', 'fallida'])], 'medio' => ['required', Rule::in(array_keys(PagosEnLinea::MEDIOS))]]);
        $r = $this->enLinea->simularPago($orden, $d['resultado'], $d['medio'], FamiliaController::alumnosDe((int) $request->user()->id));

        return response()->json(['estado' => $r['estado'], 'orden' => $this->enLinea->transaccion($orden)]);
    }

    /** EDUN-82 · Estado de cuenta por hijo: cargos del año, próximo vencimiento y comprobantes. */
    public function estadoCuenta(Request $request): JsonResponse
    {
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);
        $hoy = now()->toDateString();
        $cargos = DB::table('cargos as c')->join('alumnos as a', 'a.id', '=', 'c.alumno_id')->whereIn('c.alumno_id', $hijos)->where('c.estado', '<>', 'anulado')
            ->where('c.vencimiento', '>=', now()->subYear()->startOfYear()->toDateString())->orderBy('c.vencimiento')
            ->get(['c.id', 'c.alumno_id', 'a.nombres as alumno', 'c.descripcion', 'c.emision', 'c.vencimiento', 'c.monto', 'c.descuento', 'c.pagado', 'c.estado',
                DB::raw(Cobros::saldoSql().' as saldo')])
            ->map(fn ($c) => array_merge((array) $c, ['monto' => (float) $c->monto, 'descuento' => (float) $c->descuento, 'pagado' => (float) $c->pagado, 'saldo' => (float) $c->saldo,
                'vencido' => in_array($c->estado, ['pendiente', 'parcial'], true) && $c->vencimiento < $hoy]));
        $comprobantes = $this->misPagos($request)->getData(true)['comprobantes'];
        $porHijo = $cargos->groupBy('alumno_id')->map(function ($g, $id) use ($hoy) {
            $pendientes = $g->whereIn('estado', ['pendiente', 'parcial']);
            $proximo = $pendientes->filter(fn ($c) => $c['vencimiento'] >= $hoy)->sortBy('vencimiento')->first();

            return [
                'alumno_id' => (int) $id, 'alumno' => $g->first()['alumno'], 'deuda_vencida' => round($pendientes->where('vencido', true)->sum('saldo'), 2),
                'por_vencer' => round($pendientes->where('vencido', false)->sum('saldo'), 2),
                'proximo' => $proximo ? ['descripcion' => $proximo['descripcion'], 'vencimiento' => $proximo['vencimiento'], 'saldo' => $proximo['saldo']] : null,
                'cargos' => $g->values()->all(),
            ];
        })->values();

        return response()->json(['hijos' => $porHijo, 'comprobantes' => $comprobantes]);
    }

    public function miComprobante(Request $request, int $comprobante, AlmacenArchivos $almacen): JsonResponse
    {
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);
        $c = DB::table('comprobantes')->find($comprobante);
        abort_if(! $c || ! $c->pdf_archivo_id || ! $c->pago_id, 404);
        $propio = DB::table('pago_aplicaciones as pa')->join('cargos as g', 'g.id', '=', 'pa.cargo_id')->where('pa.pago_id', $c->pago_id)->whereIn('g.alumno_id', $hijos)->exists();
        abort_unless($propio, 404);

        return response()->json(['url' => $almacen->urlTemporal(Archivo::query()->findOrFail($c->pdf_archivo_id))]);
    }

    // ── EDUN-81 · Morosidad

    public function morosidad(Request $request, Morosidad $morosidad): JsonResponse
    {
        $tramo = $request->validate(['tramo' => ['nullable', Rule::in(array_keys(Morosidad::TRAMOS))]])['tramo'] ?? null;

        return response()->json(['data' => $morosidad->familias($tramo), 'resumen' => $morosidad->resumen(), 'tramos' => Morosidad::TRAMOS]);
    }

    public function historialCobranza(int $apoderado, Morosidad $morosidad): JsonResponse
    {
        return response()->json($morosidad->historial($apoderado));
    }

    public function gestionCobranza(Request $request, int $apoderado, Morosidad $morosidad): JsonResponse
    {
        $d = $request->validate(['canal' => ['required', Rule::in(['llamada', 'whatsapp', 'correo', 'presencial'])], 'resultado' => ['required', 'string', 'max:200'],
            'detalle' => ['nullable', 'string', 'max:2000'], 'monto' => ['nullable', 'numeric', 'gt:0'], 'fecha' => ['nullable', 'date_format:Y-m-d']],
            ['resultado.required' => 'Indica el resultado del contacto.']);
        $morosidad->registrarGestion($apoderado, $d['canal'], $d['resultado'], $d['detalle'] ?? null, isset($d['monto']) ? (float) $d['monto'] : null, $d['fecha'] ?? null, (int) $request->user()->id);

        return response()->json($morosidad->historial($apoderado), 201);
    }

    public function recordatorioMasivo(Request $request, Morosidad $morosidad): JsonResponse
    {
        $d = $request->validate(['tramo' => ['nullable', Rule::in(array_keys(Morosidad::TRAMOS))], 'canales' => ['required', 'array', 'min:1'], 'canales.*' => [Rule::in(['app', 'whatsapp', 'correo'])]]);

        return response()->json($morosidad->recordatorio($d['tramo'] ?? null, $d['canales'], (int) $request->user()->id));
    }
}
