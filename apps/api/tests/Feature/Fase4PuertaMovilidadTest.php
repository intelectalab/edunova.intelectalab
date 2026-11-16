<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
| Fase 4 a medias, terminada:
| EDUN-186 asistencia en la puerta también con el DNI · EDUN-214 portería con lista del turno y
| salidas verificadas (en línea y en lote) · EDUN-176 retiro desde el tópico validado contra los
| autorizados · EDUN-163 / EDUN-165 movilidad sin conexión (marcas, posiciones y fin con su hora
| real) · EDUN-161 / EDUN-162 configuración de rutas, paraderos y alumnos en la web.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    // El colegio demo no trae Portería: se enciende para estas pruebas.
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $por = $db->table('modulos_catalogo')->where('codigo', 'POR')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $por)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $por]);
    }
});

/** Alumno matriculado en 2027 con su madre (con cuenta) y un tío autorizado para recogerlo. */
function alumnoFase4(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->value('id');
    $dniAlumno = (string) random_int(60000000, 69999999);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Puerta'.uniqid(), 'nombres' => 'Iris', 'numero_documento' => $dniAlumno]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Marta', 'apellidos' => 'Puerta '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'marta.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $dniMadre = (string) random_int(40000000, 49999999);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => $dniMadre, 'apellidos' => 'Puerta', 'nombres' => 'Marta']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre']);
    $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $dniTio = 'CE'.random_int(1000000, 9999999);
    $db->table('por_autorizados')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'nombres' => 'Raúl Puerta', 'numero_documento' => $dniTio, 'parentesco' => 'Tío']);
    $db->table('por_autorizados')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'nombres' => 'Vencido', 'numero_documento' => '55555555', 'parentesco' => 'Vecino', 'vigente_hasta' => now()->subDay()->toDateString()]);

    return ['alumno' => $alumno, 'seccion' => $seccion, 'anio' => $anio, 'dni_alumno' => $dniAlumno, 'dni_madre' => $dniMadre, 'dni_tio' => $dniTio, 'familia' => User::query()->findOrFail($u)];
}

it('EDUN-186 · en la puerta se registra la asistencia con el DNI del alumno si olvidó el carné', function () {
    $a = alumnoFase4();
    $auxiliar = personaDemo('auxiliar');
    $r = enDemo($auxiliar, 'POST', '/asistencia/qr', ['codigo' => $a['dni_alumno']])->assertOk();
    expect($r->json('alumno'))->toContain('Iris')
        ->and(dueno()->table('asistencias')->where('alumno_id', $a['alumno'])->where('fecha', now()->toDateString())->exists())->toBeTrue();
    // El mismo alumno otra vez (ahora sin señal, en lote): no duplica.
    $l = enDemo($auxiliar, 'POST', '/asistencias/lote', ['lecturas' => [['id' => 'x1', 'codigo' => $a['dni_alumno'], 'leido_at' => now()->toIso8601String()]]])->assertOk();
    expect($l->json('resultados.0.ok'))->toBeTrue()
        ->and(dueno()->table('asistencias')->where('alumno_id', $a['alumno'])->where('fecha', now()->toDateString())->count())->toBe(1);
    enDemo($auxiliar, 'POST', '/asistencia/qr', ['codigo' => '00000001'])->assertUnprocessable()->assertJsonValidationErrors('codigo');
});

it('EDUN-214 · la portería descarga la lista del turno y solo deja salir con un documento autorizado', function () {
    $a = alumnoFase4();
    $porteria = personaDemo('porteria');
    $lista = enDemo($porteria, 'GET', '/porteria/lista')->assertOk();
    $item = collect($lista->json('alumnos'))->firstWhere('id', $a['alumno']);
    expect(collect($item['pueden_recoger'])->pluck('numero_documento')->all())->toEqualCanonicalizing([$a['dni_madre'], $a['dni_tio']])
        ->and($item['numero_documento'])->toBe($a['dni_alumno']);
    // Un autorizado vencido no aparece ni sirve.
    enDemo($porteria, 'POST', '/porteria/salidas', ['alumno_id' => $a['alumno'], 'numero_documento' => '55555555'])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');

    $s = enDemo($porteria, 'POST', '/porteria/salidas', ['alumno_id' => $a['alumno'], 'numero_documento' => strtolower($a['dni_tio'])])->assertCreated();
    expect($s->json('recoge'))->toBe('Raúl Puerta (Tío)');
    $fila = dueno()->table('por_salidas')->find($s->json('id'));
    expect($fila->origen)->toBe('porteria')->and($fila->estado)->toBe('salio')->and($fila->recogido_documento)->toBe($a['dni_tio'])->and($fila->verificado_por)->toBe($porteria->id);
    Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'con Raúl Puerta (Tío)'));

    expect(collect(enDemo(personaDemo('director'), 'GET', '/porteria/salidas')->assertOk()->json('data'))->pluck('recoge'))->toContain('Raúl Puerta (Tío)');
    enDemo(personaDemo('docente'), 'GET', '/porteria/lista')->assertForbidden();
});

it('EDUN-214 · las salidas guardadas sin señal llegan en lote: cada una responde y reenviarlas no duplica', function () {
    $a = alumnoFase4();
    $porteria = personaDemo('porteria');
    $hace = now()->subMinutes(40)->toIso8601String();
    $lote = ['salidas' => [
        ['id' => 's1', 'alumno_id' => $a['alumno'], 'numero_documento' => $a['dni_madre'], 'salio_at' => $hace],
        ['id' => 's2', 'alumno_id' => $a['alumno'], 'numero_documento' => '12345678', 'salio_at' => $hace],
        ['id' => 's3', 'alumno_id' => $a['alumno'], 'numero_documento' => $a['dni_madre'], 'salio_at' => now()->subDays(5)->toIso8601String()],
    ]];
    $r = enDemo($porteria, 'POST', '/porteria/salidas/lote', $lote)->assertOk();
    expect(collect($r->json('resultados'))->pluck('ok', 'id')->all())->toBe(['s1' => true, 's2' => false, 's3' => false])
        ->and($r->json('resultados.1.mensaje'))->toContain('no está autorizada');
    enDemo($porteria, 'POST', '/porteria/salidas/lote', ['salidas' => [$lote['salidas'][0]]])->assertOk()->assertJsonPath('resultados.0.ok', true);
    expect(dueno()->table('por_salidas')->where('alumno_id', $a['alumno'])->count())->toBe(1)
        ->and(substr((string) dueno()->table('por_salidas')->where('alumno_id', $a['alumno'])->value('hora'), 0, 5))->toBe(now()->subMinutes(40)->timezone('America/Lima')->format('H:i'));
});

it('EDUN-176 · en el tópico, el retiro de un alumno derivado se valida contra sus apoderados y autorizados', function () {
    $a = alumnoFase4();
    $enfermera = personaDemo('enfermeria');
    expect(collect(enDemo($enfermera, 'GET', "/salud/alumnos/{$a['alumno']}/pueden-recoger")->assertOk()->json('data'))->pluck('parentesco'))->toContain('madre', 'Tío');
    // Por DNI también se encuentra al alumno.
    expect(enDemo($enfermera, 'GET', '/salud/alumnos?q='.$a['dni_alumno'])->assertOk()->json('data.0.id'))->toBe($a['alumno']);

    $antes = dueno()->select('select count(*) as n from salud.atenciones where alumno_id = ?', [$a['alumno']])[0]->n;
    enDemo($enfermera, 'POST', '/salud/atenciones', ['alumno_id' => $a['alumno'], 'motivo' => 'Fiebre', 'accion' => 'Paracetamol', 'derivado' => true, 'retiro_documento' => '99999999'])
        ->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
    expect(dueno()->select('select count(*) as n from salud.atenciones where alumno_id = ?', [$a['alumno']])[0]->n)->toBe($antes);

    $r = enDemo($enfermera, 'POST', '/salud/atenciones', ['alumno_id' => $a['alumno'], 'motivo' => 'Fiebre', 'accion' => 'Paracetamol', 'derivado' => true, 'retiro_documento' => $a['dni_madre']])->assertCreated();
    expect(dueno()->select('select retirado_por from salud.atenciones where id = ?', [$r->json('id')])[0]->retirado_por)->toBe('Marta Puerta (madre)')
        ->and(dueno()->table('por_salidas')->where('alumno_id', $a['alumno'])->value('origen'))->toBe('topico');
});

it('EDUN-161/162 · la web configura unidades, personal, rutas, paraderos y alumnos con sus reglas', function () {
    $a = alumnoFase4();
    $gestor = personaDemo('movilidad');
    $placa = 'P'.strtoupper(substr(uniqid(), -5));
    $u = enDemo($gestor, 'POST', '/movilidad/unidades', ['placa' => $placa, 'capacidad' => 1, 'soat_vence' => now()->addDays(10)->toDateString(), 'propia' => true])->assertCreated()->json('id');
    enDemo($gestor, 'POST', '/movilidad/unidades', ['placa' => $placa, 'capacidad' => 10])->assertUnprocessable()->assertJsonValidationErrors('placa');
    enDemo($gestor, 'POST', '/movilidad/personal', ['nombres' => 'Sin Licencia', 'numero_documento' => '41111111', 'rol' => 'conductor'])->assertUnprocessable()->assertJsonValidationErrors('licencia');
    $c = enDemo($gestor, 'POST', '/movilidad/personal', ['nombres' => 'Juan Chofer', 'numero_documento' => '41111112', 'rol' => 'conductor', 'licencia' => 'A-IIb 123', 'user_id' => $gestor->id])->assertCreated()->json('id');
    $ruta = enDemo($gestor, 'POST', '/movilidad/rutas', ['nombre' => 'Ruta '.uniqid(), 'turno' => 'manana', 'mov_unidad_id' => $u, 'conductor_id' => $c])->assertCreated()->json('id');
    enDemo($gestor, 'POST', '/movilidad/rutas', ['nombre' => 'X', 'turno' => 'manana', 'auxiliar_id' => $c])->assertUnprocessable()->assertJsonValidationErrors('auxiliar_id');

    $p = enDemo($gestor, 'PUT', "/movilidad/rutas/$ruta/paraderos", ['paraderos' => [['nombre' => 'Plaza', 'hora_estimada' => '06:40'], ['nombre' => 'Mercado', 'hora_estimada' => '06:50']]])->assertOk()->json('data');
    expect(array_column($p, 'nombre'))->toBe(['Plaza', 'Mercado']);
    $otro = personaDemo('apoderado');
    enDemo($gestor, 'PUT', "/movilidad/rutas/$ruta/alumnos", ['asignaciones' => [['alumno_id' => $a['alumno'], 'paradero_id' => $p[1]['id']], ['alumno_id' => $a['alumno'] + 999999, 'paradero_id' => $p[0]['id']]]])
        ->assertUnprocessable(); // capacidad 1
    enDemo($gestor, 'PUT', "/movilidad/rutas/$ruta/alumnos", ['asignaciones' => [['alumno_id' => $a['alumno'], 'paradero_id' => $p[1]['id']]]])->assertOk()->assertJsonPath('data.0.paradero', 'Mercado');
    // No se quita un paradero con alumnos; se reordenan los que quedan.
    enDemo($gestor, 'PUT', "/movilidad/rutas/$ruta/paraderos", ['paraderos' => [['id' => $p[0]['id'], 'nombre' => 'Plaza']]])->assertUnprocessable()->assertJsonValidationErrors('paraderos');
    enDemo($gestor, 'PUT', "/movilidad/rutas/$ruta/paraderos", ['paraderos' => [['id' => $p[1]['id'], 'nombre' => 'Mercado'], ['id' => $p[0]['id'], 'nombre' => 'Plaza']]])->assertOk()->assertJsonPath('data.0.nombre', 'Mercado');
    enDemo($gestor, 'DELETE', "/movilidad/unidades/$u")->assertUnprocessable();

    $res = enDemo($gestor, 'GET', '/movilidad/configuracion')->assertOk();
    $r = collect($res->json('rutas'))->firstWhere('id', $ruta);
    expect($r['alumnos'])->toBe(1)->and(collect($res->json('unidades'))->firstWhere('id', $u)['por_vencer'])->toBe(['SOAT por vencer']);
    enDemo($otro, 'POST', '/movilidad/unidades', ['placa' => 'ZZZ-999', 'capacidad' => 5])->assertForbidden();
    // El conductor enlazado ve la ruta en la app.
    expect(collect(enDemo($gestor, 'GET', '/movilidad/mis-rutas')->assertOk()->json('data'))->pluck('id'))->toContain($ruta);
});

it('EDUN-163/165 · marcas, posiciones y fin registrados sin señal llegan después con su hora real', function () {
    $a = alumnoFase4();
    $db = dueno();
    $inst = demoId();
    $conductor = personaDemo('conductor');
    $cid = $db->table('mov_conductores')->insertGetId(['institucion_id' => $inst, 'nombres' => 'Conductor Sin Señal', 'numero_documento' => (string) random_int(10000000, 99999999), 'rol' => 'conductor', 'user_id' => $conductor->id]);
    $ruta = $db->table('mov_rutas')->insertGetId(['institucion_id' => $inst, 'nombre' => 'Ruta Sin Señal '.uniqid(), 'turno' => 'manana', 'conductor_id' => $cid]);
    $paradero = $db->table('mov_paraderos')->insertGetId(['institucion_id' => $inst, 'mov_ruta_id' => $ruta, 'nombre' => 'Esquina', 'orden' => 1]);
    $asig = $db->table('mov_asignaciones')->insertGetId(['institucion_id' => $inst, 'alumno_id' => $a['alumno'], 'mov_ruta_id' => $ruta, 'mov_paradero_id' => $paradero, 'anio_escolar_id' => $a['anio']]);
    $rec = enDemo($conductor, 'POST', "/movilidad/rutas/$ruta/recorridos", ['sentido' => 'ida'])->assertCreated()->json('recorrido_id');
    // Se inició hace 30 minutos; el fin se marcó sin señal hace 5.
    $db->table('mov_recorridos')->where('id', $rec)->update(['iniciado_at' => now()->subMinutes(30)]);
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/fin", ['finalizado_at' => now()->subMinutes(5)->toIso8601String()])->assertOk();
    expect(abs(now()->subMinutes(5)->diffInSeconds($db->table('mov_recorridos')->find($rec)->finalizado_at)))->toBeLessThan(5);
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/fin")->assertOk(); // repetirlo no falla

    $lote = ['eventos' => [
        ['id' => 'e1', 'recorrido_id' => $rec, 'asignacion_id' => $asig, 'tipo' => 'subio', 'ocurrido_at' => now()->subMinutes(20)->toIso8601String()],
        ['id' => 'e2', 'recorrido_id' => $rec, 'asignacion_id' => $asig, 'tipo' => 'bajo', 'ocurrido_at' => now()->subMinute()->toIso8601String()],
        ['id' => 'e3', 'recorrido_id' => $rec, 'asignacion_id' => 999999999, 'tipo' => 'subio', 'ocurrido_at' => now()->subMinutes(20)->toIso8601String()],
    ]];
    $r = enDemo($conductor, 'POST', '/movilidad/eventos/lote', $lote)->assertOk();
    expect(collect($r->json('resultados'))->pluck('ok', 'id')->all())->toBe(['e1' => true, 'e2' => false, 'e3' => false]);
    enDemo($conductor, 'POST', '/movilidad/eventos/lote', ['eventos' => [$lote['eventos'][0]]])->assertOk();
    expect($db->table('mov_eventos')->where('mov_recorrido_id', $rec)->count())->toBe(1);
    Http::assertSent(fn ($req) => str_contains(json_encode($req->data(), JSON_UNESCAPED_UNICODE), 'registrado sin señal'));

    // Posiciones guardadas sin señal: entran las del recorrido; después del fin, no (privacidad).
    $p = enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/posiciones", ['posiciones' => [
        ['latitud' => -16.38, 'longitud' => -71.54, 'registrado_at' => now()->subMinutes(25)->toIso8601String()],
        ['latitud' => -16.381, 'longitud' => -71.541, 'registrado_at' => now()->subMinutes(10)->toIso8601String()],
        ['latitud' => -16.382, 'longitud' => -71.542, 'registrado_at' => now()->subMinutes(2)->toIso8601String()],
    ]])->assertOk();
    expect($p->json('guardadas'))->toBe(2);
    enDemo($conductor, 'POST', "/movilidad/recorridos/$rec/posiciones", ['posiciones' => [['latitud' => -16.38, 'longitud' => -71.54, 'registrado_at' => now()->subMinute()->toIso8601String()]]])->assertUnprocessable();
    enDemo(personaDemo('conductor'), 'POST', '/movilidad/eventos/lote', $lote)->assertOk()->assertJsonPath('resultados.0.ok', false); // otra ruta
});
