<?php

use App\Models\User;
use App\Modulos\PAG\Pasarela\PasarelaSimulada;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 17 · Cobranza:
| EDUN-75 cobrar en caja · EDUN-76 cierre con arqueo · EDUN-77 comprobante electrónico · EDUN-78 nota de crédito
| EDUN-88 cuenta de pasarela · EDUN-73 pagar en línea · EDUN-74 webhook
*/

beforeEach(function () {
    Storage::fake('objetos');
    Mail::fake();
    $db = dueno();
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->update(['predeterminado' => false]);
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->where('proveedor', 'proveedor_a')
        ->update(['predeterminado' => true, 'activo' => true, 'serie_nota_credito' => 'BC01']);
});

/** Alumno del demo con un apoderado que tiene usuario, y cargos propios (dos emitidos y uno futuro). */
function familiaConDeuda(): array
{
    $db = dueno();
    $inst = demoId();
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Caja', 'nombres' => 'Alumno '.uniqid(), 'numero_documento' => (string) random_int(70000000, 79999999)]);
    $userId = $db->table('users')->insertGetId(['nombres' => 'Rosa', 'apellidos' => 'Caja '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999),
        'email' => 'rosa.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $userId, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apoderado = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $userId, 'numero_documento' => (string) random_int(40000000, 49999999),
        'apellidos' => 'Caja', 'nombres' => 'Rosa', 'email' => 'rosa.'.uniqid().'@familia.prueba']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apoderado, 'parentesco' => 'madre', 'es_responsable_pago' => true]);
    $pension = $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id');
    $cargo = fn (string $desc, string $emision, string $vence, float $monto = 350) => $db->table('cargos')->insertGetId([
        'institucion_id' => $inst, 'alumno_id' => $alumno, 'concepto_cobro_id' => $pension, 'descripcion' => $desc, 'emision' => $emision, 'vencimiento' => $vence, 'monto' => $monto,
    ]);

    return [
        'alumno' => $alumno, 'apoderado' => $apoderado, 'usuario' => User::query()->findOrFail($userId),
        'vencido' => $cargo('Pensión setiembre', '2026-09-01', '2026-09-30'), 'actual' => $cargo('Pensión octubre', '2026-10-01', '2026-10-31'),
        'futuro' => $cargo('Pensión diciembre', '2026-12-01', '2026-12-31'),
    ];
}

function cajeroConCajaAbierta(): User
{
    $t = personaDemo('tesoreria');
    $caja = dueno()->table('cajas')->insertGetId(['institucion_id' => demoId(), 'nombre' => 'Caja '.uniqid()]);
    enDemo($t, 'POST', '/pagos/caja/abrir', ['caja_id' => $caja, 'monto_inicial' => 100])->assertCreated();

    return $t;
}

it('cobra en caja con varios medios, emite la boleta electrónica y cierra con arqueo justificado', function () {
    $f = familiaConDeuda();
    $t = personaDemo('tesoreria');
    enDemo($t, 'POST', '/pagos/caja/cobros', ['cargos' => [['id' => $f['vencido'], 'monto' => 350]], 'medios' => [['medio' => 'efectivo', 'monto' => 350]], 'tipo_comprobante' => '03'])
        ->assertUnprocessable()->assertJsonValidationErrors('caja');

    $caja = dueno()->table('cajas')->insertGetId(['institucion_id' => demoId(), 'nombre' => 'Ventanilla '.uniqid()]);
    enDemo($t, 'POST', '/pagos/caja/abrir', ['caja_id' => $caja, 'monto_inicial' => 100])->assertCreated();
    enDemo(personaDemo('tesoreria'), 'POST', '/pagos/caja/abrir', ['caja_id' => $caja, 'monto_inicial' => 0])->assertUnprocessable();

    $busqueda = enDemo($t, 'GET', '/pagos/caja/buscar?q='.dueno()->table('alumnos')->where('id', $f['alumno'])->value('numero_documento'))->assertOk();
    expect($busqueda->json('data.0.cargos'))->toHaveCount(3)->and($busqueda->json('data.0.apoderados.0.id'))->toBe($f['apoderado']);

    $cobro = ['cargos' => [['id' => $f['vencido'], 'monto' => 350], ['id' => $f['actual'], 'monto' => 150]], 'apoderado_id' => $f['apoderado'], 'tipo_comprobante' => '03',
        'cliente' => ['numero_doc' => '45678912', 'nombre' => 'Rosa Caja', 'email' => 'rosa@familia.prueba']];
    enDemo($t, 'POST', '/pagos/caja/cobros', $cobro + ['medios' => [['medio' => 'efectivo', 'monto' => 300]]])->assertUnprocessable()->assertJsonValidationErrors('medios');
    $r = enDemo($t, 'POST', '/pagos/caja/cobros', $cobro + ['medios' => [['medio' => 'efectivo', 'monto' => 200], ['medio' => 'yape', 'monto' => 300, 'referencia' => 'OP-123']]])->assertCreated();

    $db = dueno();
    expect($db->table('cargos')->find($f['vencido'])->estado)->toBe('pagado')
        ->and($db->table('cargos')->find($f['actual'])->estado)->toBe('parcial')
        ->and($db->table('pagos')->find($r->json('pago_id'))->medio)->toBe('mixto');
    $c = $db->table('comprobantes')->find($r->json('comprobante_id'));
    expect($c->tipo)->toBe('03')->and($c->serie)->toBe('B001')->and($c->estado_sunat)->toBe('aceptado')->and((float) $c->total)->toBe(500.0)
        ->and((float) $c->exonerado)->toBe(500.0)->and($c->cliente_tipo_doc)->toBe('1')
        ->and($c->xml_archivo_id)->not->toBeNull()->and($c->pdf_archivo_id)->not->toBeNull()->and($c->correo_enviado_at)->not->toBeNull();
    expect(Storage::disk('objetos')->get($db->table('archivos')->find($c->xml_archivo_id)->ruta))->toContain('<cbc:ID>B001-'.$c->numero.'</cbc:ID>');
    expect(json_decode($c->respuesta_proveedor, true))->toHaveKey('hash');

    // Arqueo: efectivo esperado = 100 inicial + 200 cobrados; Yape 300.
    $estado = enDemo($t, 'GET', '/pagos/caja')->assertOk();
    expect($estado->json('sesion.efectivo_esperado'))->toEqual(300)->and($estado->json('sesion.por_medio.yape'))->toEqual(300);
    enDemo($t, 'POST', '/pagos/caja/cerrar', ['contado' => ['efectivo' => 290, 'yape' => 300]])->assertUnprocessable()->assertJsonValidationErrors('justificacion');
    $cierre = enDemo($t, 'POST', '/pagos/caja/cerrar', ['contado' => ['efectivo' => 290, 'yape' => 300], 'justificacion' => 'Vuelto mal entregado a un apoderado'])->assertOk();
    expect($cierre->json('diferencia'))->toEqual(-10)->and($cierre->json('arqueo.efectivo.diferencia'))->toEqual(-10);
    $this->get('/api/v1/pagos/caja/sesiones/'.$cierre->json('sesion_id').'/pdf', ['X-Institucion' => (string) demoId()])->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('si el proveedor no responde el comprobante queda pendiente con su error, y la boleta grande pide documento', function () {
    $f = familiaConDeuda();
    $t = cajeroConCajaAbierta();
    $base = ['cargos' => [['id' => $f['vencido'], 'monto' => 350]], 'medios' => [['medio' => 'efectivo', 'monto' => 350]], 'tipo_comprobante' => '03'];

    enDemo($t, 'POST', '/pagos/caja/cobros', ['tipo_comprobante' => '01', 'cliente' => ['numero_doc' => '45678912', 'nombre' => 'X']] + $base)
        ->assertUnprocessable()->assertJsonValidationErrors('cliente.numero_doc');
    $r = enDemo($t, 'POST', '/pagos/caja/cobros', $base + ['cliente' => ['numero_doc' => '', 'nombre' => 'FALLA-PROVEEDOR']])->assertCreated();
    $c = dueno()->table('comprobantes')->find($r->json('comprobante_id'));
    expect($c->estado_sunat)->toBe('pendiente')->and($c->intentos)->toBe(1)->and($c->ultimo_error)->toContain('no respondió')
        ->and($c->siguiente_intento_at)->not->toBeNull()->and($c->cliente_numero_doc)->toBe('-');
    // El cobro no se pierde aunque falle el proveedor.
    expect(dueno()->table('cargos')->find($f['vencido'])->estado)->toBe('pagado');

    $grande = familiaConDeuda();
    dueno()->table('cargos')->where('id', $grande['vencido'])->update(['monto' => 800]);
    enDemo($t, 'POST', '/pagos/caja/cobros', ['cargos' => [['id' => $grande['vencido'], 'monto' => 800]], 'medios' => [['medio' => 'tarjeta', 'monto' => 800]], 'tipo_comprobante' => '03'])
        ->assertUnprocessable()->assertJsonValidationErrors('cliente.numero_doc');
});

it('la nota de crédito exige motivo, referencia la boleta y revierte el pago', function () {
    $f = familiaConDeuda();
    $t = cajeroConCajaAbierta();
    $r = enDemo($t, 'POST', '/pagos/caja/cobros', ['cargos' => [['id' => $f['vencido'], 'monto' => 350]], 'medios' => [['medio' => 'efectivo', 'monto' => 350]],
        'tipo_comprobante' => '03', 'cliente' => ['numero_doc' => '45678912', 'nombre' => 'Rosa Caja']])->assertCreated();
    $boleta = $r->json('comprobante_id');

    enDemo($t, 'POST', "/pagos/comprobantes/$boleta/anulacion", ['motivo' => 'Error de digitación'])->assertUnprocessable();
    enDemo($t, 'POST', "/pagos/comprobantes/$boleta/nota-credito", [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
    $nc = enDemo($t, 'POST', "/pagos/comprobantes/$boleta/nota-credito", ['motivo' => 'Cobro duplicado'])->assertCreated();
    $nota = dueno()->table('comprobantes')->find($nc->json('id'));
    expect($nota->tipo)->toBe('07')->and($nota->serie)->toBe('BC01')->and((int) $nota->comprobante_ref_id)->toBe($boleta)
        ->and($nota->motivo)->toBe('Cobro duplicado')->and($nota->estado_sunat)->toBe('aceptado');
    expect(dueno()->table('cargos')->find($f['vencido']))->estado->toBe('pendiente')->pagado->toEqual(0)
        ->and(dueno()->table('pagos')->find($r->json('pago_id'))->estado)->toBe('anulado');
    enDemo($t, 'POST', "/pagos/comprobantes/$boleta/nota-credito", ['motivo' => 'Otra vez'])->assertUnprocessable();
    expect(collect(enDemo($t, 'GET', '/pagos/comprobantes')->json('data'))->firstWhere('id', $boleta)['tiene_nota'])->toBeTrue();
});

it('el director conecta la pasarela en pruebas y la familia paga en línea; el webhook firmado aplica el pago una sola vez', function () {
    dueno()->table('pasarela_cuentas')->where('institucion_id', demoId())->delete();
    $f = familiaConDeuda();
    $director = directorDemo();

    enDemo($director, 'POST', '/pagos/pasarela', ['proveedor' => 'culqi', 'ambiente' => 'pruebas', 'medios' => []])->assertUnprocessable();
    $c = enDemo($director, 'POST', '/pagos/pasarela', ['proveedor' => 'culqi', 'ambiente' => 'pruebas', 'llave_publica' => 'pk_test_1', 'llave_secreta' => 'sk_test_abc', 'medios' => ['tarjeta', 'yape']])->assertCreated();
    $cuenta = collect($c->json('data'))->first();
    expect($cuenta['estado'])->toBe('pendiente')->and($cuenta['webhook_url'])->toContain('/api/v1/pasarela/webhook/')->and(json_encode($c->json()))->not->toContain('sk_test_abc');

    // Sin cuenta activa la familia no puede pagar.
    enDemo($f['usuario'], 'POST', '/familia/pagos/ordenes', ['cargos' => [$f['vencido']]])->assertUnprocessable()->assertJsonValidationErrors('pasarela');
    expect(enDemo($director, 'POST', "/pagos/pasarela/{$cuenta['id']}/prueba")->assertOk()->json('ok'))->toBeTrue();

    $mis = enDemo($f['usuario'], 'GET', '/familia/pagos')->assertOk();
    expect(collect($mis->json('cargos'))->pluck('id')->all())->toContain($f['vencido'], $f['actual'])->not->toContain($f['futuro'])
        ->and($mis->json('en_linea.medios'))->toBe(['tarjeta', 'yape']);
    enDemo($f['usuario'], 'POST', '/familia/pagos/ordenes', ['cargos' => [$f['futuro']]])->assertUnprocessable();
    enDemo(familiaConDeuda()['usuario'], 'POST', '/familia/pagos/ordenes', ['cargos' => [$f['vencido']]])->assertUnprocessable();

    $o = enDemo($f['usuario'], 'POST', '/familia/pagos/ordenes', ['cargos' => [$f['vencido'], $f['actual']]])->assertCreated();
    expect($o->json('monto'))->toEqual(700)->and($o->json('url'))->toContain('/#/pago-simulado/');
    $orden = $o->json('orden');
    // La redirección del navegador no marca nada: sigue pendiente hasta el aviso firmado.
    $ver = enDemo($f['usuario'], 'GET', "/familia/pagos/ordenes/$orden");
    expect($ver->json('estado'))->toBe('creada');

    $token = dueno()->table('pasarela_cuentas')->find($cuenta['id'])->webhook_token;
    $cuerpo = json_encode(['orden' => $orden, 'estado' => 'pagada', 'medio' => 'yape', 'monto' => 700]);
    $this->call('POST', "/api/v1/pasarela/webhook/$token", [], [], [], ['HTTP_X_PASARELA_FIRMA' => 'firma-falsa', 'CONTENT_TYPE' => 'application/json'], $cuerpo)->assertStatus(401);
    expect(dueno()->table('cargos')->find($f['vencido'])->estado)->toBe('pendiente');

    $s = enDemo($f['usuario'], 'POST', "/familia/pagos/ordenes/$orden/simulacion", ['resultado' => 'pagada', 'medio' => 'yape'])->assertOk();
    expect($s->json('estado'))->toBe('pagada')->and($s->json('orden.comprobante.serie'))->toBe('B001');
    $t = dueno()->table('pasarela_transacciones')->where('orden', $orden)->first();
    expect($t->estado)->toBe('pagada')->and((float) $t->comision)->toBeGreaterThan(0)->and((float) $t->neto)->toBe(round(700 - (float) $t->comision, 2))
        ->and(dueno()->table('cargos')->whereIn('id', [$f['vencido'], $f['actual']])->pluck('estado')->unique()->all())->toBe(['pagado']);

    // La pasarela reenvía el mismo aviso: no se aplica dos veces.
    $secreto = Crypt::decryptString(dueno()->table('pasarela_cuentas')->find($cuenta['id'])->webhook_secreto_cifrado);
    $this->call('POST', "/api/v1/pasarela/webhook/$token", [], [], [], ['HTTP_X_PASARELA_FIRMA' => PasarelaSimulada::firmar($cuerpo, $secreto), 'CONTENT_TYPE' => 'application/json'], $cuerpo)
        ->assertOk()->assertJson(['estado' => 'ya_procesada']);
    expect(dueno()->table('pagos')->where('pasarela_transaccion_id', $t->id)->count())->toBe(1);

    // La familia descarga su boleta; otra familia no.
    $comprobante = $s->json('orden.comprobante.id');
    enDemo($f['usuario'], 'GET', "/familia/comprobantes/$comprobante")->assertOk()->assertJsonStructure(['url']);
    enDemo(familiaConDeuda()['usuario'], 'GET', "/familia/comprobantes/$comprobante")->assertNotFound();
});
