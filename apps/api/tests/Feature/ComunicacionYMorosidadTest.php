<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
| Sprint 21 · Comunicación y cobranza:
| EDUN-60 comunicado segmentado · EDUN-61 lectura · EDUN-62 la familia confirma · EDUN-63 mensajería con horario
| EDUN-64 agenda · EDUN-65 citas · EDUN-66/67 libro de reclamaciones · EDUN-68 bitácora · EDUN-81 morosidad
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Mail::fake();
    config()->set('services.whatsapp.costo_mensaje', 0.05); // valor de prueba
});

afterEach(fn () => Carbon::setTestNow());

/** Sección propia (3.° secundaria «C») con tutor con cuenta y dos familias con cuenta; la segunda con deuda vencida. */
function escenarioCom(): array
{
    static $e = null;
    if ($e) {
        return $e;
    }
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = (int) $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $tutor = personaDemo('tutor');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $tutor->id, 'numero_documento' => (string) random_int(10000000, 99999999), 'apellidos' => 'Tutor Com', 'nombres' => 'Raúl', 'cargo' => 'Docente']);
    $seccion = $db->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->where('nombre', 'C')->value('id')
        ?? $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'C', 'tutor_personal_id' => $personal]);
    $familias = [];
    foreach (['Ana', 'Beto'] as $i => $n) {
        $u = $db->table('users')->insertGetId(['nombres' => "Madre $n", 'apellidos' => 'Com '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999),
            'email' => strtolower($n).'.'.uniqid().'@familia.prueba', 'celular' => '98765432'.$i, 'password' => Hash::make('x')]);
        $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
        $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Com', 'nombres' => "Madre $n", 'celular' => '98765432'.$i]);
        $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Com', 'nombres' => $n, 'numero_documento' => (string) random_int(70000000, 79999999)]);
        $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre', 'es_responsable_pago' => true]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion]);
        $familias[] = ['usuario' => User::query()->findOrFail($u), 'apoderado' => $apo, 'alumno' => $alumno];
    }
    $pen = $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id');
    $db->table('cargos')->insert(['institucion_id' => $inst, 'alumno_id' => $familias[1]['alumno'], 'concepto_cobro_id' => $pen, 'descripcion' => 'Pensión vencida', 'emision' => now()->subDays(80)->toDateString(),
        'vencimiento' => now()->subDays(45)->toDateString(), 'monto' => 350]);

    return $e = ['seccion' => $seccion, 'tutor' => $tutor, 'personal' => $personal, 'familias' => $familias];
}

it('envía un comunicado segmentado por sección y con deuda, con lectura, confirmación y recordatorio', function () {
    $e = escenarioCom();
    $director = directorDemo();
    enDemo($director, 'POST', '/comunicados', ['titulo' => 'X', 'contenido' => 'Y', 'alcance' => ['tipo' => 'seccion', 'ids' => [999999]], 'canales' => ['app']])
        ->assertUnprocessable()->assertJsonValidationErrors('alcance');
    $prev = enDemo($director, 'POST', '/comunicados/vista-previa', ['alcance' => ['tipo' => 'seccion', 'ids' => [$e['seccion']], 'con_deuda' => true]])->assertOk();
    expect($prev->json('familias'))->toBe(1);

    $r = enDemo($director, 'POST', '/comunicados', ['titulo' => 'Reunión de padres', 'contenido' => 'El viernes a las 6 p. m.', 'alcance' => ['tipo' => 'seccion', 'ids' => [$e['seccion']]],
        'canales' => ['app', 'whatsapp', 'correo'], 'requiere_confirmacion' => true])->assertCreated();
    $id = $r->json('id');
    $c = collect($r->json('data'))->firstWhere('id', $id);
    expect($c['estado'])->toBe('enviado')->and($c['destinatarios'])->toBe(2);
    expect(dueno()->table('notificaciones')->where('canal', 'whatsapp')->where('plantilla', 'comunicados')->where('created_at', '>=', now()->subMinute())->sum('costo'))->toEqual(0.1);

    // La familia lo ve por hijo, lo lee y lo confirma.
    $f = enDemo($e['familias'][0]['usuario'], 'GET', '/familia/comunicados')->assertOk();
    $mio = collect($f->json('data'))->firstWhere('comunicado_id', $id);
    expect($mio['requiere_confirmacion'])->toBeTrue()->and($mio['alumno_id'])->toBe($e['familias'][0]['alumno']);
    enDemo($e['familias'][0]['usuario'], 'POST', "/familia/comunicados/{$mio['id']}", ['confirmar' => true])->assertOk();
    enDemo($e['familias'][1]['usuario'], 'POST', "/familia/comunicados/{$mio['id']}", ['confirmar' => true])->assertNotFound();

    $l = enDemo($director, 'GET', "/comunicados/$id/lectura")->assertOk();
    expect($l->json('por_seccion.0'))->toMatchArray(['total' => 2, 'leidos' => 1, 'confirmados' => 1, 'porcentaje' => 50])->and($l->json('no_leidos'))->toHaveCount(1);
    expect(enDemo($director, 'POST', "/comunicados/$id/recordatorio")->assertOk()->json('recordados'))->toBe(1);

    // Un enviado no se edita: se anula con motivo.
    enDemo($director, 'POST', "/comunicados/$id/anulacion", ['motivo' => 'Cambio de fecha'])->assertOk();
    expect(collect(enDemo($e['familias'][0]['usuario'], 'GET', '/familia/comunicados')->json('data'))->firstWhere('comunicado_id', $id))->toBeNull();

    // Programado: sale con el comando.
    $p = enDemo($director, 'POST', '/comunicados', ['titulo' => 'Programado', 'contenido' => 'Mañana', 'alcance' => ['tipo' => 'colegio'], 'canales' => ['app'], 'programado_para' => now()->addHour()->toIso8601String()])->assertCreated();
    expect(dueno()->table('comunicados')->find($p->json('id'))->estado)->toBe('programado');
    Carbon::setTestNow(now()->addHours(2));
    $this->artisan('com:programados')->assertSuccessful();
    expect(dueno()->table('comunicados')->find($p->json('id'))->estado)->toBe('enviado');
});

it('la familia conversa con el tutor; fuera del horario el mensaje se entrega al día siguiente', function () {
    $e = escenarioCom();
    $familia = $e['familias'][0]['usuario'];
    $contactos = enDemo($familia, 'GET', '/conversaciones')->assertOk()->json('contactos.'.$e['familias'][0]['alumno']);
    expect(collect($contactos)->pluck('rol')->all())->toContain('tutor');

    Carbon::setTestNow(Carbon::parse('2027-04-07 10:00', 'America/Lima')); // miércoles, en horario
    $c = enDemo($familia, 'POST', '/conversaciones', ['alumno_id' => $e['familias'][0]['alumno'], 'rol' => 'tutor', 'asunto' => 'Tarea de Matemática', 'mensaje' => '¿Qué páginas son?'])->assertCreated();
    $id = $c->json('id');
    expect(enDemo($e['tutor'], 'GET', '/conversaciones')->json('data.0.sin_leer'))->toBe(1);
    $t = enDemo($e['tutor'], 'GET', "/conversaciones/$id")->assertOk();
    expect($t->json('mensajes'))->toHaveCount(1)->and($t->json('mensajes.0.mio'))->toBeFalse();
    enDemo($e['tutor'], 'POST', "/conversaciones/$id/mensajes", ['contenido' => 'Páginas 20 a 22.'])->assertCreated();
    $v = enDemo($familia, 'GET', "/conversaciones/$id")->json('mensajes');
    expect($v[0]['leido'])->toBeTrue()->and($v[1]['mio'])->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2027-04-07 21:30', 'America/Lima')); // fuera de horario
    $r = enDemo($familia, 'POST', "/conversaciones/$id/mensajes", ['contenido' => 'Gracias, profe.'])->assertCreated();
    expect($r->json('entregado'))->toBeFalse()->and(Carbon::parse($r->json('entregar_at'))->timezone('America/Lima')->format('Y-m-d H:i'))->toBe('2027-04-08 07:30');
    expect(enDemo($e['tutor'], 'GET', "/conversaciones/$id")->json('mensajes'))->toHaveCount(2);
    Carbon::setTestNow(Carbon::parse('2027-04-08 08:00', 'America/Lima'));
    expect(enDemo($e['tutor'], 'GET', "/conversaciones/$id")->json('mensajes'))->toHaveCount(3);
    enDemo(personaDemo('apoderado'), 'GET', "/conversaciones/$id")->assertNotFound();
});

it('publica eventos por sección con recordatorio y gestiona citas con acuerdos', function () {
    $e = escenarioCom();
    $director = directorDemo();
    enDemo($director, 'POST', '/agenda', ['titulo' => 'Día del logro', 'inicio' => now()->addHours(20)->toIso8601String(), 'secciones' => [$e['seccion']]])->assertCreated();
    enDemo($director, 'POST', '/agenda', ['titulo' => 'Solo primaria', 'inicio' => now()->addDays(3)->toIso8601String(), 'niveles' => ['primaria']])->assertCreated();
    $vistos = collect(enDemo($e['familias'][0]['usuario'], 'GET', '/agenda?familia=1')->json('data'))->pluck('titulo')->all();
    expect($vistos)->toContain('Día del logro')->not->toContain('Solo primaria');
    $this->artisan('com:recordatorios')->assertSuccessful();
    expect(dueno()->table('eventos_calendario')->where('titulo', 'Día del logro')->value('recordatorio_enviado_at'))->not->toBeNull();

    $familia = $e['familias'][0]['usuario'];
    enDemo($familia, 'POST', '/citas', ['alumno_id' => $e['familias'][0]['alumno'], 'rol' => 'tutor', 'inicio' => now()->subDay()->toIso8601String(), 'motivo' => 'Conducta'])->assertUnprocessable();
    enDemo($familia, 'POST', '/citas', ['alumno_id' => $e['familias'][0]['alumno'], 'rol' => 'tutor', 'inicio' => now()->addDays(2)->setTime(9, 0)->toIso8601String(), 'motivo' => 'Revisar notas'])->assertCreated();
    $cita = collect(enDemo($e['tutor'], 'GET', '/citas')->json('data'))->firstWhere('motivo', 'Revisar notas');
    enDemo($e['tutor'], 'PATCH', "/citas/{$cita['id']}", ['estado' => 'confirmada'])->assertOk();
    enDemo($e['tutor'], 'PATCH', "/citas/{$cita['id']}", ['estado' => 'realizada'])->assertUnprocessable()->assertJsonValidationErrors('acuerdos');
    enDemo($e['tutor'], 'PATCH', "/citas/{$cita['id']}", ['estado' => 'realizada', 'acuerdos' => 'Reforzar lectura en casa.'])->assertOk();
    expect(collect(enDemo($familia, 'GET', '/citas?familia=1')->json('data'))->firstWhere('id', $cita['id']))->estado->toBe('realizada')->acuerdos->toBe('Reforzar lectura en casa.');
    enDemo(personaDemo('docente'), 'PATCH', "/citas/{$cita['id']}", ['estado' => 'cancelada'])->assertForbidden();
});

it('recibe reclamos sin sesión con número correlativo y copia, alerta antes de vencer y responde por correo', function () {
    $slug = dueno()->table('instituciones')->where('id', demoId())->value('slug');
    $this->getJson("/api/v1/colegios/$slug/libro-reclamaciones")->assertOk()->assertJsonStructure(['nombre', 'plazo_dias']);
    $this->getJson('/api/v1/colegios/no-existe/libro-reclamaciones')->assertNotFound();
    $base = ['tipo' => 'reclamo', 'nombre' => 'Consumidor Prueba', 'documento' => '45678912', 'email' => 'consumidor@correo.prueba', 'detalle' => 'Cobro de un taller que no se dictó.', 'pedido' => 'Devolución del monto.', 'acepta' => true];
    $this->postJson("/api/v1/colegios/$slug/libro-reclamaciones", ['email' => ''] + $base)->assertUnprocessable()->assertJsonValidationErrors('email');
    $a = $this->postJson("/api/v1/colegios/$slug/libro-reclamaciones", $base)->assertCreated();
    $b = $this->postJson("/api/v1/colegios/$slug/libro-reclamaciones", ['tipo' => 'queja'] + $base)->assertCreated();
    [$anio, $na] = explode('-', $a->json('numero'));
    expect($anio)->toBe((string) now()->year)->and((int) explode('-', $b->json('numero'))[1])->toBe((int) $na + 1);
    expect(dueno()->table('correos_bitacora')->where('destinatario', 'consumidor@correo.prueba')->count())->toBeGreaterThanOrEqual(2);

    $director = directorDemo();
    $id = dueno()->table('reclamaciones')->where('institucion_id', demoId())->where('numero', $a->json('numero'))->value('id');
    dueno()->table('reclamaciones')->where('id', $id)->update(['fecha_limite' => now()->addDays(2)->toDateString()]);
    $this->artisan('com:recordatorios')->assertSuccessful();
    expect(dueno()->table('reclamaciones')->find($id)->alerta_enviada_at)->not->toBeNull();
    $lista = collect(enDemo($director, 'GET', '/reclamos')->assertOk()->json('data'))->firstWhere('id', $id);
    expect($lista['dias_restantes'])->toBe(2);
    enDemo($director, 'POST', "/reclamos/$id/respuesta", ['respuesta' => 'corta'])->assertUnprocessable();
    enDemo($director, 'POST', "/reclamos/$id/respuesta", ['respuesta' => 'Se devolverá el monto del taller en la próxima semana.'])->assertOk();
    expect(dueno()->table('reclamaciones')->find($id)->estado)->toBe('respondido')->and(dueno()->table('reclamacion_respuestas')->where('reclamacion_id', $id)->exists())->toBeTrue();
    enDemo($director, 'POST', "/reclamos/$id/respuesta", ['respuesta' => 'Otra respuesta más larga que veinte caracteres.'])->assertUnprocessable();
});

it('muestra la bitácora con el costo de WhatsApp por mes y gestiona la morosidad por tramos', function () {
    $e = escenarioCom();
    $director = directorDemo();
    $b = enDemo($director, 'GET', '/notificaciones/bitacora?canal=whatsapp')->assertOk();
    $mes = now()->timezone('America/Lima')->format('Y-m');
    expect(collect($b->json('por_mes'))->where('mes', $mes)->firstWhere('canal', 'whatsapp')['costo'])->toBeGreaterThan(0);

    $tesoreria = personaDemo('tesoreria');
    $m = enDemo($tesoreria, 'GET', '/pagos/morosidad?tramo=31-60')->assertOk();
    $fam = collect($m->json('data'))->firstWhere('apoderado_id', $e['familias'][1]['apoderado']);
    expect($fam)->not->toBeNull()->and($fam['tramo'])->toBe('31-60')->and($fam['deuda'])->toEqual(350)->and($m->json('resumen.31-60.familias'))->toBeGreaterThanOrEqual(1);

    enDemo($tesoreria, 'POST', "/pagos/morosidad/{$e['familias'][1]['apoderado']}/gestiones", ['canal' => 'llamada', 'resultado' => 'Promete pagar', 'monto' => 350])->assertUnprocessable();
    $h = enDemo($tesoreria, 'POST', "/pagos/morosidad/{$e['familias'][1]['apoderado']}/gestiones", ['canal' => 'llamada', 'resultado' => 'Promete pagar el viernes', 'monto' => 350, 'fecha' => now()->addDays(3)->toDateString()])->assertCreated();
    expect($h->json('compromisos.0.estado'))->toBe('vigente')->and($h->json('gestiones.0.resultado'))->toBe('Promete pagar el viernes');
    expect(collect(enDemo($tesoreria, 'GET', '/pagos/morosidad')->json('data'))->firstWhere('apoderado_id', $e['familias'][1]['apoderado'])['compromiso']['monto'])->toEqual(350);

    $r = enDemo($tesoreria, 'POST', '/pagos/morosidad/recordatorio', ['tramo' => '31-60', 'canales' => ['app', 'correo']])->assertOk();
    expect($r->json('familias'))->toBeGreaterThanOrEqual(1)->and($r->json('enviados'))->toBeGreaterThanOrEqual(1);
    enDemo(personaDemo('docente'), 'GET', '/pagos/morosidad')->assertForbidden();
});
