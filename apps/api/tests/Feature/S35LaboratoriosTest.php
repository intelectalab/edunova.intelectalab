<?php

use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| S35 · Laboratorios: EDUN-191 reservas sin cruces · 192 préstamo de equipos con revisión ·
| 193 materiales y reactivos (hoja de seguridad, llave, vencidos) · 194 incidencias hasta cerrar.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $m = $db->table('modulos_catalogo')->where('codigo', 'LAB')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
});

function docenteLab(): array
{
    $u = personaDemo('docente');
    $p = dueno()->table('personal')->insertGetId(['institucion_id' => demoId(), 'user_id' => $u->id, 'nombres' => 'Profe', 'apellidos' => 'Lab '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999), 'cargo' => 'Docente']);

    return ['usuario' => $u, 'personal' => $p];
}

it('EDUN-191 · el docente reserva un laboratorio sin cruces y avisa al jefe', function () {
    $jefe = personaDemo('laboratorio');
    dueno()->table('dispositivos_moviles')->insert(['user_id' => $jefe->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $espacio = enDemo($jefe, 'POST', '/laboratorio/espacios', ['nombre' => 'Lab de Ciencias '.uniqid(), 'tipo' => 'laboratorio', 'capacidad' => 30])->assertCreated()->json('id');
    $d = docenteLab();
    $fecha = now()->addDays(2)->toDateString();
    $r = enDemo($d['usuario'], 'POST', '/laboratorio/reservas', ['lab_espacio_id' => $espacio, 'fecha' => $fecha, 'hora_inicio' => '08:00', 'hora_fin' => '09:30', 'materiales' => 'Microscopios'])->assertCreated();
    expect($r->json('hora_inicio'))->toBe('08:00');
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Microscopios'));
    // Se cruza con la anterior (aunque empiece a otra hora).
    enDemo(docenteLab()['usuario'], 'POST', '/laboratorio/reservas', ['lab_espacio_id' => $espacio, 'fecha' => $fecha, 'hora_inicio' => '09:00', 'hora_fin' => '10:00'])->assertUnprocessable()->assertJsonValidationErrors('hora_inicio');
    enDemo(docenteLab()['usuario'], 'POST', '/laboratorio/reservas', ['lab_espacio_id' => $espacio, 'fecha' => $fecha, 'hora_inicio' => '09:30', 'hora_fin' => '10:30'])->assertCreated();
    enDemo($d['usuario'], 'POST', "/laboratorio/reservas/{$r->json('id')}/estado", ['estado' => 'realizada'])->assertForbidden();
    enDemo($d['usuario'], 'POST', "/laboratorio/reservas/{$r->json('id')}/estado", ['estado' => 'cancelada'])->assertOk();
    enDemo(docenteLab()['usuario'], 'POST', '/laboratorio/reservas', ['lab_espacio_id' => $espacio, 'fecha' => $fecha, 'hora_inicio' => '08:00', 'hora_fin' => '09:00'])->assertCreated();
});

it('EDUN-192 / 194 · préstamo de equipos con revisión al devolver; el daño abre una incidencia que se cierra', function () {
    $jefe = personaDemo('laboratorio');
    $codigo = 'MIC-'.random_int(100, 999999);
    enDemo($jefe, 'POST', '/laboratorio/equipos', ['codigo' => $codigo, 'tipo' => 'Microscopio'])->assertCreated();
    $d = docenteLab();
    enDemo($jefe, 'POST', '/laboratorio/prestamos', ['codigo' => strtolower($codigo), 'personal_id' => $d['personal'], 'hasta' => now()->addHours(2)->toIso8601String()])->assertCreated();
    enDemo($jefe, 'POST', '/laboratorio/prestamos', ['codigo' => $codigo, 'personal_id' => $d['personal'], 'hasta' => now()->addHours(2)->toIso8601String()])->assertUnprocessable();
    enDemo($jefe, 'POST', '/laboratorio/devoluciones', ['codigo' => $codigo, 'resultado' => 'danado'])->assertUnprocessable()->assertJsonValidationErrors('observacion');
    enDemo($jefe, 'POST', '/laboratorio/devoluciones', ['codigo' => $codigo, 'resultado' => 'danado', 'observacion' => 'Lente rayado'])->assertOk()->assertJsonPath('estado', 'reparacion');
    $inc = collect(enDemo($jefe, 'GET', '/laboratorio/incidencias?estado=abierta')->assertOk()->json('data'))->firstWhere('equipo', $codigo);
    expect($inc['detalle'])->toContain('Lente rayado');

    $rep = enDemo($d['usuario'], 'POST', '/laboratorio/incidencias', ['detalle' => 'El caño del lavadero gotea', 'equipo' => null])->assertCreated();
    enDemo($jefe, 'POST', "/laboratorio/incidencias/{$rep->json('id')}/estado", ['estado' => 'en_proceso'])->assertOk();
    enDemo($jefe, 'POST', "/laboratorio/incidencias/{$rep->json('id')}/estado", ['estado' => 'cerrada'])->assertUnprocessable();
    enDemo($jefe, 'POST', "/laboratorio/incidencias/{$rep->json('id')}/estado", ['estado' => 'cerrada', 'respuesta' => 'Se cambió el caño'])->assertOk()->assertJsonPath('estado', 'cerrada');
    enDemo($d['usuario'], 'POST', "/laboratorio/incidencias/{$rep->json('id')}/estado", ['estado' => 'cerrada', 'respuesta' => 'x'])->assertForbidden();
});

it('EDUN-193 · reactivos peligrosos con hoja y llave; los vencidos no se entregan y se avisa el stock bajo', function () {
    $jefe = personaDemo('laboratorio');
    dueno()->table('dispositivos_moviles')->insert(['user_id' => $jefe->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    actuarComo($jefe, true, demoId());
    $h = ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json'];
    test()->post('/api/v1/laboratorio/materiales', ['nombre' => 'Ácido clorhídrico', 'unidad' => 'ml', 'stock' => 500, 'peligroso' => 1], $h)->assertUnprocessable()->assertJsonValidationErrors('hoja_seguridad');
    test()->post('/api/v1/laboratorio/materiales', ['nombre' => 'Ácido clorhídrico', 'unidad' => 'ml', 'stock' => 500, 'peligroso' => 1, 'hoja_seguridad' => UploadedFile::fake()->create('hoja.pdf', 50, 'application/pdf')], $h)->assertUnprocessable()->assertJsonValidationErrors('ubicacion');
    $acido = test()->post('/api/v1/laboratorio/materiales', ['nombre' => 'Ácido clorhídrico', 'unidad' => 'ml', 'stock' => 500, 'stock_minimo' => 100, 'peligroso' => 1, 'con_llave' => 1, 'ubicacion' => 'Armario A (llave)', 'hoja_seguridad' => UploadedFile::fake()->create('hoja.pdf', 50, 'application/pdf')], $h)->assertCreated();
    expect($acido->json('tiene_hoja'))->toBeTrue();
    expect(enDemo($jefe, 'POST', "/laboratorio/materiales/{$acido->json('id')}/entrega", ['cantidad' => 450])->assertOk()->json('stock'))->toEqual(50);
    enDemo($jefe, 'POST', "/laboratorio/materiales/{$acido->json('id')}/entrega", ['cantidad' => 60])->assertUnprocessable();
    actuarComo($jefe, true, demoId());
    $vencido = test()->post('/api/v1/laboratorio/materiales', ['nombre' => 'Indicador', 'unidad' => 'g', 'stock' => 10, 'vence' => now()->subDay()->toDateString()], $h)->assertCreated()->json('id');
    enDemo($jefe, 'POST', "/laboratorio/materiales/{$vencido}/entrega", ['cantidad' => 1])->assertUnprocessable()->assertJsonValidationErrors('material');
    Artisan::call('lab:alertas');
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Stock bajo') && str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Indicador'));
    expect(enDemo(docenteLab()['usuario'], 'GET', "/laboratorio/materiales/{$acido->json('id')}/hoja")->assertOk()->json('url'))->toBeString();
});

it('EDUN-216 / EDUN-213 · atenciones guardadas sin señal llegan en lote con su hora real y la ruta trae el carné', function () {
    $db = dueno();
    $carne = 'EN'.strtoupper(bin2hex(random_bytes(5)));
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => demoId(), 'apellido_paterno' => 'Topico'.uniqid(), 'nombres' => 'Iván', 'numero_documento' => (string) random_int(60000000, 69999999), 'codigo_carne' => $carne]);
    $enfermeria = personaDemo('enfermeria');
    $hora = now()->subMinutes(30)->startOfMinute();
    $lote = ['atenciones' => [
        ['id' => 'a1', 'codigo' => strtolower($carne), 'motivo' => 'Golpe en la rodilla', 'accion' => 'Hielo y reposo', 'atendido_at' => $hora->toIso8601String()],
        ['id' => 'a2', 'codigo' => 'ENNOEXISTE1', 'motivo' => 'Fiebre', 'accion' => 'Llamar a casa', 'atendido_at' => $hora->toIso8601String()],
    ]];
    $r = enDemo($enfermeria, 'POST', '/salud/atenciones/lote', $lote)->assertOk();
    expect(collect($r->json('resultados'))->pluck('ok', 'id')->all())->toBe(['a1' => true, 'a2' => false]);
    enDemo($enfermeria, 'POST', '/salud/atenciones/lote', ['atenciones' => [$lote['atenciones'][0]]])->assertOk();
    $db->unprepared("select set_config('app.acceso_salud', 'on', false)");
    $atenciones = $db->table('salud.atenciones')->where('alumno_id', $alumno)->get();
    expect($atenciones)->toHaveCount(1)->and(abs($hora->diffInSeconds($atenciones[0]->atendido_at)))->toBeLessThan(2);
    enDemo(personaDemo('docente'), 'POST', '/salud/atenciones/lote', $lote)->assertForbidden();
});
