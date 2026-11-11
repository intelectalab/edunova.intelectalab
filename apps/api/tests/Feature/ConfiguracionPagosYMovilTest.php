<?php

use App\Modulos\ADM\Services\RegistroAlumnos;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\GeneradorCargos;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| Sprint 16 · Configuración de pagos y base de la app móvil:
| EDUN-69 conceptos · EDUN-70 cronograma · EDUN-71 cargos al matricular · EDUN-72 descuentos y becas
| EDUN-90 proveedores de facturación · EDUN-195 ingreso desde la app · EDUN-196 push · EDUN-200 imagen del colegio
*/

/** Año escolar propio de estas pruebas (para no chocar con el cronograma del demo). */
function anioPagos(int $anio): int
{
    $id = dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', $anio)->value('id');

    return (int) ($id ?? dueno()->table('anios_escolares')->insertGetId([
        'institucion_id' => demoId(), 'anio' => $anio, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => "$anio-03-01", 'fecha_fin' => "$anio-12-17",
    ]));
}

function seccionPagos(): int
{
    $anio = anioPagos(2027);
    $grado = dueno()->table('grados')->where('institucion_id', demoId())->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $id = dueno()->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->where('nombre', 'P')->value('id');

    return (int) ($id ?? dueno()->table('secciones')->insertGetId(['institucion_id' => demoId(), 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'P']));
}

/** Alumno nuevo del demo, sin matrícula. */
function alumnoPagos(): int
{
    return (int) enDemo(directorDemo(), 'POST', '/alumnos', [
        'apellido_paterno' => 'Pagos', 'nombres' => 'Alumno '.uniqid(), 'numero_documento' => (string) random_int(60000000, 69999999),
    ])->assertCreated()->json('id');
}

/** Matrícula fuera de ventanilla (registro o importación), como la hace RegistroAlumnos. */
function matricularPagos(int $alumno): void
{
    DB::transaction(function () use ($alumno) {
        ContextoInstitucion::fijar(demoId());
        app(RegistroAlumnos::class)->matricular($alumno, seccionPagos());
    });
}

function conceptoDemo(string $codigo): int
{
    return (int) dueno()->table('conceptos_cobro')->where('institucion_id', demoId())->where('codigo', $codigo)->value('id');
}

function sustentoDemo(): int
{
    return (int) dueno()->table('archivos')->insertGetId([
        'institucion_id' => demoId(), 'ruta' => 'pruebas/sustento.pdf', 'nombre_original' => 'sustento.pdf', 'mime' => 'application/pdf', 'bytes' => 10, 'sha256' => str_repeat('a', 64),
    ]);
}

it('valida los conceptos: obligatorios solo los tres de Indecopi, comprobante y matrícula no mayor que la pensión', function () {
    $tesoreria = personaDemo('tesoreria');
    $base = ['nombre' => 'Taller de ajedrez', 'tipo' => 'servicio', 'monto' => 80, 'tipo_comprobante' => 'boleta', 'afecto_igv' => true];

    enDemo($tesoreria, 'POST', '/pagos/conceptos', $base + ['codigo' => 'AJ'.random_int(100, 999), 'obligatorio' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('obligatorio');
    enDemo($tesoreria, 'POST', '/pagos/conceptos', array_merge($base, ['codigo' => 'AJ'.random_int(100, 999), 'tipo_comprobante' => 'ticket']))
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_comprobante');
    enDemo($tesoreria, 'POST', '/pagos/conceptos', ['codigo' => 'MX'.random_int(100, 999), 'nombre' => 'Matrícula cara', 'tipo' => 'matricula', 'monto' => 900, 'tipo_comprobante' => 'boleta'])
        ->assertUnprocessable()->assertJsonValidationErrors('monto');

    $r = enDemo($tesoreria, 'POST', '/pagos/conceptos', $base + ['codigo' => 'aj'.random_int(100, 999)])->assertCreated();
    $nuevo = collect($r->json('data'))->firstWhere('id', $r->json('id'));
    expect($nuevo['codigo'])->toStartWith('AJ')->and($nuevo['afecto_igv'])->toBeTrue()->and($nuevo['obligatorio'])->toBeFalse();

    // La BD también lo impide aunque alguien se salte la API.
    expect(fn () => dueno()->table('conceptos_cobro')->where('id', $r->json('id'))->update(['obligatorio' => true]))->toThrow(Exception::class);
    // Sin permiso de edición (director): solo ve.
    enDemo(directorDemo(), 'GET', '/pagos/conceptos')->assertOk();
    enDemo(personaDemo('director'), 'GET', '/pagos/conceptos')->assertOk();
    enDemo(personaDemo('director'), 'POST', '/pagos/conceptos', $base + ['codigo' => 'ZZ1'])->assertForbidden();
});

it('crea el cronograma por año y nivel sin cuotas que venzan antes de su mes y lo copia al año siguiente', function () {
    $tesoreria = personaDemo('tesoreria');
    $anio = anioPagos(2041);
    dueno()->table('cronograma_cuotas')->whereIn('cronograma_id', dueno()->table('cronogramas')->whereIn('anio_escolar_id', [$anio, anioPagos(2042)])->pluck('id'))->delete();
    dueno()->table('cronogramas')->whereIn('anio_escolar_id', [$anio, anioPagos(2042)])->delete();
    $pension = conceptoDemo('PEN');
    $cuota = fn (string $mes, string $vence, $monto = 350, $concepto = null) => ['concepto_cobro_id' => $concepto ?? $pension, 'mes' => $mes, 'emision' => "$mes-01", 'vencimiento' => $vence, 'monto' => $monto];

    enDemo($tesoreria, 'POST', '/pagos/cronogramas', ['anio_escolar_id' => $anio, 'nivel' => 'secundaria', 'nombre' => 'Pensiones 2041', 'cuotas' => [$cuota('2041-04', '2041-03-31')]])
        ->assertUnprocessable()->assertJsonValidationErrors('cuotas.0.vencimiento');
    enDemo($tesoreria, 'POST', '/pagos/cronogramas', ['anio_escolar_id' => $anio, 'nivel' => 'secundaria', 'nombre' => 'Pensiones 2041', 'cuotas' => [
        $cuota('2041-03', '2041-03-31', 400, conceptoDemo('MAT')), $cuota('2041-03', '2041-03-31', 350),
    ]])->assertUnprocessable()->assertJsonValidationErrors('cuotas');

    $cuotas = [$cuota('2041-03', '2041-03-05', 300, conceptoDemo('MAT'))];
    foreach (range(3, 12) as $m) {
        $mes = sprintf('2041-%02d', $m);
        $cuotas[] = $cuota($mes, date('Y-m-t', strtotime("$mes-01")));
    }
    $r = enDemo($tesoreria, 'POST', '/pagos/cronogramas', ['anio_escolar_id' => $anio, 'nivel' => 'secundaria', 'nombre' => 'Pensiones 2041', 'cuotas' => $cuotas])->assertCreated();
    expect($r->json('data.0.cuotas'))->toHaveCount(11)->and($r->json('data.0.cuotas.0.mes'))->toBe('2041-03');

    enDemo($tesoreria, 'POST', '/pagos/cronogramas', ['anio_escolar_id' => $anio, 'nivel' => 'secundaria', 'nombre' => 'Otro', 'cuotas' => $cuotas])
        ->assertUnprocessable()->assertJsonValidationErrors('nivel');
    // La BD rechaza una cuota que vence antes de su mes.
    expect(fn () => dueno()->table('cronograma_cuotas')->where('cronograma_id', $r->json('id'))->where('mes', '2041-05')->update(['vencimiento' => '2041-04-30']))->toThrow(Exception::class);

    $c = enDemo($tesoreria, 'POST', '/pagos/cronogramas/copiar', ['desde_anio_id' => $anio, 'hacia_anio_id' => anioPagos(2042)])->assertOk();
    expect($c->json('copiados'))->toBe(1)->and($c->json('data.0.nombre'))->toBe('Pensiones 2042')
        ->and($c->json('data.0.cuotas.1.mes'))->toBe('2042-03')->and($c->json('data.0.cuotas.1.vencimiento'))->toBe('2042-03-31');
});

it('genera los cargos al matricular con beneficios aprobados y código de recaudación', function () {
    expect(GeneradorCargos::codigo(1))->toBe('0000000018')->and(strlen(GeneradorCargos::codigo(123456)))->toBe(10);

    $alumno = alumnoPagos();
    $beca = (int) dueno()->table('beneficios')->where('institucion_id', demoId())->where('nombre', 'Descuento hermanos')->value('id');
    enDemo(directorDemo(), 'POST', '/pagos/beneficios/asignaciones', ['alumno_id' => $alumno, 'beneficio_id' => $beca, 'anio_escolar_id' => anioPagos(2027), 'sustento_archivo_id' => sustentoDemo()])
        ->assertCreated()->assertJson(['estado' => 'aprobado']);

    // Matrícula fuera de ventanilla (registro o importación): también genera los cargos.
    matricularPagos($alumno);

    $cargos = dueno()->table('cargos')->where('alumno_id', $alumno)->get();
    expect($cargos)->not->toBeEmpty()
        ->and($cargos->every(fn ($c) => preg_match('/^\d{10}$/', (string) $c->codigo_recaudacion) === 1))->toBeTrue()
        ->and((float) $cargos->first()->descuento)->toBe(35.0);
});

it('tesorería propone la beca con sustento, dirección la aprueba y se recalculan los cargos pendientes', function () {
    $alumno = alumnoPagos();
    matricularPagos($alumno);
    $beca = (int) dueno()->table('beneficios')->where('institucion_id', demoId())->where('nombre', 'Beca por orfandad')->value('id');
    $tesoreria = personaDemo('tesoreria');

    enDemo($tesoreria, 'POST', '/pagos/beneficios/asignaciones', ['alumno_id' => $alumno, 'beneficio_id' => $beca, 'anio_escolar_id' => anioPagos(2027)])
        ->assertUnprocessable()->assertJsonValidationErrors('sustento_archivo_id');
    $r = enDemo($tesoreria, 'POST', '/pagos/beneficios/asignaciones', ['alumno_id' => $alumno, 'beneficio_id' => $beca, 'anio_escolar_id' => anioPagos(2027), 'sustento_archivo_id' => sustentoDemo()])
        ->assertCreated()->assertJson(['estado' => 'pendiente', 'recalculados' => 0]);
    expect((float) dueno()->table('cargos')->where('alumno_id', $alumno)->sum('descuento'))->toBe(0.0);

    // Tesorería no aprueba; el director sí y queda quién y cuándo.
    enDemo($tesoreria, 'PATCH', '/pagos/beneficios/asignaciones/'.$r->json('id'), ['aprobar' => true])->assertForbidden();
    enDemo(directorDemo(), 'PATCH', '/pagos/beneficios/asignaciones/'.$r->json('id'), ['aprobar' => false])->assertUnprocessable()->assertJsonValidationErrors('observacion');
    $ok = enDemo(directorDemo(), 'PATCH', '/pagos/beneficios/asignaciones/'.$r->json('id'), ['aprobar' => true])->assertOk();
    expect($ok->json('recalculados'))->toBeGreaterThan(0);
    $ab = dueno()->table('alumno_beneficios')->find($r->json('id'));
    expect($ab->estado)->toBe('aprobado')->and((int) $ab->aprobado_por)->toBe(directorDemo()->id)->and($ab->aprobado_at)->not->toBeNull();
    $pension = dueno()->table('cargos as c')->join('conceptos_cobro as cp', 'cp.id', '=', 'c.concepto_cobro_id')->where('c.alumno_id', $alumno)->where('cp.tipo', 'pension')->first(['c.monto', 'c.descuento']);
    expect((float) $pension->descuento)->toBe((float) $pension->monto);

    // Al quitarla vuelve el monto completo.
    enDemo(directorDemo(), 'DELETE', '/pagos/beneficios/asignaciones/'.$r->json('id'))->assertOk();
    expect((float) dueno()->table('cargos')->where('alumno_id', $alumno)->sum('descuento'))->toBe(0.0);
    expect(dueno()->table('auditoria')->where('tabla', 'alumno_beneficios')->where('registro_id', $r->json('id'))->pluck('accion')->all())
        ->toContain('beneficio_solicitado', 'beneficio_aprobado', 'beneficio_retirado');
});

it('configura dos proveedores de facturación con uno predeterminado, series válidas y credenciales cifradas', function () {
    $tesoreria = personaDemo('tesoreria');
    $db = dueno();
    $db->table('proveedores_facturacion')->where('institucion_id', demoId())->where('proveedor', 'proveedor_b')->delete();
    $base = ['proveedor' => 'proveedor_b', 'ambiente' => 'beta', 'serie_boleta' => 'B002', 'serie_factura' => 'F002', 'credenciales' => '{"usuario":"x","clave":"secreta"}'];

    enDemo($tesoreria, 'POST', '/pagos/proveedores-facturacion', ['serie_boleta' => 'F001'] + $base)->assertUnprocessable()->assertJsonValidationErrors('serie_boleta');
    enDemo($tesoreria, 'POST', '/pagos/proveedores-facturacion', ['proveedor' => 'otro'] + $base)->assertUnprocessable()->assertJsonValidationErrors('proveedor');
    $r = enDemo($tesoreria, 'POST', '/pagos/proveedores-facturacion', $base + ['predeterminado' => true])->assertCreated();
    expect(json_encode($r->json()))->not->toContain('secreta');
    $filas = collect($r->json('data'));
    expect($filas->where('predeterminado', true))->toHaveCount(1)->and($filas->firstWhere('predeterminado', true)['proveedor'])->toBe('proveedor_b')
        ->and($filas->firstWhere('proveedor', 'proveedor_b')['tiene_credenciales'])->toBeTrue();
    expect(Crypt::decryptString($db->table('proveedores_facturacion')->find($r->json('id'))->credenciales_cifradas))->toContain('secreta');

    // Volver al primero como predeterminado y pasar a producción sin reescribir credenciales.
    $a = $filas->firstWhere('proveedor', 'proveedor_a');
    enDemo($tesoreria, 'PUT', '/pagos/proveedores-facturacion/'.$a['id'], ['proveedor' => 'proveedor_a', 'ambiente' => 'produccion', 'serie_boleta' => 'B001', 'serie_factura' => 'F001', 'predeterminado' => true])->assertOk();
    expect($db->table('proveedores_facturacion')->where('institucion_id', demoId())->where('predeterminado', true)->pluck('proveedor')->all())->toBe(['proveedor_a']);
    enDemo($tesoreria, 'POST', '/pagos/proveedores-facturacion', $base)->assertUnprocessable()->assertJsonValidationErrors('proveedor');
});

it('la app ingresa, elige colegio con su imagen, registra el dispositivo y respeta las preferencias de push', function () {
    $apoderado = personaDemo('apoderado');
    $ingreso = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $apoderado->email, 'password' => DemoSeeder::DEMO_PASSWORD, 'dispositivo' => 'app-android'])->assertOk();
    $token = $ingreso->json('token');
    expect($ingreso->json('colegio_activo.marca'))->toHaveKeys(['color_primario', 'tiene_logo']);
    $h = ['Authorization' => "Bearer $token"];

    $this->postJson('/api/v1/auth/dispositivos', ['token' => 'cualquier-cosa', 'plataforma' => 'android'], $h)->assertUnprocessable();
    $push = 'ExponentPushToken['.bin2hex(random_bytes(8)).']';
    $this->postJson('/api/v1/auth/dispositivos', ['token' => $push, 'plataforma' => 'android', 'app_version' => '1.0.0'], $h)->assertOk();
    expect(dueno()->table('dispositivos_moviles')->where('token_push', $push)->value('user_id'))->toBe($apoderado->id);

    $p = $this->putJson('/api/v1/auth/notificaciones/preferencias', ['preferencias' => ['comunicados' => false, 'inventado' => false]], $h)->assertOk();
    expect(collect($p->json('data'))->firstWhere('tipo', 'comunicados')['push'])->toBeFalse()->and(collect($p->json('data'))->firstWhere('tipo', 'pagos')['push'])->toBeTrue();

    Http::fake(['exp.host/*' => Http::sequence()->push(['data' => [['status' => 'ok', 'id' => 'x']]])
        ->push(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    $this->postJson('/api/v1/notificaciones/prueba', [], $h + ['X-Institucion' => (string) demoId()])->assertOk()->assertJson(['enviados' => 0, 'omitidos' => 1]);
    Http::assertNothingSent();

    $this->putJson('/api/v1/auth/notificaciones/preferencias', ['preferencias' => ['comunicados' => true]], $h)->assertOk();
    $this->postJson('/api/v1/notificaciones/prueba', [], $h + ['X-Institucion' => (string) demoId()])->assertOk()->assertJson(['enviados' => 1]);
    Http::assertSent(fn ($req) => $req[0]['to'] === $push && $req[0]['data']['pantalla'] === 'notificaciones');

    // Token que ya no existe en el servicio: se borra.
    $this->postJson('/api/v1/notificaciones/prueba', [], $h + ['X-Institucion' => (string) demoId()])->assertOk()->assertJson(['enviados' => 0]);
    expect(dueno()->table('dispositivos_moviles')->where('token_push', $push)->exists())->toBeFalse();
});
