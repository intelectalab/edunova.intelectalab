<?php

use App\Models\User;
use App\Modulos\MDL\Services\ClienteMoodle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
| S30 · Moodle conectado: EDUN-143 probar la conexión · 144 ingreso único · 145 cursos del año ·
| 146 usuarios y matrículas (retiro suspende) · 147 notas de Moodle · 148 aprobar notas · 149 imagen.
| El conector usa el Moodle simulado (services.moodle.simular).
*/

beforeEach(function () {
    config(['services.moodle.simular' => true]);
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $m = $db->table('modulos_catalogo')->where('codigo', 'MDL')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    // Cada prueba parte de un conector y un Moodle simulado limpios.
    $db->table('moodle_notas_recibidas')->where('institucion_id', demoId())->delete();
    $db->table('moodle_cursos')->where('institucion_id', demoId())->delete();
    $db->table('moodle_usuarios')->where('institucion_id', demoId())->delete();
    $db->table('moodle_sincronizaciones')->where('institucion_id', demoId())->delete();
    $db->table('moodle_conexiones')->where('institucion_id', demoId())->delete();
    Cache::flush();
});

/** Docente con carga en una sección nueva de 2027 y un alumno con cuenta. */
function moodleS30(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = $db->table('secciones')->where('anio_escolar_id', $anio)->value('grado_id');
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'M'.random_int(10, 99999)]);
    $area = $db->table('areas_curriculares')->where('nivel', $db->table('grados')->where('id', $grado)->value('nivel'))->orderBy('orden')->value('id');
    $docente = personaDemo('docente');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $docente->id, 'nombres' => 'Profe', 'apellidos' => 'Moodle '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999), 'cargo' => 'Docente']);
    $carga = $db->table('cargas_docentes')->insertGetId(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal, 'horas_semanales' => 4]);
    $u = $db->table('users')->insertGetId(['nombres' => 'Alumna', 'apellidos' => 'Moodle '.uniqid(), 'numero_documento' => (string) random_int(70000000, 79999999), 'email' => 'mo.'.uniqid().'@alumno.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'alumno')->value('id')]);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'apellido_paterno' => 'Moodle', 'nombres' => 'Alumna', 'numero_documento' => (string) random_int(60000000, 69999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);

    return ['docente' => $docente, 'carga' => $carga, 'alumno' => $alumno, 'usuario_alumno' => User::query()->findOrFail($u), 'area' => $area, 'anio' => $anio, 'seccion' => $seccion];
}

it('EDUN-143 / 144 · prueba la conexión, genera el secreto y arma el enlace de ingreso de un minuto', function () {
    $director = personaDemo('director');
    enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'https://aula.colegio.pe', 'token' => 'invalido-'.str_repeat('x', 20)])->assertOk();
    expect(enDemo($director, 'POST', '/moodle/conexion/prueba')->assertOk()->json('ok'))->toBeFalse();
    expect(enDemo($director, 'GET', '/moodle/conexion')->json('estado'))->toBe('error');

    enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'https://aula.colegio.pe', 'token' => str_repeat('t', 32)])->assertOk();
    $p = enDemo($director, 'POST', '/moodle/conexion/prueba')->assertOk();
    expect($p->json('ok'))->toBeTrue()->and($p->json('version'))->toContain('4.5');

    $secreto = enDemo($director, 'POST', '/moodle/sso/secreto')->assertOk()->json('secreto');
    expect(strlen($secreto))->toBe(48)->and(enDemo($director, 'GET', '/moodle/conexion')->json())->not->toHaveKey('secreto');

    $m = moodleS30();
    // Sin cuenta en Moodle todavía no hay enlace.
    enDemo($m['usuario_alumno'], 'GET', '/moodle/sso')->assertUnprocessable();
    enDemo($director, 'POST', '/moodle/sincronizacion', ['tipo' => 'todo'])->assertOk();
    $url = enDemo($m['usuario_alumno'], 'GET', '/moodle/sso')->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect($url)->toStartWith('https://aula.colegio.pe/auth/edunova/login.php?')
        ->and((int) $q['t'])->toBeLessThanOrEqual(now()->addMinute()->timestamp)
        ->and($q['s'])->toBe(hash_hmac('sha256', "{$q['u']}|{$q['t']}", $secreto));

    enDemo($director, 'POST', '/moodle/conexion/pausa', ['pausado' => true])->assertOk();
    enDemo($director, 'POST', '/moodle/sincronizacion', ['tipo' => 'cursos'])->assertUnprocessable();
    enDemo(personaDemo('docente'), 'POST', '/moodle/conexion/prueba')->assertForbidden();
});

it('EDUN-145 / 146 / 147 / 148 · crea cursos y usuarios, el retiro suspende y las notas se aprueban al registro', function () {
    $director = personaDemo('director');
    enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'https://aula2.colegio.pe', 'token' => str_repeat('k', 32)])->assertOk();
    $m = moodleS30();
    $r = enDemo($director, 'POST', '/moodle/sincronizacion', ['tipo' => 'todo'])->assertOk();
    expect($r->json('cursos.registros'))->toBeGreaterThanOrEqual(1)->and($r->json('cursos.errores'))->toBe([]);
    $curso = dueno()->table('moodle_cursos')->where('carga_docente_id', $m['carga'])->first();
    expect($curso->estado)->toBe('creado')->and($curso->moodle_course_id)->not->toBeNull();
    $simulado = ClienteMoodle::estadoSimulado('https://aula2.colegio.pe');
    $mu = dueno()->table('moodle_usuarios')->where('user_id', $m['usuario_alumno']->id)->first();
    expect($mu->username)->toBe('edu'.demoId().'-'.$m['usuario_alumno']->id)
        ->and($simulado['matriculas']["{$curso->moodle_course_id}-{$mu->moodle_user_id}"])->toBe(5)
        ->and($simulado['matriculas']["{$curso->moodle_course_id}-".dueno()->table('moodle_usuarios')->where('user_id', $m['docente']->id)->value('moodle_user_id')])->toBe(3);

    // Notas recibidas: pendientes y convertidas (15/20 = 75 % → A, con la tabla por defecto).
    $notas = enDemo($m['docente'], 'GET', '/moodle/notas')->assertOk();
    $nota = collect($notas->json('data'))->firstWhere('alumno', 'Moodle, Alumna');
    expect($nota)->not->toBeNull()->and($nota['estado'])->toBe('pendiente');
    expect(in_array($nota['valor_convertido'], ['A', 'AD'], true))->toBeTrue();
    // Otro docente no ve las notas de este curso.
    expect(collect(enDemo(personaDemo('docente'), 'GET', '/moodle/notas')->json('data'))->pluck('id'))->not->toContain($nota['id']);

    $competencia = dueno()->table('competencias')->where('area_curricular_id', $m['area'])->value('id');
    $periodo = dueno()->table('periodos')->where('anio_escolar_id', $m['anio'])->where('estado', '!=', 'cerrado')->orderBy('numero')->value('id');
    enDemo($m['docente'], 'POST', '/moodle/notas/resolucion', ['ids' => [$nota['id']], 'decision' => 'aprobar'])->assertUnprocessable();
    $a = enDemo($m['docente'], 'POST', '/moodle/notas/resolucion', ['ids' => [$nota['id']], 'decision' => 'aprobar', 'competencia_id' => $competencia, 'periodo_id' => $periodo])->assertOk();
    expect($a->json('procesadas'))->toBe(1);
    $cal = dueno()->table('calificaciones')->where('alumno_id', $m['alumno'])->where('competencia_id', $competencia)->where('periodo_id', $periodo)->first();
    expect($cal->origen)->toBe('moodle')->and($cal->valor)->toBe($nota['valor_convertido']);

    // Una nota aprobada no se pisa en la siguiente sincronización.
    enDemo($director, 'POST', '/moodle/sincronizacion', ['tipo' => 'notas'])->assertOk();
    expect(dueno()->table('moodle_notas_recibidas')->find($nota['id'])->estado)->toBe('aprobada');

    // Retiro: se suspende en Moodle, no se borra.
    dueno()->table('matriculas')->where('alumno_id', $m['alumno'])->update(['estado' => 'retirado']);
    enDemo($director, 'POST', '/moodle/sincronizacion', ['tipo' => 'usuarios'])->assertOk();
    expect(dueno()->table('moodle_usuarios')->find($mu->id)->suspendido)->toBeTrue()
        ->and(ClienteMoodle::estadoSimulado('https://aula2.colegio.pe')['usuarios'][$mu->moodle_user_id]['suspended'])->toBe(1);

    $bitacora = enDemo($director, 'GET', '/moodle/bitacora')->assertOk()->json('data');
    expect(collect($bitacora)->pluck('tipo')->unique()->values()->all())->toEqualCanonicalizing(['cursos', 'usuarios', 'notas']);
});

it('EDUN-147 / 149 · tabla de conversión del colegio, imagen para el tema y sincronización programada', function () {
    $director = personaDemo('director');
    enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'https://aula3.colegio.pe', 'token' => str_repeat('z', 32)])->assertOk();
    enDemo($director, 'PUT', '/moodle/conversion', ['rangos' => [['desde' => 95, 'valor' => 'AD'], ['desde' => 80, 'valor' => 'A']]])->assertUnprocessable();
    enDemo($director, 'PUT', '/moodle/conversion', ['rangos' => [['desde' => 95, 'valor' => 'AD'], ['desde' => 80, 'valor' => 'A'], ['desde' => 60, 'valor' => 'B'], ['desde' => 0, 'valor' => 'C']]])->assertOk();
    $m = moodleS30();
    Artisan::call('mdl:sincronizar');
    $nota = dueno()->table('moodle_notas_recibidas as n')->join('moodle_cursos as c', 'c.id', '=', 'n.moodle_curso_id')->where('c.carga_docente_id', $m['carga'])->first(['n.*']);
    $pct = $nota->nota_numerica * 100 / 20;
    expect($nota->valor_convertido)->toBe($pct >= 95 ? 'AD' : ($pct >= 80 ? 'A' : ($pct >= 60 ? 'B' : 'C')));
    expect(dueno()->table('moodle_conexiones')->where('institucion_id', demoId())->value('ultima_sincronizacion_at'))->not->toBeNull();

    $img = enDemo($director, 'GET', '/moodle/imagen')->assertOk();
    expect($img->json('scss_previo'))->toContain('$primary')->and($img->json('pasos'))->toHaveCount(4);
});
