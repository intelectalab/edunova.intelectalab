<?php

namespace App\Modulos\TIE\Services;

use App\Models\User;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Caja;
use App\Modulos\PAG\Services\GeneradorCargos;
use App\Modulos\PAG\Services\PagosEnLinea;
use App\Modulos\SEG\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-179 a 185 · Tienda escolar.
 *
 * - Comprar es siempre voluntario: nunca se crea un cargo obligatorio por útiles o uniformes.
 * - Productos y packs con su afectación de IGV (10 gravado por defecto, 20 exonerado, 30 inafecto;
 *   validarlo con el contador, D-18). El stock nunca queda negativo y todo movimiento queda registrado.
 * - Lista de útiles por grado y año, con pack opcional y entrega gradual.
 * - Pedido desde la app: reserva el stock 48 horas; se paga en línea (pasarela) o «con la pensión»
 *   (cargo voluntario en el estado de cuenta que no afecta la deuda de pensiones) y se recoge en
 *   secretaría. Sin pagar a las 48 horas, se anula y el stock vuelve.
 * - Venta en caja con los medios de caja y boleta electrónica (con alumno); venta libre sin alumno.
 * - El cobro usa un cargo por afectación de IGV para que la boleta lleve el impuesto correcto.
 */
class Tienda
{
    public const HORAS_RESERVA = 48;

    public const CATEGORIAS = ['util', 'uniforme', 'texto', 'pack', 'otro'];

    public function __construct(private Notificador $notificador, private Auditoria $auditoria) {}

    // ───────────── Productos y packs (EDUN-179)

    public function productos(bool $soloApp = false, ?string $nivel = null): array
    {
        $items = DB::table('tienda_pack_items as i')->join('tienda_productos as p', 'p.id', '=', 'i.producto_id')->get(['i.pack_id', 'i.producto_id', 'i.cantidad', 'p.nombre'])->groupBy('pack_id');

        return DB::table('tienda_productos')->where('activo', true)->when($soloApp, fn ($q) => $q->where('visible_app', true))
            ->when($nivel, fn ($q, $n) => $q->whereIn('nivel', [$n, 'todos']))->orderBy('categoria')->orderBy('nombre')->get()
            ->map(fn ($p) => array_merge((array) $p, ['precio' => (float) $p->precio, 'stock' => (int) $p->stock, 'disponible' => $this->disponible($p),
                'contenido' => $p->categoria === 'pack' ? collect($items[$p->id] ?? [])->map(fn ($i) => ['producto_id' => (int) $i->producto_id, 'nombre' => $i->nombre, 'cantidad' => (int) $i->cantidad])->values()->all() : null,
                'bajo_minimo' => $p->categoria !== 'pack' && (int) $p->stock <= (int) $p->stock_minimo]))->all();
    }

    public function guardarProducto(?int $id, array $d): array
    {
        $fila = array_intersect_key($d, array_flip(['codigo', 'nombre', 'categoria', 'nivel', 'precio', 'afectacion_igv', 'stock_minimo', 'visible_app', 'activo']));
        $fila['codigo'] = strtoupper(trim((string) ($fila['codigo'] ?? '')));
        if (DB::table('tienda_productos')->where('codigo', $fila['codigo'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['codigo' => ['Ya hay un producto con ese código.']]);
        }
        DB::transaction(function () use (&$id, $fila, $d) {
            if ($id) {
                abort_unless(DB::table('tienda_productos')->where('id', $id)->exists(), 404);
                DB::table('tienda_productos')->where('id', $id)->update($fila);
            } else {
                $id = (int) DB::table('tienda_productos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
            }
            if (($fila['categoria'] ?? null) === 'pack' && isset($d['contenido'])) {
                DB::table('tienda_pack_items')->where('pack_id', $id)->delete();
                foreach ($d['contenido'] as $c) {
                    if ((int) $c['producto_id'] === $id || DB::table('tienda_productos')->where('id', $c['producto_id'])->value('categoria') === 'pack') {
                        throw ValidationException::withMessages(['contenido' => ['Un pack contiene productos, no otros packs.']]);
                    }
                    DB::table('tienda_pack_items')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'pack_id' => $id, 'producto_id' => (int) $c['producto_id'], 'cantidad' => max(1, (int) $c['cantidad'])]);
                }
            }
        });

        return collect($this->productos())->firstWhere('id', $id) ?? (array) DB::table('tienda_productos')->find($id);
    }

    // ───────────── Inventario (EDUN-184)

    /** Ingreso (compra al proveedor) o ajuste (+/-) de stock: el stock nunca queda negativo. */
    public function movimiento(int $productoId, string $tipo, int $cantidad, ?float $costo, ?string $proveedor, ?string $motivo, int $usuarioId): array
    {
        return DB::transaction(function () use ($productoId, $tipo, $cantidad, $costo, $proveedor, $motivo, $usuarioId) {
            $p = DB::table('tienda_productos')->where('id', $productoId)->lockForUpdate()->first();
            abort_if(! $p, 404);
            if ($p->categoria === 'pack') {
                throw ValidationException::withMessages(['producto' => ['El stock de un pack sale de sus productos.']]);
            }
            $delta = $tipo === 'ingreso' ? abs($cantidad) : $cantidad;
            if ((int) $p->stock + $delta < 0) {
                throw ValidationException::withMessages(['cantidad' => ["El stock no puede quedar negativo (hay {$p->stock})."]]);
            }
            DB::table('tienda_productos')->where('id', $productoId)->update(['stock' => (int) $p->stock + $delta]);
            DB::table('tienda_movimientos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'producto_id' => $productoId, 'tipo' => $tipo, 'cantidad' => $delta,
                'costo_unitario' => $costo, 'proveedor' => $proveedor ?? $motivo, 'registrado_por' => $usuarioId]);

            return ['stock' => (int) $p->stock + $delta];
        });
    }

    public function kardex(int $productoId): array
    {
        return DB::table('tienda_movimientos as m')->leftJoin('tienda_pedidos as pe', 'pe.id', '=', 'm.tienda_pedido_id')->where('m.producto_id', $productoId)->orderByDesc('m.id')->limit(200)
            ->get(['m.id', 'm.tipo', 'm.cantidad', 'm.costo_unitario', 'm.proveedor', 'm.created_at', 'pe.numero'])->map(fn ($m) => (array) $m)->all();
    }

    // ───────────── Lista de útiles (EDUN-180)

    public function listas(?int $anioId): array
    {
        $anio = $anioId ?? DB::table('anios_escolares')->orderByRaw("estado = 'abierto' desc")->orderByDesc('anio')->value('id');

        return DB::table('tienda_listas as l')->join('grados as g', 'g.id', '=', 'l.grado_id')->leftJoin('tienda_productos as p', 'p.id', '=', 'l.pack_id')->where('l.anio_escolar_id', $anio)
            ->orderBy('g.nivel')->orderBy('g.numero')->get(['l.id', 'l.grado_id', 'g.nombre as grado', 'g.nivel', 'l.pack_id', 'p.nombre as pack', 'p.precio as pack_precio', 'l.entrega_gradual', 'l.publicada_at'])
            ->map(fn ($l) => array_merge((array) $l, ['pack_precio' => $l->pack_precio !== null ? (float) $l->pack_precio : null,
                'items' => DB::table('tienda_lista_items')->where('tienda_lista_id', $l->id)->orderBy('area')->orderBy('id')->get(['id', 'descripcion', 'cantidad', 'area', 'producto_id'])->map(fn ($i) => (array) $i)->all()]))->all();
    }

    public function guardarLista(int $gradoId, int $anioId, ?int $packId, bool $gradual, array $items): array
    {
        if ($packId && DB::table('tienda_productos')->where('id', $packId)->value('categoria') !== 'pack') {
            throw ValidationException::withMessages(['pack_id' => ['Elige un producto de tipo pack.']]);
        }
        $id = DB::transaction(function () use ($gradoId, $anioId, $packId, $gradual, $items) {
            $l = DB::table('tienda_listas')->where('grado_id', $gradoId)->where('anio_escolar_id', $anioId)->first();
            $id = $l?->id ?? (int) DB::table('tienda_listas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'grado_id' => $gradoId, 'anio_escolar_id' => $anioId]);
            DB::table('tienda_listas')->where('id', $id)->update(['pack_id' => $packId, 'entrega_gradual' => $gradual]);
            DB::table('tienda_lista_items')->where('tienda_lista_id', $id)->delete();
            foreach ($items as $i) {
                DB::table('tienda_lista_items')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'tienda_lista_id' => $id, 'descripcion' => mb_substr(trim($i['descripcion']), 0, 150),
                    'cantidad' => max(1, (int) ($i['cantidad'] ?? 1)), 'area' => $i['area'] ?? null, 'producto_id' => $i['producto_id'] ?? null]);
            }

            return (int) $id;
        });

        return collect($this->listas($anioId))->firstWhere('id', $id);
    }

    public function publicarLista(int $id): void
    {
        $l = DB::table('tienda_listas')->find($id);
        abort_if(! $l, 404);
        DB::table('tienda_listas')->where('id', $id)->update(['publicada_at' => now()]);
        $familias = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('alumno_apoderado as aa', 'aa.alumno_id', '=', 'm.alumno_id')
            ->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('s.grado_id', $l->grado_id)->where('m.anio_escolar_id', $l->anio_escolar_id)->whereNotNull('p.user_id')->pluck('p.user_id')->unique()->all();
        $this->notificador->enviar($familias, ['app'], 'comunicados', 'Lista de útiles publicada', 'Revísala en la app. Comprarla en la tienda del colegio es opcional.', ['pantalla' => 'tienda']);
    }

    // ───────────── Pedidos (EDUN-181 / 183)

    /**
     * Crea el pedido de la app: reserva el stock 48 horas y genera los cargos del cobro.
     *
     * @param  list<array{producto_id: int, cantidad: int}>  $items
     */
    public function pedirDesdeApp(int $alumnoId, array $items, string $formaPago, User $usuario): array
    {
        $apoderado = DB::table('apoderados')->where('user_id', $usuario->id)->value('id');

        return $this->crearPedido($alumnoId, $apoderado ? (int) $apoderado : null, $items, 'app', $formaPago, $usuario->id);
    }

    /** Arma la orden de pago en línea (Yape o tarjeta) con los cargos del pedido. */
    public function pagarEnLinea(int $pedidoId, array $hijos, User $usuario, PagosEnLinea $pagos): array
    {
        $p = DB::table('tienda_pedidos')->where('id', $pedidoId)->whereIn('alumno_id', $hijos ?: [0])->first();
        abort_if(! $p, 404);
        if ($p->estado !== 'pendiente') {
            throw ValidationException::withMessages(['pedido' => ['El pedido ya no está pendiente de pago.']]);
        }

        return $pagos->crearOrden($this->cargosDelPedido((int) $p->id), $hijos, $usuario->id, $usuario->email);
    }

    public function pedidos(?string $estado, ?array $hijos = null): array
    {
        $this->sincronizarPagos();

        return DB::table('tienda_pedidos as p')->leftJoin('alumnos as a', 'a.id', '=', 'p.alumno_id')
            ->when($hijos !== null, fn ($q) => $q->whereIn('p.alumno_id', $hijos ?: [0]))->when($estado, fn ($q, $e) => $q->where('p.estado', $e))->orderByDesc('p.id')->limit(300)
            ->get(['p.id', 'p.numero', 'p.canal', 'p.total', 'p.forma_pago', 'p.estado', 'p.reservado_hasta', 'p.entregado_at', 'p.entregado_a', 'p.created_at', 'p.alumno_id',
                DB::raw("coalesce(a.apellido_paterno || ', ' || a.nombres, 'Venta libre') as alumno")])
            ->map(fn ($p) => array_merge((array) $p, ['total' => (float) $p->total,
                'items' => DB::table('tienda_pedido_items as i')->join('tienda_productos as pr', 'pr.id', '=', 'i.producto_id')->where('i.tienda_pedido_id', $p->id)
                    ->get(['pr.nombre', 'i.cantidad', 'i.precio'])->map(fn ($i) => ['nombre' => $i->nombre, 'cantidad' => (int) $i->cantidad, 'precio' => (float) $i->precio])->all()]))->all();
    }

    public function mover(int $id, string $accion, ?string $entregadoA, int $usuarioId): array
    {
        $this->sincronizarPagos();
        $p = DB::table('tienda_pedidos')->where('id', $id)->lockForUpdate()->first();
        abort_if(! $p, 404);
        $nuevo = match (true) {
            $accion === 'preparar' && $p->estado === 'pagado' => 'preparado',
            $accion === 'entregar' && in_array($p->estado, ['pagado', 'preparado'], true) => 'entregado',
            $accion === 'anular' && in_array($p->estado, ['pendiente', 'pagado', 'preparado'], true) => 'anulado',
            default => null,
        };
        if (! $nuevo) {
            throw ValidationException::withMessages(['estado' => [$p->estado === 'pendiente' && $accion !== 'anular' ? 'El pedido todavía no está pagado.' : 'Esa acción no corresponde al estado del pedido.']]);
        }
        if ($nuevo === 'entregado' && trim((string) $entregadoA) === '') {
            throw ValidationException::withMessages(['entregado_a' => ['Indica a quién se entregó.']]);
        }
        if ($nuevo === 'anulado') {
            $pagados = DB::table('cargos')->whereIn('id', $this->cargosDelPedido($id))->where('pagado', '>', 0)->exists();
            if ($pagados && $p->forma_pago === 'inmediato') {
                throw ValidationException::withMessages(['estado' => ['El pedido ya se pagó: anula primero el pago en Caja (genera la nota de crédito).']]);
            }
            $this->liberar($p, $usuarioId);
        } else {
            DB::table('tienda_pedidos')->where('id', $id)->update(['estado' => $nuevo] + ($nuevo === 'entregado' ? ['entregado_at' => now(), 'entregado_a' => mb_substr(trim((string) $entregadoA), 0, 150)] : []));
        }
        $this->auditoria->registrar('tienda_pedido_'.$nuevo, 'tienda_pedidos', $id, ['estado' => $p->estado], ['estado' => $nuevo]);
        if ($nuevo === 'preparado' && $p->alumno_id) {
            $this->notificador->enviar($this->familia((int) $p->alumno_id), ['app'], 'pagos', "Pedido {$p->numero} listo", 'Recógelo en secretaría.', ['pantalla' => 'tienda']);
        }

        return collect($this->pedidos(null))->firstWhere('id', $id);
    }

    /** Pedidos de la app sin pagar después de 48 horas: se anulan y el stock vuelve. */
    public function anularVencidos(): int
    {
        $n = 0;
        foreach (DB::table('tienda_pedidos')->where('estado', 'pendiente')->where('canal', 'app')->where('reservado_hasta', '<', now())->get() as $p) {
            if (DB::table('cargos')->whereIn('id', $this->cargosDelPedido((int) $p->id))->where('pagado', '>', 0)->exists()) {
                continue;
            }
            DB::transaction(fn () => $this->liberar($p, null));
            $n++;
        }

        return $n;
    }

    // ───────────── Caja (EDUN-182)

    /**
     * Venta en caja: con alumno se cobra con los medios de caja y se emite la boleta electrónica;
     * venta libre (sin alumno) descuenta el stock y queda registrada.
     *
     * @param  list<array{producto_id: int, cantidad: int}>  $items
     * @param  list<array{medio: string, monto: float}>  $medios
     */
    public function venderEnCaja(?int $alumnoId, array $items, array $medios, array $cliente, int $usuarioId, Caja $caja): array
    {
        if (! $caja->sesionAbierta($usuarioId)) {
            throw ValidationException::withMessages(['caja' => ['Abre una caja antes de vender.']]);
        }

        return DB::transaction(function () use ($alumnoId, $items, $medios, $cliente, $usuarioId, $caja) {
            $apoderado = $alumnoId ? DB::table('alumno_apoderado as aa')->where('aa.alumno_id', $alumnoId)->orderByDesc('aa.es_responsable_pago')->value('aa.apoderado_id') : null;
            $pedido = $this->crearPedido($alumnoId, $apoderado ? (int) $apoderado : null, $items, 'caja', 'inmediato', $usuarioId);
            $comprobante = null;
            if ($alumnoId) {
                $cargos = DB::table('cargos')->whereIn('id', $this->cargosDelPedido($pedido['id']))->get(['id', 'monto'])->map(fn ($c) => ['id' => (int) $c->id, 'monto' => (float) $c->monto])->all();
                $r = $caja->cobrar($usuarioId, $cargos, $medios, $apoderado ? (int) $apoderado : null, '03', $cliente + ['numero_doc' => '', 'nombre' => '']);
                $comprobante = $r['comprobante_id'];
            }
            DB::table('tienda_pedidos')->where('id', $pedido['id'])->update(['estado' => 'pagado', 'reservado_hasta' => null]);

            return ['pedido' => $pedido['numero'], 'total' => $pedido['total'], 'comprobante_id' => $comprobante];
        });
    }

    // ───────────── Reportes (EDUN-185)

    public function reportes(?string $desde, ?string $hasta): array
    {
        $d = $desde ?? now()->startOfMonth()->toDateString();
        $h = $hasta ?? now()->toDateString();
        $base = DB::table('tienda_pedidos as p')->whereNotIn('p.estado', ['anulado', 'pendiente'])->whereRaw('(p.created_at at time zone ?)::date between ? and ?', [config('app.timezone'), $d, $h]);

        return [
            'desde' => $d, 'hasta' => $h,
            'ventas' => (float) (clone $base)->sum('p.total'), 'pedidos' => (clone $base)->count(),
            'por_canal' => (clone $base)->groupBy('p.canal')->get(['p.canal', DB::raw('sum(p.total) as total'), DB::raw('count(*) as pedidos')])->map(fn ($x) => ['canal' => $x->canal, 'total' => (float) $x->total, 'pedidos' => (int) $x->pedidos])->all(),
            'mas_vendidos' => DB::table('tienda_pedido_items as i')->join('tienda_pedidos as p', 'p.id', '=', 'i.tienda_pedido_id')->join('tienda_productos as pr', 'pr.id', '=', 'i.producto_id')
                ->whereNotIn('p.estado', ['anulado', 'pendiente'])->whereRaw('(p.created_at at time zone ?)::date between ? and ?', [config('app.timezone'), $d, $h])
                ->groupBy('pr.id', 'pr.nombre')->orderByDesc(DB::raw('sum(i.cantidad)'))->limit(10)
                ->get(['pr.nombre', DB::raw('sum(i.cantidad) as unidades'), DB::raw('sum(i.cantidad * i.precio) as total')])->map(fn ($x) => ['nombre' => $x->nombre, 'unidades' => (int) $x->unidades, 'total' => (float) $x->total])->all(),
            'bajo_minimo' => collect($this->productos())->where('bajo_minimo', true)->values()->all(),
            'por_entregar' => DB::table('tienda_pedidos')->whereIn('estado', ['pagado', 'preparado'])->count(),
        ];
    }

    // ───────────── Interno

    private function crearPedido(?int $alumnoId, ?int $apoderadoId, array $items, string $canal, string $formaPago, int $usuarioId): array
    {
        if (! $items) {
            throw ValidationException::withMessages(['items' => ['Agrega al menos un producto.']]);
        }

        return DB::transaction(function () use ($alumnoId, $apoderadoId, $items, $canal, $formaPago, $usuarioId) {
            $inst = ContextoInstitucion::requerido();
            DB::select('select pg_advisory_xact_lock(?)', [crc32("tienda-{$inst}")]);
            $anio = now()->format('y');
            $numero = sprintf('P%s-%06d', $anio, DB::table('tienda_pedidos')->where('numero', 'like', "P{$anio}-%")->count() + 1);
            $lineas = [];
            $porAfectacion = [];
            foreach ($items as $it) {
                $p = DB::table('tienda_productos')->where('id', $it['producto_id'])->where('activo', true)->lockForUpdate()->first();
                if (! $p || ($canal === 'app' && ! $p->visible_app)) {
                    throw ValidationException::withMessages(['items' => ['Un producto ya no está disponible.']]);
                }
                $cantidad = max(1, (int) $it['cantidad']);
                $this->descontar($p, $cantidad, $numero);
                $lineas[] = ['producto_id' => (int) $p->id, 'cantidad' => $cantidad, 'precio' => (float) $p->precio];
                $porAfectacion[$p->afectacion_igv] = ($porAfectacion[$p->afectacion_igv] ?? 0) + $cantidad * (float) $p->precio;
            }
            $total = round(array_sum(array_map(fn ($l) => $l['cantidad'] * $l['precio'], $lineas)), 2);
            $id = (int) DB::table('tienda_pedidos')->insertGetId(['institucion_id' => $inst, 'numero' => $numero, 'apoderado_id' => $apoderadoId, 'alumno_id' => $alumnoId, 'canal' => $canal,
                'total' => $total, 'forma_pago' => $formaPago, 'estado' => $formaPago === 'con_pension' ? 'pagado' : 'pendiente',
                'reservado_hasta' => $canal === 'app' && $formaPago === 'inmediato' ? now()->addHours(self::HORAS_RESERVA) : null]);
            foreach ($lineas as $l) {
                DB::table('tienda_pedido_items')->insert(['institucion_id' => $inst, 'tienda_pedido_id' => $id] + $l);
            }
            DB::table('tienda_movimientos')->where('proveedor', "pedido:{$numero}")->update(['tienda_pedido_id' => $id, 'proveedor' => null, 'registrado_por' => $usuarioId]);
            if ($alumnoId) {
                $cargos = [];
                foreach ($porAfectacion as $afectacion => $monto) {
                    $cargos[] = $this->cargo($alumnoId, (string) $afectacion, round($monto, 2), $numero);
                }
                DB::table('tienda_pedidos')->where('id', $id)->update(['cargo_id' => $cargos[0]]);
            }
            $this->auditoria->registrar('tienda_pedido_creado', 'tienda_pedidos', $id, null, ['numero' => $numero, 'canal' => $canal, 'total' => $total, 'forma_pago' => $formaPago]);

            return ['id' => $id, 'numero' => $numero, 'total' => $total, 'estado' => $formaPago === 'con_pension' ? 'pagado' : 'pendiente'];
        });
    }

    /** Descuenta el stock del producto (o de los productos del pack) sin dejarlo negativo. */
    private function descontar(object $p, int $cantidad, string $numero): void
    {
        $componentes = $p->categoria === 'pack'
            ? DB::table('tienda_pack_items')->where('pack_id', $p->id)->get(['producto_id', 'cantidad'])->map(fn ($c) => [(int) $c->producto_id, (int) $c->cantidad * $cantidad])->all()
            : [[(int) $p->id, $cantidad]];
        foreach ($componentes as [$prod, $cant]) {
            $actual = DB::table('tienda_productos')->where('id', $prod)->lockForUpdate()->first(['nombre', 'stock']);
            if ((int) $actual->stock < $cant) {
                throw ValidationException::withMessages(['items' => ["No hay stock suficiente de «{$actual->nombre}» (quedan {$actual->stock})."]]);
            }
            DB::table('tienda_productos')->where('id', $prod)->update(['stock' => (int) $actual->stock - $cant]);
            DB::table('tienda_movimientos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'producto_id' => $prod, 'tipo' => 'venta', 'cantidad' => -$cant, 'proveedor' => "pedido:{$numero}"]);
        }
    }

    private function liberar(object $p, ?int $usuarioId): void
    {
        foreach (DB::table('tienda_pedido_items')->where('tienda_pedido_id', $p->id)->get() as $i) {
            $prod = DB::table('tienda_productos')->find($i->producto_id);
            $componentes = $prod->categoria === 'pack'
                ? DB::table('tienda_pack_items')->where('pack_id', $prod->id)->get()->map(fn ($c) => [(int) $c->producto_id, (int) $c->cantidad * (int) $i->cantidad])->all()
                : [[(int) $prod->id, (int) $i->cantidad]];
            foreach ($componentes as [$id, $cant]) {
                DB::table('tienda_productos')->where('id', $id)->increment('stock', $cant);
                DB::table('tienda_movimientos')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'producto_id' => $id, 'tipo' => 'devolucion', 'cantidad' => $cant, 'tienda_pedido_id' => $p->id, 'registrado_por' => $usuarioId]);
            }
        }
        DB::table('cargos')->whereIn('id', $this->cargosDelPedido((int) $p->id))->where('pagado', 0)->update(['estado' => 'anulado']);
        DB::table('tienda_pedidos')->where('id', $p->id)->update(['estado' => 'anulado', 'reservado_hasta' => null]);
    }

    /** Los pedidos pendientes cuyo cargo ya se pagó (en línea o en caja) pasan a pagados. */
    private function sincronizarPagos(): void
    {
        foreach (DB::table('tienda_pedidos')->where('estado', 'pendiente')->whereNotNull('cargo_id')->get(['id']) as $p) {
            $cargos = DB::table('cargos')->whereIn('id', $this->cargosDelPedido((int) $p->id))->get(['estado']);
            if ($cargos->isNotEmpty() && $cargos->every(fn ($c) => in_array($c->estado, ['pagado', 'exonerado'], true))) {
                DB::table('tienda_pedidos')->where('id', $p->id)->update(['estado' => 'pagado', 'reservado_hasta' => null]);
            }
        }
    }

    /** @return list<int> */
    private function cargosDelPedido(int $pedidoId): array
    {
        $numero = DB::table('tienda_pedidos')->where('id', $pedidoId)->value('numero');

        return DB::table('cargos')->where('descripcion', 'like', "Tienda {$numero}%")->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function cargo(int $alumnoId, string $afectacion, float $monto, string $numero): int
    {
        $gravado = $afectacion === '10';
        $codigo = $gravado ? 'TIENDA-G' : 'TIENDA-E';
        $concepto = DB::table('conceptos_cobro')->where('codigo', $codigo)->value('id') ?? (int) DB::table('conceptos_cobro')->insertGetId([
            'institucion_id' => ContextoInstitucion::requerido(), 'codigo' => $codigo, 'nombre' => $gravado ? 'Tienda escolar (gravado)' : 'Tienda escolar (exonerado o inafecto)',
            'tipo' => 'otro', 'monto' => 0, 'afecto_igv' => $gravado, 'tipo_comprobante' => 'boleta',
        ]);
        $id = (int) DB::table('cargos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'alumno_id' => $alumnoId, 'concepto_cobro_id' => $concepto,
            'matricula_id' => DB::table('matriculas')->where('alumno_id', $alumnoId)->where('estado', 'matriculado')->orderByDesc('anio_escolar_id')->value('id'),
            'descripcion' => "Tienda {$numero}".($gravado ? '' : ' (no gravado)'), 'emision' => now()->toDateString(), 'vencimiento' => now()->addDays(7)->toDateString(), 'monto' => $monto]);
        DB::table('cargos')->where('id', $id)->update(['codigo_recaudacion' => GeneradorCargos::codigo($id)]);

        return $id;
    }

    private function disponible(object $p): int
    {
        if ($p->categoria !== 'pack') {
            return (int) $p->stock;
        }
        $min = DB::table('tienda_pack_items as i')->join('tienda_productos as pr', 'pr.id', '=', 'i.producto_id')->where('i.pack_id', $p->id)->selectRaw('min(floor(pr.stock / i.cantidad)) as n')->value('n');

        return (int) ($min ?? 0);
    }

    private function familia(int $alumnoId): array
    {
        return DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')->all();
    }
}
