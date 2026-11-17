<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
| S31 · EDUN-164 documentos de unidades por vencer · 166 la familia sigue la movilidad (llegada
| estimada, aviso al acercarse, bitácora) · 167 monitor con alerta por pérdida de señal ·
| 177 ficha de salud (enfermería y familia, auditada) · 178 campañas con autorización de los padres.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    foreach (['MOV', 'SAL'] as $codigo) {
        $m = $db->table('modulos_catalogo')->where('codigo', $codigo)->value('id');
        if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
            $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
        }
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    }
});

/** Ruta con un paradero, un alumno asignado y su madre; recorrido en curso del auxiliar. */
function rutaS31(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $seccion = (int) $db->table('secciones')->where('anio_escolar_id', $anio)->orderBy('id')->value('id');
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Ruta'.uniqid(), 'nombres' => 'Gael', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
    $u = $db->table('users')->insertGetId(['nombres' => 'Lupe', 'apellidos' => 'Ruta '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'lu.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Ruta', 'nombres' => 'Lupe']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre']);
    $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $placa = 'S'.random_int(10000, 99999);
    $unidad = $db->table('mov_unidades')->insertGetId(['institucion_id' => $inst, 'placa' => $placa, 'capacidad' => 20, 'soat_vence' => now()->addDays(7)->toDateString()]);
    $auxiliar = personaDemo('movilidad');
    $db->table('dispositivos_moviles')->insert(['user_id' => $auxiliar->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $aux = $db->table('mov_conductores')->insertGetId(['institucion_id' => $inst, 'nombres' => 'Aux Ruta', 'numero_documento' => (string) random_int(30000000, 39999999), 'rol' => 'auxiliar', 'user_id' => $auxiliar->id]);
    $ruta = $db->table('mov_rutas')->insertGetId(['institucion_id' => $inst, 'nombre' => 'Ruta '.uniqid(), 'turno' => 'manana', 'mov_unidad_id' => $unidad, 'auxiliar_id' => $aux]);
    $paradero = $db->table('mov_paraderos')->insertGetId(['institucion_id' => $inst, 'mov_ruta_id' => $ruta, 'nombre' => 'Parque Kennedy', 'orden' => 1, 'latitud' => -12.121, 'longitud' => -77.030, 'hora_estimada' => (now()->subMinutes(10)->isSameDay(now()) ? now()->subMinutes(10) : now()->startOfDay())->format('H:i:s')]);
    $asig = $db->table('mov_asignaciones')->insertGetId(['institucion_id' => $inst, 'alumno_id' => $alumno, 'mov_ruta_id' => $ruta, 'mov_paradero_id' => $paradero, 'anio_escolar_id' => $anio]);

    return ['alumno' => $alumno, 'familia' => User::query()->findOrFail($u), 'auxiliar' => $auxiliar, 'ruta' => $ruta, 'asignacion' => $asig, 'placa' => $placa, 'seccion' => $seccion];
}

it('EDUN-166 · la familia ve la llegada estimada solo durante el recorrido y recibe el aviso al acercarse', function () {
    $r = rutaS31();
    $antes = collect(enDemo($r['familia'], 'GET', '/familia/movilidad')->assertOk()->json('data'))->firstWhere('alumno_id', $r['alumno']);
    expect($antes['en_vivo'])->toBeNull()->and($antes['paradero'])->toBe('Parque Kennedy');

    $rec = enDemo($r['auxiliar'], 'POST', "/movilidad/rutas/{$r['ruta']}/recorridos", ['sentido' => 'ida'])->assertCreated()->json('recorrido_id');
    // Lejos (unos 2 km): llegada estimada sin aviso.
    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/posiciones", ['posiciones' => [['latitud' => -12.139, 'longitud' => -77.030, 'velocidad_kmh' => 30]]])->assertOk();
    $vivo = collect(enDemo($r['familia'], 'GET', '/familia/movilidad')->json('data'))->firstWhere('alumno_id', $r['alumno'])['en_vivo'];
    expect($vivo['distancia_m'])->toBeGreaterThan(1500)->and($vivo['llegada_min'])->toBeGreaterThan(0)->and($vivo['mapa_url'])->toContain('openstreetmap.org');
    expect(dueno()->table('mov_avisos_cercania')->where('mov_recorrido_id', $rec)->count())->toBe(0);

    // A unos 200 m: aviso una sola vez.
    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/posiciones", ['posiciones' => [['latitud' => -12.1228, 'longitud' => -77.030]]])->assertOk();
    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/posiciones", ['posiciones' => [['latitud' => -12.1215, 'longitud' => -77.030]]])->assertOk();
    expect(dueno()->table('mov_avisos_cercania')->where('mov_recorrido_id', $rec)->count())->toBe(1);
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'está llegando'));

    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/eventos", ['asignacion_id' => $r['asignacion'], 'tipo' => 'subio'])->assertSuccessful();
    $bitacora = collect(enDemo($r['familia'], 'GET', '/familia/movilidad')->json('data'))->firstWhere('alumno_id', $r['alumno'])['bitacora'];
    expect(collect($bitacora)->pluck('tipo')->all())->toBe(['subio']);
    // Otra familia no ve esta ruta.
    expect(collect(enDemo(rutaS31()['familia'], 'GET', '/familia/movilidad')->json('data'))->pluck('alumno_id'))->not->toContain($r['alumno']);
});

it('EDUN-167 / 164 · el monitor marca el retraso y la pérdida de señal; los documentos por vencer avisan', function () {
    $r = rutaS31();
    $rec = enDemo($r['auxiliar'], 'POST', "/movilidad/rutas/{$r['ruta']}/recorridos", ['sentido' => 'ida'])->assertCreated()->json('recorrido_id');
    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/posiciones", ['posiciones' => [['latitud' => -12.139, 'longitud' => -77.030]]])->assertOk();
    $coordinador = personaDemo('movilidad');
    dueno()->table('dispositivos_moviles')->insert(['user_id' => $coordinador->id, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
    $fila = collect(enDemo($coordinador, 'GET', '/movilidad/monitor')->assertOk()->json('data'))->firstWhere('id', $rec);
    expect($fila['sin_senal'])->toBeFalse()->and($fila['siguiente_paradero'])->toBe('Parque Kennedy')->and($fila['retraso_min'])->toBe(max(0, (int) floor(now()->startOfDay()->setTimeFromTimeString(dueno()->table('mov_paraderos')->where('mov_ruta_id', $r['ruta'])->value('hora_estimada'))->diffInSeconds(now(), false) / 60)));

    // Sin señal hace 4 minutos: una alerta, no dos.
    dueno()->table('mov_recorridos')->where('id', $rec)->update(['ultima_posicion_at' => now()->subMinutes(4)]);
    Artisan::call('mov:vigilar');
    Artisan::call('mov:vigilar');
    expect(dueno()->table('mov_recorridos')->find($rec)->alerta_senal_at)->not->toBeNull();
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), 'Sin señal'));
    expect(collect(enDemo($coordinador, 'GET', '/movilidad/monitor')->json('data'))->firstWhere('id', $rec)['sin_senal'])->toBeTrue();
    // Vuelve la señal: la alerta se limpia.
    enDemo($r['auxiliar'], 'POST', "/movilidad/recorridos/{$rec}/posiciones", ['posiciones' => [['latitud' => -12.138, 'longitud' => -77.030]]])->assertOk();
    expect(dueno()->table('mov_recorridos')->find($rec)->alerta_senal_at)->toBeNull();

    Artisan::call('mov:vencimientos');
    Http::assertSent(fn ($q) => str_contains(json_encode($q->data(), JSON_UNESCAPED_UNICODE), "SOAT de {$r['placa']}"));
    enDemo($r['familia'], 'GET', '/movilidad/monitor')->assertForbidden();
});

it('EDUN-177 · enfermería y la familia mantienen la ficha de salud; cada consulta queda en la auditoría', function () {
    $r = rutaS31();
    $enfermeria = personaDemo('enfermeria');
    enDemo($enfermeria, 'PUT', "/salud/fichas/{$r['alumno']}", ['alergias' => 'Penicilina', 'telefono_emergencia' => 'abc'])->assertUnprocessable();
    enDemo($enfermeria, 'PUT', "/salud/fichas/{$r['alumno']}", ['alergias' => 'Penicilina', 'grupo_sanguineo' => 'O+', 'telefono_emergencia' => '987654321'])->assertOk()->assertJsonPath('ficha.alergias', 'Penicilina');
    // La familia completa la medicación desde la app.
    enDemo($r['familia'], 'PUT', "/familia/salud/{$r['alumno']}", ['medicacion' => 'Salbutamol si hay crisis'])->assertOk();
    $f = enDemo($enfermeria, 'GET', "/salud/fichas/{$r['alumno']}")->assertOk();
    expect($f->json('ficha.medicacion'))->toBe('Salbutamol si hay crisis')->and($f->json('ficha.alergias'))->toBe('Penicilina');
    expect(collect(enDemo($enfermeria, 'GET', '/salud/fichas?buscar='.urlencode('Gael'))->json('data'))->firstWhere('alumno_id', $r['alumno'])['alertas'])->toBeTrue();
    expect(dueno()->table('auditoria')->where('accion', 'salud_consultada')->where('registro_id', $r['alumno'])->count())->toBeGreaterThanOrEqual(2);
    // Sin el permiso o sin ser su hijo, no.
    enDemo(personaDemo('docente'), 'GET', "/salud/fichas/{$r['alumno']}")->assertForbidden();
    enDemo(rutaS31()['familia'], 'GET', "/familia/salud/{$r['alumno']}")->assertForbidden();
});

it('EDUN-178 · campaña con autorización que los padres firman en la app y lista de autorizados', function () {
    $r = rutaS31();
    $enfermeria = personaDemo('enfermeria');
    $c = enDemo($enfermeria, 'POST', '/salud/campanas', ['nombre' => 'Vacunación VPH', 'fecha' => now()->addDays(5)->toDateString(), 'descripcion' => 'Vacuna gratuita del MINSA para 5.° grado.', 'secciones' => [$r['seccion']]])->assertCreated();
    expect($c->json('pendientes'))->toBe($c->json('total'));
    $aut = collect(enDemo($r['familia'], 'GET', '/familia/autorizaciones')->assertOk()->json('data'))->firstWhere('alumno_id', $r['alumno']);
    expect($aut['titulo'])->toBe('Campaña de salud: Vacunación VPH');
    enDemo($r['familia'], 'POST', "/familia/autorizaciones/{$aut['id']}/firma", ['alumno_id' => $r['alumno'], 'acepta' => true])->assertOk();
    $lista = collect(enDemo($enfermeria, 'GET', "/salud/campanas/{$c->json('id')}/autorizados")->assertOk()->json('data'))->firstWhere('alumno_id', $r['alumno']);
    expect($lista['estado'])->toBe('autorizado');
    expect(collect(enDemo($enfermeria, 'GET', '/salud/campanas')->json('data'))->firstWhere('id', $c->json('id'))['autorizados'])->toBe(1);
});
