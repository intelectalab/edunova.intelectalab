<?php

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Cobros;
use App\Modulos\PAG\Services\GeneradorCargos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 22:
| EDUN-84/85/86/87 convenios · EDUN-79/80 recaudación bancaria · EDUN-89 conciliación · EDUN-83 reportes · EDUN-199 app del personal
*/

beforeEach(function () {
    Storage::fake('objetos');
    Mail::fake();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    $db = dueno();
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->update(['predeterminado' => false]);
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->where('proveedor', 'proveedor_a')->update(['predeterminado' => true, 'activo' => true]);
});

/** Familia con cuenta y tres cargos vencidos (S/ 300 c/u). */
function familiaMorosa(): array
{
    $db = dueno();
    $inst = demoId();
    $u = $db->table('users')->insertGetId(['nombres' => 'Lidia', 'apellidos' => 'Convenio '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'lidia.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Convenio', 'nombres' => 'Lidia']);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Convenio', 'nombres' => 'Tito', 'numero_documento' => (string) random_int(70000000, 79999999)]);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre', 'es_responsable_pago' => true]);
    $pen = $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id');
    $cargos = [];
    foreach ([90, 60, 30] as $d) {
        $id = $db->table('cargos')->insertGetId(['institucion_id' => $inst, 'alumno_id' => $alumno, 'concepto_cobro_id' => $pen, 'descripcion' => "Pensión hace $d días",
            'emision' => now()->subDays($d + 20)->toDateString(), 'vencimiento' => now()->subDays($d)->toDateString(), 'monto' => 300]);
        $db->table('cargos')->where('id', $id)->update(['codigo_recaudacion' => GeneradorCargos::codigo($id)]);
        $cargos[] = $id;
    }

    return ['usuario' => User::query()->findOrFail($u), 'apoderado' => $apo, 'alumno' => $alumno, 'cargos' => $cargos];
}

function pagarS22(array $cargos): int
{
    return DB::transaction(function () use ($cargos) {
        ContextoInstitucion::fijar(demoId());

        return app(Cobros::class)->registrar($cargos, [], ['medio' => 'efectivo'], null);
    });
}

it('crea el convenio con cuotas y acta, la familia firma en la app, dirección aprueba y los pagos cubren las cuotas en orden', function () {
    $f = familiaMorosa();
    $tesoreria = personaDemo('tesoreria');
    expect(collect(enDemo($tesoreria, 'GET', "/pagos/convenios/elegibles/{$f['apoderado']}")->json('data'))->pluck('id')->all())->toBe($f['cargos']);
    $s = enDemo($tesoreria, 'POST', '/pagos/convenios/simulacion', ['total' => 900, 'cuotas' => 4, 'primera' => now()->addDays(5)->toDateString()])->assertOk();
    expect(collect($s->json('cuotas'))->sum('monto'))->toEqual(900)->and($s->json('cuotas.3.monto'))->toEqual(225);
    enDemo($tesoreria, 'POST', '/pagos/convenios', ['apoderado_id' => $f['apoderado'], 'cargos' => $f['cargos'], 'cuotas' => 13, 'primera' => now()->addDays(5)->toDateString()])->assertUnprocessable();

    $c = enDemo($tesoreria, 'POST', '/pagos/convenios', ['apoderado_id' => $f['apoderado'], 'cargos' => $f['cargos'], 'cuotas' => 3, 'primera' => now()->addDays(5)->toDateString()])->assertCreated();
    $id = $c->json('id');
    expect($c->json('estado'))->toBe('borrador')->and($c->json('monto_total'))->toEqual(900)->and($c->json('cuotas'))->toHaveCount(3)->and($c->json('acta_archivo_id'))->not->toBeNull();
    // Un cargo no puede estar en dos convenios.
    enDemo($tesoreria, 'POST', '/pagos/convenios', ['apoderado_id' => $f['apoderado'], 'cargos' => [$f['cargos'][0]], 'cuotas' => 2, 'primera' => now()->addDays(5)->toDateString()])->assertUnprocessable();
    enDemo(directorDemo(), 'POST', "/pagos/convenios/$id/aprobacion")->assertUnprocessable(); // falta la firma

    enDemo($f['usuario'], 'POST', "/familia/convenios/$id/firma", [])->assertUnprocessable()->assertJsonValidationErrors('acepto');
    enDemo($f['usuario'], 'POST', "/familia/convenios/$id/firma", ['acepto' => true])->assertOk()->assertJsonPath('firma_canal', 'app');
    enDemo($tesoreria, 'POST', "/pagos/convenios/$id/aprobacion")->assertForbidden();
    enDemo(directorDemo(), 'POST', "/pagos/convenios/$id/aprobacion")->assertOk()->assertJsonPath('estado', 'vigente');
    expect(dueno()->table('auditoria')->where('tabla', 'convenios_fraccionamiento')->where('registro_id', $id)->pluck('accion')->all())->toContain('convenio_creado', 'convenio_firmado', 'convenio_aprobado');

    // Paga S/ 350: cubre la cuota 1 (300) y parte de la 2. Los pagos van a los cargos originales.
    pagarS22([['id' => $f['cargos'][0], 'monto' => 300], ['id' => $f['cargos'][1], 'monto' => 50]]);
    $d = enDemo($f['usuario'], 'GET', '/familia/convenios')->assertOk()->json('data.0');
    expect($d['cuotas'][0]['estado'])->toBe('pagada')->and($d['cuotas'][1]['pagado'])->toEqual(50)->and($d['saldo'])->toEqual(550);
    expect(dueno()->table('cargos')->find($f['cargos'][0])->estado)->toBe('pagado');

    // La familia paga en línea la cuota 2 (250 restantes).
    $cuenta = dueno()->table('pasarela_cuentas')->where('institucion_id', demoId())->value('id')
        ?? enDemo(directorDemo(), 'POST', '/pagos/pasarela', ['proveedor' => 'culqi', 'ambiente' => 'pruebas', 'llave_secreta' => 'sk_test_x', 'medios' => ['yape']])->json('id');
    dueno()->table('pasarela_cuentas')->where('id', $cuenta)->update(['predeterminada' => true]);
    enDemo(directorDemo(), 'POST', "/pagos/pasarela/$cuenta/prueba")->assertOk();
    $o = enDemo($f['usuario'], 'POST', "/familia/convenios/$id/cuotas/2/pago")->assertCreated();
    expect($o->json('monto'))->toEqual(250);
    enDemo($f['usuario'], 'POST', '/familia/pagos/ordenes/'.$o->json('orden').'/simulacion', ['resultado' => 'pagada', 'medio' => 'yape'])->assertOk();
    $ultimo = pagarS22([['id' => $f['cargos'][2], 'monto' => 300]]);
    expect(dueno()->table('convenios_fraccionamiento')->find($id)->estado)->toBe('cumplido');

    // Revisión: anular el último pago vuelve a abrir la cuota 3 y el convenio.
    DB::transaction(function () use ($ultimo) {
        ContextoInstitucion::fijar(demoId());
        app(Cobros::class)->anular($ultimo, 'Prueba de anulación', null);
    });
    $cuota3 = dueno()->table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->where('numero', 3)->first();
    expect((float) $cuota3->pagado)->toEqual(0)->and($cuota3->estado)->toBe('pendiente')
        ->and(dueno()->table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->where('numero', 2)->value('estado'))->toBe('pagada')
        ->and(dueno()->table('convenios_fraccionamiento')->find($id)->estado)->toBe('vigente');
});

it('marca incumplido el convenio con una cuota vencida más de 30 días', function () {
    $f = familiaMorosa();
    $tesoreria = personaDemo('tesoreria');
    $id = enDemo($tesoreria, 'POST', '/pagos/convenios', ['apoderado_id' => $f['apoderado'], 'cargos' => $f['cargos'], 'cuotas' => 2, 'primera' => now()->toDateString()])->json('id');
    enDemo($f['usuario'], 'POST', "/familia/convenios/$id/firma", ['acepto' => true])->assertOk();
    enDemo(directorDemo(), 'POST', "/pagos/convenios/$id/aprobacion")->assertOk();
    dueno()->table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->where('numero', 1)->update(['vencimiento' => now()->subDays(31)->toDateString()]);
    $this->artisan('pag:convenios')->assertSuccessful();
    $c = dueno()->table('convenios_fraccionamiento')->find($id);
    expect($c->estado)->toBe('incumplido')->and($c->incumplido_at)->not->toBeNull()
        ->and(dueno()->table('convenio_cuotas')->where('convenio_fraccionamiento_id', $id)->where('numero', 1)->value('estado'))->toBe('vencida');
});

it('genera el archivo de deudas y concilia el archivo de pagos del banco con sus observaciones', function () {
    $f = familiaMorosa();
    $tesoreria = personaDemo('tesoreria');
    actuarComo($tesoreria, true, demoId());
    $r = $this->post('/api/v1/pagos/recaudacion/deudas', ['banco' => 'BCP'], ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'])->assertOk();
    $codigos = array_map(fn ($c) => dueno()->table('cargos')->where('id', $c)->value('codigo_recaudacion'), $f['cargos']);
    expect($r->getContent())->toContain($codigos[0].'|')->toContain('|300.00');

    $hoy = now()->format('Ymd');
    $archivo = implode("\n", ["{$codigos[0]}|300.00|$hoy|OP1", "{$codigos[1]}|250.00|$hoy|OP2", "0000000000|100.00|$hoy|OP3", "{$codigos[0]}|300.00|$hoy|OP4"]);
    $ruta = tempnam(sys_get_temp_dir(), 'bcp');
    file_put_contents($ruta, $archivo);
    $p = enDemo($tesoreria, 'POST', '/pagos/recaudacion/pagos', ['banco' => 'BCP', 'archivo' => new UploadedFile($ruta, 'pagos.txt', 'text/plain', null, true)])->assertCreated();
    expect($p->json())->toMatchArray(['lineas' => 4, 'conciliado' => 1, 'monto_distinto' => 1, 'sin_coincidencia' => 1, 'duplicado' => 1]);
    expect(dueno()->table('cargos')->find($f['cargos'][0])->estado)->toBe('pagado')->and(dueno()->table('cargos')->find($f['cargos'][1])->estado)->toBe('pendiente');
    $pago = dueno()->table('pagos')->where('medio', 'recaudacion')->latest('id')->first();
    expect($pago->recaudacion_linea_id)->not->toBeNull()->and(dueno()->table('comprobantes')->where('pago_id', $pago->id)->value('estado_sunat'))->toBe('aceptado');
    expect(collect(enDemo($tesoreria, 'GET', '/pagos/recaudacion/'.$p->json('id'))->json('data'))->pluck('resultado')->all())->toBe(['conciliado', 'monto_distinto', 'sin_coincidencia', 'duplicado']);
});

it('concilia los pagos en línea con el reporte de abonos y arma los reportes financieros', function () {
    $f = familiaMorosa();
    $db = dueno();
    $cuenta = $db->table('pasarela_cuentas')->where('institucion_id', demoId())->value('id') ?? $db->table('pasarela_cuentas')->insertGetId(['institucion_id' => demoId(), 'proveedor' => 'culqi', 'credenciales_cifradas' => 'x']);
    foreach ([['ORD-A', 300, 10.82], ['ORD-B', 200, 7.38], ['ORD-C', 150, 5.66]] as [$orden, $m, $com]) {
        $db->table('pasarela_transacciones')->where('orden', $orden.'-S22')->delete();
        $db->table('pasarela_transacciones')->insert(['institucion_id' => demoId(), 'pasarela_cuenta_id' => $cuenta, 'proveedor' => 'culqi', 'orden' => $orden.'-S22', 'monto' => $m, 'comision' => $com,
            'neto' => $m - $com, 'estado' => 'pagada', 'created_at' => now()->subDays(5)]);
    }
    $csv = "orden,monto,comision,neto,fecha_abono\nORD-A-S22,300,10.82,289.18,".now()->toDateString()."\nORD-B-S22,199,7.38,191.62,".now()->toDateString()."\nORD-X-S22,50,2,48,".now()->toDateString();
    $ruta = tempnam(sys_get_temp_dir(), 'abonos');
    file_put_contents($ruta, $csv);
    $tesoreria = personaDemo('tesoreria');
    $r = enDemo($tesoreria, 'POST', '/pagos/conciliacion', ['reporte' => new UploadedFile($ruta, 'abonos.csv', 'text/csv', null, true)])->assertOk();
    expect($r->json('conciliado'))->toBe(1)->and($r->json('monto_distinto'))->toBe(1)->and($r->json('sin_registro'))->toBe(1)->and($r->json('no_abonado'))->toBeGreaterThanOrEqual(1);
    expect($db->table('pasarela_transacciones')->where('orden', 'ORD-C-S22')->value('conciliacion'))->toBe('no_abonado');
    expect(enDemo($tesoreria, 'GET', '/pagos/conciliacion')->assertOk()->json('totales.comision'))->toBeGreaterThan(0);

    pagarS22([['id' => $f['cargos'][0], 'monto' => 300]]);
    $rep = enDemo(directorDemo(), 'GET', '/pagos/reportes?anio='.now()->year)->assertOk();
    expect($rep->json('total'))->toBeGreaterThanOrEqual(300)->and(collect($rep->json('por_concepto'))->pluck('concepto')->all())->toContain('Pensión mensual')
        ->and($rep->json('proyeccion'))->not->toBeEmpty();
    actuarComo(directorDemo(), true, demoId());
    $this->get('/api/v1/pagos/reportes/excel?anio='.now()->year, ['X-Institucion' => (string) demoId()])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('el conductor inicia el recorrido, envía su ubicación y marca subidas con aviso; enfermería registra una atención rápida', function () {
    $db = dueno();
    $inst = demoId();
    $f = familiaMorosa();
    $db->table('dispositivos_moviles')->insert(['user_id' => $f['usuario']->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $conductor = personaDemo('conductor');
    $cid = $db->table('mov_conductores')->insertGetId(['institucion_id' => $inst, 'nombres' => 'Conductor Prueba', 'numero_documento' => (string) random_int(10000000, 99999999), 'rol' => 'conductor', 'user_id' => $conductor->id]);
    $ruta = $db->table('mov_rutas')->insertGetId(['institucion_id' => $inst, 'nombre' => 'Ruta Cayma '.uniqid(), 'turno' => 'manana', 'conductor_id' => $cid]);
    $paradero = $db->table('mov_paraderos')->insertGetId(['institucion_id' => $inst, 'mov_ruta_id' => $ruta, 'nombre' => 'Parque', 'orden' => 1]);
    $anio = $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $asig = $db->table('mov_asignaciones')->insertGetId(['institucion_id' => $inst, 'alumno_id' => $f['alumno'], 'mov_ruta_id' => $ruta, 'mov_paradero_id' => $paradero, 'anio_escolar_id' => $anio]);

    $rutas = enDemo($conductor, 'GET', '/movilidad/mis-rutas')->assertOk();
    expect(collect($rutas->json('data'))->firstWhere('id', $ruta)['alumnos'][0]['asignacion_id'])->toBe($asig);
    enDemo(personaDemo('conductor'), 'POST', "/movilidad/rutas/$ruta/recorridos", ['sentido' => 'ida'])->assertForbidden();
    $rec = enDemo($conductor, 'POST', "/movilidad/rutas/$ruta/recorridos", ['sentido' => 'ida'])->assertCreated()->json('recorrido_id');
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/posiciones", ['posiciones' => [['latitud' => -16.38, 'longitud' => -71.54], ['latitud' => -16.381, 'longitud' => -71.541]]])->assertOk()->assertJson(['guardadas' => 2]);
    expect($db->table('mov_recorridos')->find($rec)->ultima_latitud)->not->toBeNull();
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/eventos", ['asignacion_id' => $asig, 'tipo' => 'subio'])->assertCreated();
    expect($db->table('mov_eventos')->where('mov_recorrido_id', $rec)->value('tipo'))->toBe('subio');
    Http::assertSent(fn ($req) => str_contains((string) ($req->data()[0]['body'] ?? ''), 'subió a la movilidad'));
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/fin")->assertOk();
    // Con el recorrido finalizado ya no se guarda la ubicación (privacidad).
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/posiciones", ['posiciones' => [['latitud' => -16.38, 'longitud' => -71.54]]])->assertUnprocessable();

    $enfermera = personaDemo('enfermeria');
    expect(enDemo($enfermera, 'GET', '/salud/alumnos?q=Convenio')->assertOk()->json('data'))->not->toBeEmpty();
    $a = enDemo($enfermera, 'POST', '/salud/atenciones', ['alumno_id' => $f['alumno'], 'motivo' => 'Dolor de cabeza', 'accion' => 'Descanso y agua', 'avisar_padre' => true])->assertCreated();
    expect($db->table('salud.atenciones')->find($a->json('id'))->padre_notificado_at)->not->toBeNull();
    enDemo(personaDemo('docente'), 'POST', '/salud/atenciones', ['alumno_id' => $f['alumno'], 'motivo' => 'X', 'accion' => 'Y'])->assertForbidden();
});
