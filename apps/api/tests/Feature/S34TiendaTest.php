<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| S34 · Tienda escolar: EDUN-179 productos y packs · 180 lista de útiles · 181 pedido desde la app
| (reserva 48 h, en línea o con la pensión) · 182 venta en caja con boleta · 183 entrega ·
| 184 inventario sin stock negativo · 185 reportes.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    Mail::fake();
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $m = $db->table('modulos_catalogo')->where('codigo', 'TIE')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->update(['predeterminado' => false]);
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->where('proveedor', 'proveedor_a')->update(['predeterminado' => true, 'activo' => true]);
});

function familiaTienda(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->first();
    $seccion = $db->table('secciones')->where('anio_escolar_id', $anio->id)->orderBy('id')->first();
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Tienda'.uniqid(), 'nombres' => 'Noa', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio->id, 'seccion_id' => $seccion->id, 'origen' => 'manual', 'estado' => 'matriculado']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Rita', 'apellidos' => 'Tienda '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'rt.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Tienda', 'nombres' => 'Rita']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre', 'es_responsable_pago' => true]);

    return ['alumno' => $alumno, 'familia' => User::query()->findOrFail($u), 'grado' => $seccion->grado_id, 'anio' => $anio->id];
}

/** Dos útiles con stock y un pack que los contiene. */
function catalogoTienda(User $tienda): array
{
    $c = uniqid();
    $cuaderno = enDemo($tienda, 'POST', '/tienda/productos', ['codigo' => "CU{$c}", 'nombre' => 'Cuaderno', 'categoria' => 'util', 'precio' => 5])->assertCreated()->json('id');
    $libro = enDemo($tienda, 'POST', '/tienda/productos', ['codigo' => "LI{$c}", 'nombre' => 'Libro de texto', 'categoria' => 'texto', 'precio' => 40, 'afectacion_igv' => '20'])->assertCreated()->json('id');
    enDemo($tienda, 'POST', "/tienda/productos/{$cuaderno}/movimientos", ['tipo' => 'ingreso', 'cantidad' => 10, 'costo_unitario' => 3, 'proveedor' => 'Distribuidora'])->assertOk();
    enDemo($tienda, 'POST', "/tienda/productos/{$libro}/movimientos", ['tipo' => 'ingreso', 'cantidad' => 3])->assertOk();
    $pack = enDemo($tienda, 'POST', '/tienda/productos', ['codigo' => "PK{$c}", 'nombre' => 'Pack 3.°', 'categoria' => 'pack', 'precio' => 50, 'contenido' => [['producto_id' => $cuaderno, 'cantidad' => 2], ['producto_id' => $libro, 'cantidad' => 1]]])->assertCreated();
    expect($pack->json('disponible'))->toBe(3)->and($pack->json('contenido'))->toHaveCount(2);

    return ['cuaderno' => $cuaderno, 'libro' => $libro, 'pack' => $pack->json('id')];
}

it('EDUN-179 / 184 · productos, pack y movimientos de inventario sin stock negativo', function () {
    $tienda = personaDemo('tienda');
    $p = catalogoTienda($tienda);
    enDemo($tienda, 'POST', "/tienda/productos/{$p['cuaderno']}/movimientos", ['tipo' => 'ajuste', 'cantidad' => -20, 'motivo' => 'Merma'])->assertUnprocessable();
    expect(enDemo($tienda, 'POST', "/tienda/productos/{$p['cuaderno']}/movimientos", ['tipo' => 'ajuste', 'cantidad' => -1, 'motivo' => 'Dañado'])->assertOk()->json('stock'))->toBe(9);
    expect(enDemo($tienda, 'GET', "/tienda/productos/{$p['cuaderno']}/kardex")->assertOk()->json('data'))->toHaveCount(2);
    enDemo($tienda, 'POST', "/tienda/productos/{$p['pack']}/movimientos", ['tipo' => 'ingreso', 'cantidad' => 5])->assertUnprocessable();
    enDemo(personaDemo('docente'), 'POST', '/tienda/productos', [])->assertForbidden();
});

it('EDUN-180 / 181 / 183 · lista de útiles publicada, pedido de la app con reserva de stock, pago y entrega', function () {
    $tienda = personaDemo('tienda');
    $p = catalogoTienda($tienda);
    $f = familiaTienda();
    $lista = enDemo($tienda, 'PUT', '/tienda/listas', ['grado_id' => $f['grado'], 'anio_escolar_id' => $f['anio'], 'pack_id' => $p['pack'], 'items' => [['descripcion' => 'Cuaderno cuadriculado', 'cantidad' => 2, 'producto_id' => $p['cuaderno']]]])->assertOk();
    enDemo($tienda, 'POST', "/tienda/listas/{$lista->json('id')}/publicacion")->assertOk();
    $cat = enDemo($f['familia'], 'GET', '/tienda/familia')->assertOk();
    expect($cat->json('aviso'))->toContain('opcional')->and(collect($cat->json('listas'))->pluck('id'))->toContain($lista->json('id'));

    // Pedido en línea: reserva el stock; el libro va como no gravado (IGV del producto).
    $ped = enDemo($f['familia'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => $f['alumno'], 'forma_pago' => 'inmediato', 'items' => [['producto_id' => $p['pack'], 'cantidad' => 1]]])->assertCreated();
    expect($ped->json('estado'))->toBe('pendiente')->and($ped->json('total'))->toEqual(50);
    expect(dueno()->table('tienda_productos')->find($p['cuaderno'])->stock)->toBe(8)->and(dueno()->table('tienda_productos')->find($p['libro'])->stock)->toBe(2);
    $cargos = dueno()->table('cargos')->where('descripcion', 'like', "Tienda {$ped->json('numero')}%")->get();
    expect($cargos)->toHaveCount(1)->and((float) $cargos[0]->monto)->toBe(50.0);
    enDemo($f['familia'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => $f['alumno'], 'forma_pago' => 'inmediato', 'items' => [['producto_id' => $p['libro'], 'cantidad' => 5]]])->assertUnprocessable();

    // Sin pagar no se prepara; pagado (en línea o en caja), se prepara y se entrega.
    enDemo($tienda, 'POST', "/tienda/pedidos/{$ped->json('id')}/estado", ['accion' => 'preparar'])->assertUnprocessable();
    dueno()->table('cargos')->where('id', $cargos[0]->id)->update(['estado' => 'pagado', 'pagado' => 50]);
    enDemo($tienda, 'POST', "/tienda/pedidos/{$ped->json('id')}/estado", ['accion' => 'preparar'])->assertOk()->assertJsonPath('estado', 'preparado');
    enDemo($tienda, 'POST', "/tienda/pedidos/{$ped->json('id')}/estado", ['accion' => 'entregar'])->assertUnprocessable();
    enDemo($tienda, 'POST', "/tienda/pedidos/{$ped->json('id')}/estado", ['accion' => 'entregar', 'entregado_a' => 'Rita (madre)'])->assertOk()->assertJsonPath('estado', 'entregado');

    // Con la pensión: queda pagado (cargo voluntario en el estado de cuenta).
    $pen = enDemo($f['familia'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => $f['alumno'], 'forma_pago' => 'con_pension', 'items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 2], ['producto_id' => $p['libro'], 'cantidad' => 1]]])->assertCreated();
    expect($pen->json('estado'))->toBe('pagado')->and(dueno()->table('cargos')->where('descripcion', 'like', "Tienda {$pen->json('numero')}%")->count())->toBe(2);

    // Sin pagar a las 48 horas: se anula y el stock vuelve.
    $otra = enDemo($f['familia'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => $f['alumno'], 'forma_pago' => 'inmediato', 'items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 3]]])->assertCreated();
    $antes = dueno()->table('tienda_productos')->find($p['cuaderno'])->stock;
    dueno()->table('tienda_pedidos')->where('id', $otra->json('id'))->update(['reservado_hasta' => now()->subHour()]);
    Artisan::call('tie:vencidos');
    expect(dueno()->table('tienda_pedidos')->find($otra->json('id'))->estado)->toBe('anulado')
        ->and(dueno()->table('tienda_productos')->find($p['cuaderno'])->stock)->toBe($antes + 3)
        ->and(dueno()->table('cargos')->where('descripcion', 'like', "Tienda {$otra->json('numero')}%")->value('estado'))->toBe('anulado');
    enDemo($f['familia'], 'POST', '/tienda/familia/pedidos', ['alumno_id' => familiaTienda()['alumno'], 'forma_pago' => 'inmediato', 'items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 1]]])->assertForbidden();
});

it('EDUN-182 / 185 · venta en caja con boleta (con alumno) y venta libre; reportes', function () {
    $tienda = personaDemo('tienda');
    $p = catalogoTienda($tienda);
    $f = familiaTienda();
    $t = personaDemo('tesoreria');
    enDemo($t, 'POST', '/tienda/ventas', ['items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 1]], 'medios' => [['medio' => 'efectivo', 'monto' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('caja');
    $caja = dueno()->table('cajas')->insertGetId(['institucion_id' => demoId(), 'nombre' => 'Caja tienda '.uniqid()]);
    enDemo($t, 'POST', '/pagos/caja/abrir', ['caja_id' => $caja, 'monto_inicial' => 50])->assertCreated();
    $v = enDemo($t, 'POST', '/tienda/ventas', ['alumno_id' => $f['alumno'], 'items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 2], ['producto_id' => $p['libro'], 'cantidad' => 1]], 'medios' => [['medio' => 'efectivo', 'monto' => 50]]])->assertCreated();
    expect($v->json('total'))->toEqual(50)->and($v->json('comprobante_id'))->not->toBeNull();
    $items = dueno()->table('comprobante_items')->where('comprobante_id', $v->json('comprobante_id'))->get();
    expect($items)->toHaveCount(2);
    $libre = enDemo($t, 'POST', '/tienda/ventas', ['items' => [['producto_id' => $p['cuaderno'], 'cantidad' => 1]], 'medios' => [['medio' => 'efectivo', 'monto' => 5]]])->assertCreated();
    expect($libre->json('comprobante_id'))->toBeNull();

    $r = enDemo($tienda, 'GET', '/tienda/reportes')->assertOk();
    expect($r->json('ventas'))->toBeGreaterThanOrEqual(55)->and(collect($r->json('mas_vendidos'))->pluck('nombre'))->toContain('Cuaderno');
});
