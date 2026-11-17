<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| S27–S29 · Aula virtual: EDUN-123 cursos desde la carga · 124 unidades y materiales · 125–127 tareas
| y entregas · 128/130/137/138 bancos y cuestionarios · 129 preguntas con IA · 131 foros · 132 clases
| en vivo · 133 seguimiento · 134 tareas de los hijos · 135/136 avance en orden y video · 139 encuesta ·
| 140 observaciones · 141 avisos.
*/

beforeEach(function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $m = $db->table('modulos_catalogo')->where('codigo', 'AUL')->value('id');
    if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
        $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
    }
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
});

function postAula(User $u, string $url, array $datos)
{
    actuarComo($u, true, demoId());

    return test()->post('/api/v1'.$url, $datos, ['X-Institucion' => (string) demoId(), 'Accept' => 'application/json']);
}

/** Docente con carga en una sección nueva de 2027, dos alumnos con cuenta y la madre del primero. */
function aulaS27(): array
{
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = $db->table('secciones')->where('anio_escolar_id', $anio)->value('grado_id');
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'Z'.random_int(10, 99999)]);
    $area = $db->table('areas_curriculares')->where('nivel', $db->table('grados')->where('id', $grado)->value('nivel'))->orderBy('orden')->value('id');
    $docente = personaDemo('docente');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $docente->id, 'nombres' => 'Profe', 'apellidos' => 'Aula '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999), 'cargo' => 'Docente']);
    $db->table('cargas_docentes')->insert(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal, 'horas_semanales' => 4]);
    $alumnos = [];
    foreach (['Uno', 'Dos'] as $n) {
        $u = $db->table('users')->insertGetId(['nombres' => "Alumno{$n}", 'apellidos' => 'Aula '.uniqid(), 'numero_documento' => (string) random_int(70000000, 79999999), 'email' => 'al.'.uniqid().'@alumno.prueba', 'password' => Hash::make('x')]);
        $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'alumno')->value('id')]);
        $a = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'user_id' => $u, 'apellido_paterno' => 'Aula'.$n, 'nombres' => $n, 'numero_documento' => (string) random_int(60000000, 69999999)]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'origen' => 'manual']);
        $db->table('dispositivos_moviles')->insert(['user_id' => $u, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);
        $alumnos[] = ['id' => $a, 'user' => User::query()->findOrFail($u)];
    }
    $mu = $db->table('users')->insertGetId(['nombres' => 'Madre', 'apellidos' => 'Aula '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'ma.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $mu, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $mu, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Aula', 'nombres' => 'Madre']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumnos[0]['id'], 'apoderado_id' => $apo, 'parentesco' => 'madre']);
    $db->table('dispositivos_moviles')->insert(['user_id' => $mu, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);

    $curso = collect(enDemo($docente, 'GET', '/aula/cursos')->assertOk()->json('data'))->firstWhere('seccion_id', $seccion);

    return ['curso' => $curso['id'], 'docente' => $docente, 'alumnos' => $alumnos, 'madre' => User::query()->findOrFail($mu), 'seccion' => $seccion];
}

it('EDUN-123 / 124 / 135 / 136 · el curso nace de la carga; el alumno avanza en orden y el video se completa al 80 %', function () {
    $a = aulaS27();
    $c = $a['curso'];
    expect($c)->toBeInt();
    $alumno = $a['alumnos'][0]['user'];
    expect(collect(enDemo($alumno, 'GET', '/aula/cursos')->assertOk()->json('data'))->pluck('id'))->toContain($c);
    // Otro docente no ve el curso.
    enDemo(personaDemo('docente'), 'GET', "/aula/cursos/{$c}")->assertNotFound();

    $u = enDemo($a['docente'], 'POST', "/aula/cursos/{$c}/unidades", ['titulo' => 'Unidad 1'])->assertCreated()->json('id');
    $texto = postAula($a['docente'], "/aula/cursos/{$c}/unidades/{$u}/recursos", ['tipo' => 'texto', 'titulo' => 'Lectura', 'contenido' => 'Hola'])->assertCreated()->json('id');
    $video = postAula($a['docente'], "/aula/cursos/{$c}/unidades/{$u}/recursos", ['tipo' => 'video', 'titulo' => 'Video', 'archivo' => UploadedFile::fake()->create('clase.mp4', 500, 'video/mp4')])->assertCreated()->json('id');
    postAula($a['docente'], "/aula/cursos/{$c}/unidades/{$u}/recursos", ['tipo' => 'enlace', 'titulo' => 'Sin url'])->assertUnprocessable();
    postAula($alumno, "/aula/cursos/{$c}/unidades/{$u}/recursos", ['tipo' => 'texto', 'titulo' => 'Intruso', 'contenido' => 'x'])->assertForbidden();

    $vista = enDemo($alumno, 'GET', "/aula/cursos/{$c}")->assertOk();
    $recursos = collect($vista->json('unidades.0.recursos'))->keyBy('id');
    expect($vista->json('rol'))->toBe('alumno')->and($recursos[$texto]['bloqueado'])->toBeFalse()->and($recursos[$video]['bloqueado'])->toBeTrue()
        ->and($vista->json('continuar.recurso_id'))->toBe($texto);
    // Bloqueado: no se abre ni avanza.
    enDemo($alumno, 'POST', "/aula/cursos/{$c}/recursos/{$video}/avance", ['porcentaje' => 50])->assertUnprocessable();
    enDemo($alumno, 'POST', "/aula/cursos/{$c}/recursos/{$texto}/avance", ['porcentaje' => 100, 'segundos' => 60])->assertOk()->assertJsonPath('completado', true);

    // Video sin adelantar: un salto grande no cuenta; avanzando de a poco se completa al 80 %.
    expect(enDemo($alumno, 'POST', "/aula/cursos/{$c}/recursos/{$video}/avance", ['porcentaje' => 90])->assertOk()->json('porcentaje'))->toBe(0);
    foreach ([15, 30, 45, 60, 75] as $p) {
        enDemo($alumno, 'POST', "/aula/cursos/{$c}/recursos/{$video}/avance", ['porcentaje' => $p, 'segundos' => $p * 3])->assertOk()->assertJsonPath('completado', false);
    }
    $fin = enDemo($alumno, 'POST', "/aula/cursos/{$c}/recursos/{$video}/avance", ['porcentaje' => 82, 'segundos' => 250])->assertOk();
    expect($fin->json('completado'))->toBeTrue()->and($fin->json('avance'))->toBe(100);
    expect(enDemo($alumno, 'GET', "/aula/cursos/{$c}/recursos/{$video}/archivo")->assertOk()->json('url'))->toBeString();
});

it('EDUN-125 / 126 / 127 / 134 / 141 · tareas: el alumno entrega desde el celular, el docente revisa y la familia lo ve', function () {
    $a = aulaS27();
    $c = $a['curso'];
    $u = enDemo($a['docente'], 'POST', "/aula/cursos/{$c}/unidades", ['titulo' => 'Unidad 1'])->json('id');
    $t = postAula($a['docente'], "/aula/cursos/{$c}/tareas", ['aula_unidad_id' => $u, 'titulo' => 'Mapa del Perú', 'instrucciones' => 'Dibuja el mapa', 'vence_at' => now()->addDay()->toIso8601String(), 'publicar' => 1])->assertCreated();
    Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Nueva tarea: Mapa del Perú'));
    $tid = $t->json('id');
    $alumno = $a['alumnos'][0]['user'];
    expect(enDemo($alumno, 'GET', '/aula/cursos')->json('data'))->toBeArray();
    postAula($alumno, "/aula/cursos/{$c}/tareas/{$tid}/entrega", [])->assertUnprocessable();
    postAula($alumno, "/aula/cursos/{$c}/tareas/{$tid}/entrega", ['archivo' => UploadedFile::fake()->create('mapa.jpg', 120, 'image/jpeg')])->assertOk()->assertJsonPath('tardia', false);

    $e = enDemo($a['docente'], 'GET', "/aula/cursos/{$c}/tareas/{$tid}/entregas")->assertOk();
    $estados = collect($e->json('alumnos'))->pluck('estado', 'alumno_id');
    expect($estados[$a['alumnos'][0]['id']])->toBe('entregada')->and($estados[$a['alumnos'][1]['id']])->toBe('pendiente');
    expect(enDemo($a['docente'], 'GET', "/aula/cursos/{$c}/tareas/{$tid}/entregas/{$a['alumnos'][0]['id']}/archivo")->assertOk()->json('url'))->toBeString();
    enDemo($a['docente'], 'POST', "/aula/cursos/{$c}/tareas/{$tid}/entregas/{$a['alumnos'][0]['id']}/revision", ['nota' => 'X'])->assertUnprocessable();
    enDemo($a['docente'], 'POST', "/aula/cursos/{$c}/tareas/{$tid}/entregas/{$a['alumnos'][0]['id']}/revision", ['nota' => 'a', 'comentario' => 'Muy bien'])->assertOk()->assertJsonPath('estado', 'revisada');
    // Revisada: ya no se cambia.
    postAula($alumno, "/aula/cursos/{$c}/tareas/{$tid}/entrega", ['texto' => 'otra'])->assertUnprocessable();

    $hijos = collect(enDemo($a['madre'], 'GET', '/aula/familia/tareas')->assertOk()->json('data'))->firstWhere('id', $tid);
    expect($hijos['estado'])->toBe('revisada')->and($hijos['nota'])->toBe('A');

    // Tarea vencida que no acepta tardías.
    $t2 = postAula($a['docente'], "/aula/cursos/{$c}/tareas", ['aula_unidad_id' => $u, 'titulo' => 'Vencida', 'instrucciones' => 'Hazla ya', 'vence_at' => now()->subHour()->toIso8601String(), 'acepta_tardias' => 0, 'publicar' => 1])->json('id');
    postAula($a['alumnos'][1]['user'], "/aula/cursos/{$c}/tareas/{$t2}/entrega", ['texto' => 'tarde'])->assertUnprocessable();

    enDemo($a['docente'], 'POST', "/aula/cursos/{$c}/avisos", ['texto' => 'Mañana hay examen'])->assertCreated();
    expect(enDemo($alumno, 'GET', "/aula/cursos/{$c}")->json('avisos.0.texto'))->toBe('Mañana hay examen');
    enDemo($alumno, 'POST', "/aula/cursos/{$c}/avisos", ['texto' => 'No me dejan publicar'])->assertForbidden();
});

it('EDUN-128 / 129 / 130 / 137 / 138 · cuestionario con banco: corrección automática, intentos, extra y la mejor nota', function () {
    $a = aulaS27();
    $c = $a['curso'];
    $d = $a['docente'];
    $u = enDemo($d, 'POST', "/aula/cursos/{$c}/unidades", ['titulo' => 'Unidad 1'])->json('id');
    $banco = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos", ['nombre' => 'Banco del curso', 'alcance' => 'curso'])->assertCreated()->json('id');
    $p1 = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/preguntas", ['tipo' => 'opcion_multiple', 'enunciado' => '¿2+2?', 'opciones' => ['3', '4', '5'], 'respuesta_correcta' => 1])->assertCreated()->json('id');
    $p2 = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/preguntas", ['tipo' => 'verdadero_falso', 'enunciado' => 'Lima es la capital', 'respuesta_correcta' => true])->json('id');
    $p3 = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/preguntas", ['tipo' => 'relacionar', 'enunciado' => 'Relaciona', 'opciones' => ['izquierda' => ['Perú', 'Chile'], 'derecha' => ['Santiago', 'Lima']], 'respuesta_correcta' => ['0' => 1, '1' => 0]])->json('id');
    $p4 = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/preguntas", ['tipo' => 'respuesta_corta', 'enunciado' => 'Río más largo del Perú', 'respuesta_correcta' => ['Amazonas', 'río Amazonas']])->json('id');
    enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/preguntas", ['tipo' => 'opcion_multiple', 'enunciado' => 'Mala', 'opciones' => ['a'], 'respuesta_correcta' => 3])->assertUnprocessable();

    // IA (simulada sin clave): quedan marcadas e inactivas hasta revisarlas.
    $ia = enDemo($d, 'POST', "/aula/cursos/{$c}/bancos/{$banco}/ia", ['tema' => 'Fracciones', 'cantidad' => 3])->assertOk();
    expect($ia->json('proveedor'))->toBe('simulado')->and($ia->json('data'))->toHaveCount(3)
        ->and(collect($ia->json('data'))->every(fn ($p) => $p['generada_ia'] && ! $p['activa']))->toBeTrue();
    enDemo($d, 'POST', "/aula/cursos/{$c}/cuestionarios", ['aula_unidad_id' => $u, 'titulo' => 'Con IA', 'preguntas' => [['id' => $ia->json('data.0.id')]]])->assertUnprocessable();

    $q = enDemo($d, 'POST', "/aula/cursos/{$c}/cuestionarios", ['aula_unidad_id' => $u, 'titulo' => 'Repaso', 'intentos_max' => 1, 'conserva' => 'mejor', 'requiere_previos' => false,
        'preguntas' => [['id' => $p1], ['id' => $p2], ['id' => $p3], ['id' => $p4]], 'publicar' => true])->assertCreated()->json('id');
    $alumno = $a['alumnos'][0];
    $i = enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/intento")->assertOk();
    expect($i->json('preguntas'))->toHaveCount(4)->and($i->json('preguntas.0'))->not->toHaveKey('respuesta_correcta');
    // Relacionar con un par errado no vale; tildes y mayúsculas no importan en respuesta corta.
    $r = enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/envio", ['respuestas' => [$p1 => 1, $p2 => true, $p3 => ['0' => 1, '1' => 1], $p4 => 'RÍO amazonas']])->assertOk();
    expect($r->json('porcentaje'))->toEqual(75)->and($r->json('nota'))->toBe('A')->and($r->json('intentos_disponibles'))->toBe(0)->and($r->json('revision'))->toHaveCount(4);
    enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/intento")->assertUnprocessable();

    // El docente da un intento extra; se conserva la mejor nota.
    enDemo($d, 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/intentos-extra", ['alumno_id' => $alumno['id'], 'cantidad' => 1])->assertOk();
    enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/intento")->assertOk();
    $r2 = enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/cuestionarios/{$q}/envio", ['respuestas' => [$p1 => 0]])->assertOk();
    expect($r2->json('porcentaje'))->toEqual(0)->and($r2->json('nota_final.porcentaje'))->toEqual(75);
    $res = collect(enDemo($d, 'GET', "/aula/cursos/{$c}/cuestionarios/{$q}")->assertOk()->json('resultados'))->firstWhere('alumno_id', $alumno['id']);
    expect($res['intentos'])->toBe(2)->and($res['nota_final']['nota'])->toBe('A');
});

it('EDUN-131 / 132 / 133 / 139 / 140 · foros, clases en vivo con recordatorio, encuesta anónima, observaciones y seguimiento', function () {
    $a = aulaS27();
    $c = $a['curso'];
    $d = $a['docente'];
    $alumno = $a['alumnos'][0];
    $foro = enDemo($d, 'POST', "/aula/cursos/{$c}/foros", ['titulo' => 'Dudas'])->assertCreated()->json('id');
    $m = enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/foros/{$foro}", ['contenido' => '¿Para cuándo es?'])->assertCreated()->json('id');
    enDemo($d, 'POST', "/aula/cursos/{$c}/foros/{$foro}", ['contenido' => 'Para el lunes', 'respuesta_a' => $m])->assertCreated();
    enDemo($d, 'PATCH', "/aula/cursos/{$c}/foros/{$foro}/mensajes/{$m}", ['oculto' => true])->assertOk();
    expect(enDemo($a['alumnos'][1]['user'], 'GET', "/aula/cursos/{$c}/foros/{$foro}")->assertOk()->json('data'))->toHaveCount(1);
    enDemo($a['madre'], 'POST', "/aula/cursos/{$c}/foros/{$foro}", ['contenido' => 'Hola'])->assertForbidden();

    enDemo($d, 'POST', "/aula/cursos/{$c}/clases", ['titulo' => 'Repaso', 'inicio' => now()->addMinutes(10)->toIso8601String(), 'plataforma' => 'meet', 'url' => 'http://meet.google.com/x'])->assertUnprocessable();
    $clase = enDemo($d, 'POST', "/aula/cursos/{$c}/clases", ['titulo' => 'Repaso', 'inicio' => now()->addMinutes(10)->toIso8601String(), 'plataforma' => 'meet', 'url' => 'https://meet.google.com/abc'])->assertCreated()->json('id');
    Artisan::call('aul:recordatorios');
    expect(dueno()->table('aula_clases_vivo')->find($clase)->recordado_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'empieza pronto'));
    enDemo($d, 'PUT', "/aula/cursos/{$c}/clases/{$clase}", ['titulo' => 'Repaso', 'inicio' => now()->addMinutes(10)->toIso8601String(), 'plataforma' => 'meet', 'url' => 'https://meet.google.com/abc', 'grabacion_url' => 'https://drive.google.com/grabacion'])->assertOk();
    expect(enDemo($alumno['user'], 'GET', "/aula/cursos/{$c}/clases")->assertOk()->json('data.0.grabacion_url'))->toBe('https://drive.google.com/grabacion');

    $u = enDemo($d, 'POST', "/aula/cursos/{$c}/unidades", ['titulo' => 'Unidad 1'])->json('id');
    enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/unidades/{$u}/encuesta", ['valor' => 4, 'comentario' => 'Me gustó'])->assertOk();
    enDemo($alumno['user'], 'POST', "/aula/cursos/{$c}/unidades/{$u}/encuesta", ['valor' => 1])->assertUnprocessable();
    $enc = collect(enDemo($d, 'GET', "/aula/cursos/{$c}/encuestas")->assertOk()->json('data'))->firstWhere('unidad_id', $u);
    expect($enc['respuestas'])->toBe(1)->and($enc['promedio'])->toEqual(4.0)->and($enc['comentarios'])->toBe(['Me gustó'])->and($enc)->not->toHaveKey('alumno');

    enDemo($d, 'POST', "/aula/cursos/{$c}/observaciones", ['alumno_id' => $alumno['id'], 'tipo' => 'fortaleza', 'texto' => 'Participa mucho'])->assertCreated();
    enDemo($alumno['user'], 'GET', "/aula/cursos/{$c}/observaciones")->assertForbidden();
    $s = collect(enDemo($d, 'GET', "/aula/cursos/{$c}/seguimiento")->assertOk()->json('alumnos'))->firstWhere('alumno_id', $alumno['id']);
    expect($s['observaciones'])->toBe(1)->and($s['ultima_actividad'])->not->toBeNull();
    // Coordinación sigue el curso aunque no lo dicte.
    expect(enDemo(personaDemo('coordinador'), 'GET', "/aula/cursos/{$c}/seguimiento")->assertOk()->json('alumnos'))->toHaveCount(2);
    expect(enDemo($d, 'POST', "/aula/cursos/{$c}/seguimiento/{$alumno['id']}/aviso", ['mensaje' => 'Le falta entregar dos tareas'])->assertOk()->json('enviados'))->toBe(1);
});
