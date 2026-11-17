<?php

namespace App\Modulos\TIE\Http\Controllers;

use App\Modulos\PAG\Services\Caja;
use App\Modulos\PAG\Services\PagosEnLinea;
use App\Modulos\SEG\Http\Controllers\FamiliaController;
use App\Modulos\TIE\Services\Tienda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** S34 · Tienda escolar (EDUN-179 a 185). */
class TiendaController
{
    public function __construct(private Tienda $tienda) {}

    public function productos(): JsonResponse
    {
        return response()->json(['data' => $this->tienda->productos()]);
    }

    public function guardarProducto(Request $request, ?int $producto = null): JsonResponse
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:20'], 'nombre' => ['required', 'string', 'min:2', 'max:150'], 'categoria' => ['required', Rule::in(Tienda::CATEGORIAS)],
            'nivel' => ['nullable', Rule::in(['inicial', 'primaria', 'secundaria', 'todos'])], 'precio' => ['required', 'numeric', 'between:0,99999'],
            'afectacion_igv' => ['nullable', Rule::in(['10', '20', '30'])], 'stock_minimo' => ['nullable', 'integer', 'min:0'], 'visible_app' => ['boolean'], 'activo' => ['boolean'],
            'contenido' => ['array'], 'contenido.*.producto_id' => ['required', 'integer'], 'contenido.*.cantidad' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->tienda->guardarProducto($producto, $d), $producto ? 200 : 201);
    }

    public function movimiento(Request $request, int $producto): JsonResponse
    {
        $d = $request->validate(['tipo' => ['required', Rule::in(['ingreso', 'ajuste'])], 'cantidad' => ['required', 'integer', 'not_in:0'], 'costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'proveedor' => ['nullable', 'string', 'max:150'], 'motivo' => ['nullable', 'string', 'max:150', 'required_if:tipo,ajuste']]);

        return response()->json($this->tienda->movimiento($producto, $d['tipo'], (int) $d['cantidad'], isset($d['costo_unitario']) ? (float) $d['costo_unitario'] : null, $d['proveedor'] ?? null, $d['motivo'] ?? null, (int) $request->user()->id));
    }

    public function kardex(int $producto): JsonResponse
    {
        return response()->json(['data' => $this->tienda->kardex($producto)]);
    }

    public function listas(Request $request): JsonResponse
    {
        $d = $request->validate(['anio_escolar_id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->tienda->listas($d['anio_escolar_id'] ?? null),
            'anios' => DB::table('anios_escolares')->orderByDesc('anio')->get(['id', 'anio', 'estado']), 'grados' => DB::table('grados')->orderBy('nivel')->orderBy('numero')->get(['id', 'nombre', 'nivel'])]);
    }

    public function guardarLista(Request $request): JsonResponse
    {
        $d = $request->validate(['grado_id' => ['required', 'integer'], 'anio_escolar_id' => ['required', 'integer'], 'pack_id' => ['nullable', 'integer'], 'entrega_gradual' => ['boolean'],
            'items' => ['array', 'max:80'], 'items.*.descripcion' => ['required', 'string', 'max:150'], 'items.*.cantidad' => ['nullable', 'integer', 'min:1'], 'items.*.area' => ['nullable', 'string', 'max:60'], 'items.*.producto_id' => ['nullable', 'integer']]);

        return response()->json($this->tienda->guardarLista((int) $d['grado_id'], (int) $d['anio_escolar_id'], $d['pack_id'] ?? null, (bool) ($d['entrega_gradual'] ?? true), $d['items'] ?? []));
    }

    public function publicarLista(int $lista): JsonResponse
    {
        $this->tienda->publicarLista($lista);

        return response()->json(['ok' => true]);
    }

    public function pedidos(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tienda->pedidos($request->validate(['estado' => ['nullable', Rule::in(['pendiente', 'pagado', 'preparado', 'entregado', 'anulado'])]])['estado'] ?? null)]);
    }

    public function mover(Request $request, int $pedido): JsonResponse
    {
        $d = $request->validate(['accion' => ['required', Rule::in(['preparar', 'entregar', 'anular'])], 'entregado_a' => ['nullable', 'string', 'max:150']]);

        return response()->json($this->tienda->mover($pedido, $d['accion'], $d['entregado_a'] ?? null, (int) $request->user()->id));
    }

    public function vender(Request $request, Caja $caja): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['nullable', 'integer'], 'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.producto_id' => ['required', 'integer'], 'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'medios' => ['array'], 'medios.*.medio' => ['required', 'string'], 'medios.*.monto' => ['required', 'numeric', 'min:0'], 'cliente' => ['array']]);

        return response()->json($this->tienda->venderEnCaja($d['alumno_id'] ?? null, $d['items'], $d['medios'] ?? [], $d['cliente'] ?? [], (int) $request->user()->id, $caja), 201);
    }

    public function reportes(Request $request): JsonResponse
    {
        $d = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->tienda->reportes($d['desde'] ?? null, $d['hasta'] ?? null));
    }

    // ─── Familia (la app)

    public function catalogoFamilia(Request $request): JsonResponse
    {
        $hijos = FamiliaController::alumnosDe((int) $request->user()->id);
        // La lista de útiles del grado y del año de la matrícula de cada hijo.
        $matriculas = DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->whereIn('m.alumno_id', $hijos ?: [0])->where('m.estado', 'matriculado')
            ->get(['s.grado_id', 'm.anio_escolar_id']);
        $listas = $matriculas->pluck('anio_escolar_id')->unique()->flatMap(fn ($anio) => collect($this->tienda->listas((int) $anio))
            ->filter(fn ($l) => $l['publicada_at'] && $matriculas->contains(fn ($m) => (int) $m->grado_id === (int) $l['grado_id'] && (int) $m->anio_escolar_id === (int) $anio)))->values();

        return response()->json(['productos' => $this->tienda->productos(true), 'listas' => $listas,
            'pedidos' => $this->tienda->pedidos(null, $hijos), 'aviso' => 'Comprar en la tienda del colegio es opcional.']);
    }

    public function pedir(Request $request): JsonResponse
    {
        $d = $request->validate(['alumno_id' => ['required', 'integer'], 'forma_pago' => ['required', Rule::in(['inmediato', 'con_pension'])], 'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.producto_id' => ['required', 'integer'], 'items.*.cantidad' => ['required', 'integer', 'between:1,20']]);
        abort_unless(in_array((int) $d['alumno_id'], FamiliaController::alumnosDe((int) $request->user()->id), true), 403, 'Solo compras para tus hijos.');

        return response()->json($this->tienda->pedirDesdeApp((int) $d['alumno_id'], $d['items'], $d['forma_pago'], $request->user()), 201);
    }

    public function pagar(Request $request, int $pedido, PagosEnLinea $pagos): JsonResponse
    {
        return response()->json($this->tienda->pagarEnLinea($pedido, FamiliaController::alumnosDe((int) $request->user()->id), $request->user(), $pagos));
    }
}
