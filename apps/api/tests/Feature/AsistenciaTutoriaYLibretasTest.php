<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 19 · CAL:
| EDUN-94 asistencia desde el celular · EDUN-95 asistencia por QR · EDUN-99 libretas · EDUN-100 notas en la app
| EDUN-101 conducta · EDUN-102 entrevistas · EDUN-103 alertas · EDUN-104 acta y méritos · EDUN-105 recuperación
*/

beforeEach(function () {
    Http::fake();
    Storage::fake('objetos');
});

afterEach(fn () => Carbon::setTestNow());

/** Sección propia (3.° secundaria «Q»), tutor con usuario, dos alumnos (uno con apoderado con usuario) y su curso de Matemática. */
function seccionS19(): array
{
    static $s = null;
    if ($s) {
        return $s;
    }
    $db = dueno();
    $inst = demoId();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $tutor = personaDemo('tutor');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $tutor->id, 'numero_documento' => (string) random_int(10000000, 99999999), 'apellidos' => 'Tutora S19', 'nombres' => 'Rosa', 'cargo' => 'Docente']);
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'Q', 'tutor_personal_id' => $personal]);
    $area = $db->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
    $db->table('cargas_docentes')->insert(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal]);
    $alumnos = [];
    foreach (['Carla', 'Diego'] as $i => $n) {
        $a = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'S19', 'nombres' => $n, 'numero_documento' => (string) random_int(70000000, 79999999),
            'codigo_carne' => 'S19'.strtoupper(substr(uniqid(), -6)).$i]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion]);
        $alumnos[] = $a;
    }
    $padreId = $db->table('users')->insertGetId(['nombres' => 'Padre', 'apellidos' => 'S19 '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'padre.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $padreId, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $padreId, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'S19', 'nombres' => 'Padre']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumnos[0], 'apoderado_id' => $apo, 'parentesco' => 'padre', 'es_responsable_pago' => true]);
    $db->table('dispositivos_moviles')->insert(['user_id' => $padreId, 'plataforma' => 'android', 'token_push' => 'ExponentPushToken['.bin2hex(random_bytes(6)).']']);

    return $s = ['anio' => $anio, 'seccion' => $seccion, 'tutor' => $tutor, 'alumnos' => $alumnos, 'padre' => User::query()->findOrFail($padreId),
        'competencias' => $db->table('competencias')->where('area_curricular_id', $area)->where('vigente', true)->orderBy('orden')->orderBy('numero')->pluck('id')->all(),
        'periodo' => (int) $db->table('periodos')->where('anio_escolar_id', $anio)->where('numero', 4)->value('id')];
}

it('toma asistencia desde el celular con todos presentes por defecto y sincroniza la cola sin conexión (gana el más reciente)', function () {
    $s = seccionS19();
    $hoy = now()->toDateString();
    $secciones = enDemo($s['tutor'], 'GET', '/asistencia/secciones?anio_escolar_id='.$s['anio'])->assertOk();
    expect(collect($secciones->json('data'))->pluck('id')->all())->toContain($s['seccion']);
    $lista = enDemo($s['tutor'], 'GET', "/asistencia/secciones/{$s['seccion']}?fecha=$hoy")->assertOk();
    expect($lista->json('tomada'))->toBeFalse()->and(collect($lista->json('alumnos'))->pluck('estado')->unique()->all())->toBe(['P']);

    $r = enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $hoy, 'origen' => 'movil', 'registros' => [
        ['alumno_id' => $s['alumnos'][0], 'estado' => 'F', 'registrado_at' => now()->subMinutes(5)->toIso8601String()],
        ['alumno_id' => $s['alumnos'][1], 'estado' => 'P'],
    ]])->assertOk();
    expect($r->json('guardados'))->toBe(2);
    // Avisó al padre de la falta, una sola vez.
    Http::assertSentCount(1);
    expect(dueno()->table('asistencias')->where('alumno_id', $s['alumnos'][0])->where('fecha', $hoy)->value('notificado_at'))->not->toBeNull();

    // Llega tarde la cola sin conexión con un registro más antiguo: no pisa el nuevo.
    $viejo = enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $hoy, 'origen' => 'offline', 'registros' => [
        ['alumno_id' => $s['alumnos'][0], 'estado' => 'P', 'registrado_at' => now()->subHour()->toIso8601String()],
    ]])->assertOk();
    expect($viejo->json('ignorados'))->toBe(1)->and(dueno()->table('asistencias')->where('alumno_id', $s['alumnos'][0])->where('fecha', $hoy)->value('estado'))->toBe('F');
    enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => now()->addDay()->toDateString(), 'registros' => [['alumno_id' => $s['alumnos'][0], 'estado' => 'P']]])
        ->assertUnprocessable();
    // Otro docente sin relación con la sección no la ve.
    expect(collect(enDemo(personaDemo('docente'), 'GET', '/asistencia/secciones?anio_escolar_id='.$s['anio'])->json('data'))->pluck('id')->all())->not->toContain($s['seccion']);
});

it('registra la llegada por QR del carné con tardanza automática según la hora de entrada', function () {
    $s = seccionS19();
    $db = dueno();
    $config = json_decode($db->table('instituciones')->where('id', demoId())->value('configuracion') ?? '{}', true) ?: [];
    $db->table('instituciones')->where('id', demoId())->update(['configuracion' => json_encode(array_merge($config, ['asistencia' => ['hora_entrada' => '07:45', 'tolerancia_min' => 10]]))]);
    $auxiliar = personaDemo('auxiliar');
    $codigo = $db->table('alumnos')->where('id', $s['alumnos'][1])->value('codigo_carne');
    $db->table('asistencias')->where('alumno_id', $s['alumnos'][1])->delete();

    Carbon::setTestNow(Carbon::parse(now()->toDateString().' 08:02', 'America/Lima'));
    $r = enDemo($auxiliar, 'POST', '/asistencia/qr', ['codigo' => strtolower($codigo)])->assertOk();
    expect($r->json())->toMatchArray(['estado' => 'T', 'hora' => '08:02', 'repetido' => false]);
    Carbon::setTestNow(Carbon::parse(now()->toDateString().' 08:20', 'America/Lima'));
    expect(enDemo($auxiliar, 'POST', '/asistencia/qr', ['codigo' => $codigo])->json())->toMatchArray(['estado' => 'T', 'hora' => '08:02', 'repetido' => true]);
    enDemo($auxiliar, 'POST', '/asistencia/qr', ['codigo' => 'NO-EXISTE'])->assertUnprocessable();
    expect($db->table('asistencias')->where('alumno_id', $s['alumnos'][1])->value('origen'))->toBe('qr');
});

it('registra conducta con aviso opcional, entrevistas confidenciales y alertas de riesgo con seguimiento', function () {
    $s = seccionS19();
    $db = dueno();
    enDemo($s['tutor'], 'POST', "/tutoria/alumnos/{$s['alumnos'][0]}/conducta", ['tipo' => 'incidencia', 'detalle' => 'Uso del celular en clase'])->assertUnprocessable()->assertJsonValidationErrors('gravedad');
    $c = enDemo($s['tutor'], 'POST', "/tutoria/alumnos/{$s['alumnos'][0]}/conducta", ['tipo' => 'merito', 'detalle' => 'Primer puesto en la olimpiada de matemática', 'avisar_padre' => true])->assertCreated();
    expect($c->json('conducta.0.notificado_padre_at'))->not->toBeNull();

    enDemo($s['tutor'], 'POST', "/tutoria/alumnos/{$s['alumnos'][0]}/entrevistas", ['participantes' => 'Tutora y madre', 'acuerdos' => 'Revisar tareas cada noche.'])->assertCreated();
    enDemo($s['tutor'], 'POST', "/tutoria/alumnos/{$s['alumnos'][0]}/entrevistas", ['participantes' => 'Tutora y alumno', 'acuerdos' => 'Tema familiar delicado.', 'confidencial' => true])->assertCreated();
    expect(enDemo($s['tutor'], 'GET', "/tutoria/alumnos/{$s['alumnos'][0]}")->json('entrevistas'))->toHaveCount(2);
    expect(enDemo(personaDemo('auxiliar'), 'GET', "/tutoria/alumnos/{$s['alumnos'][0]}")->json('entrevistas'))->toHaveCount(1);
    expect(enDemo(personaDemo('psicologia'), 'GET', "/tutoria/alumnos/{$s['alumnos'][0]}")->json('entrevistas'))->toHaveCount(2);

    // Reglas: C en 2 competencias y más de 3 faltas en el mes.
    $inst = demoId();
    foreach (array_slice($s['competencias'], 0, 2) as $k) {
        $db->table('calificaciones')->updateOrInsert(['alumno_id' => $s['alumnos'][1], 'competencia_id' => $k, 'periodo_id' => $s['periodo']],
            ['institucion_id' => $inst, 'seccion_id' => $s['seccion'], 'valor' => 'C', 'conclusion_descriptiva' => 'Reforzar.']);
    }
    $db->table('periodos')->where('id', $s['periodo'])->update(['estado' => 'abierto']);
    foreach (range(1, 4) as $d) {
        $fecha = now()->startOfMonth()->addDays($d - 1)->toDateString();
        if ($fecha <= now()->toDateString()) {
            $db->table('asistencias')->updateOrInsert(['alumno_id' => $s['alumnos'][1], 'fecha' => $fecha, 'horario_sesion_id' => null], ['institucion_id' => $inst, 'seccion_id' => $s['seccion'], 'estado' => 'F']);
        }
    }
    $coordinador = personaDemo('coordinador');
    expect(enDemo($coordinador, 'POST', '/alertas/evaluacion')->assertOk()->json('creadas'))->toBeGreaterThanOrEqual(1);
    expect(enDemo($coordinador, 'POST', '/alertas/evaluacion')->json('creadas'))->toBe(0); // No duplica las abiertas.
    $alertas = collect(enDemo($s['tutor'], 'GET', '/alertas')->assertOk()->json('data'))->where('alumno_id', $s['alumnos'][1]);
    expect($alertas->pluck('motivo')->all())->toContain('notas_c');
    $id = $alertas->firstWhere('motivo', 'notas_c')['id'];
    enDemo($s['tutor'], 'PATCH', "/alertas/$id", ['estado' => 'en_seguimiento', 'accion' => 'Citación a la familia el lunes.'])->assertOk();
    enDemo($s['tutor'], 'PATCH', "/alertas/$id", ['estado' => 'cerrada', 'accion' => 'Plan de refuerzo acordado.'])->assertOk();
    $a = $db->table('alertas_riesgo')->find($id);
    expect($a->estado)->toBe('cerrada')->and($a->accion)->toContain('Citación')->toContain('Plan de refuerzo')->and($a->cerrada_at)->not->toBeNull();
});

it('genera y publica libretas aunque haya deuda; la familia ve notas y descarga la libreta registrando la vista', function () {
    $s = seccionS19();
    $db = dueno();
    $coordinador = personaDemo('coordinador');
    foreach ($s['competencias'] as $k) {
        $db->table('calificaciones')->updateOrInsert(['alumno_id' => $s['alumnos'][0], 'competencia_id' => $k, 'periodo_id' => $s['periodo']],
            ['institucion_id' => demoId(), 'seccion_id' => $s['seccion'], 'valor' => 'A']);
    }
    // Deuda vencida del alumno: no bloquea la libreta.
    $db->table('cargos')->insert(['institucion_id' => demoId(), 'alumno_id' => $s['alumnos'][0], 'concepto_cobro_id' => $db->table('conceptos_cobro')->where('institucion_id', demoId())->where('codigo', 'PEN')->value('id'),
        'descripcion' => 'Pensión vencida', 'emision' => '2026-01-01', 'vencimiento' => '2026-01-31', 'monto' => 350]);

    enDemo($coordinador, 'POST', '/libretas/publicacion', ['seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertUnprocessable();
    $g = enDemo($coordinador, 'POST', '/libretas', ['seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertOk();
    expect($g->json('generadas'))->toBe(2);
    expect(enDemo($s['padre'], 'GET', '/familia/notas')->json('hijos'))->toBe([]); // Aún no publicada.
    expect(enDemo($coordinador, 'POST', '/libretas/publicacion', ['seccion_id' => $s['seccion'], 'periodo_id' => $s['periodo']])->assertOk()->json('publicadas'))->toBe(2);

    $notas = enDemo($s['padre'], 'GET', '/familia/notas')->assertOk();
    $periodo = $notas->json('hijos.0.periodos.0');
    expect($notas->json('hijos'))->toHaveCount(1)->and($periodo['areas'][0]['competencias'][0]['valor'])->toBe('A')->and($periodo['vista_por_padre_at'])->toBeNull();
    enDemo($s['padre'], 'GET', '/familia/libretas/'.$periodo['id'])->assertOk()->assertJsonStructure(['url']);
    expect($db->table('libretas')->find($periodo['id'])->vista_por_padre_at)->not->toBeNull();
    $otraLibreta = $db->table('libretas as l')->join('matriculas as m', 'm.id', '=', 'l.matricula_id')->where('m.alumno_id', $s['alumnos'][1])->value('l.id');
    enDemo($s['padre'], 'GET', "/familia/libretas/$otraLibreta")->assertNotFound();
});

it('arma el acta y el cuadro de méritos en PDF y Excel, y la recuperación actualiza la situación final', function () {
    $s = seccionS19();
    $db = dueno();
    $coordinador = personaDemo('coordinador');
    $db->table('calificaciones')->where('alumno_id', $s['alumnos'][1])->delete();
    foreach ($s['competencias'] as $i => $k) {
        $db->table('calificaciones')->updateOrInsert(['alumno_id' => $s['alumnos'][1], 'competencia_id' => $k, 'periodo_id' => $s['periodo']],
            ['institucion_id' => demoId(), 'seccion_id' => $s['seccion'], 'valor' => $i === 0 ? 'C' : 'B', 'conclusion_descriptiva' => $i === 0 ? 'Reforzar' : null]);
    }
    $acta = enDemo($coordinador, 'GET', "/actas/{$s['seccion']}")->assertOk();
    $diego = collect($acta->json('filas'))->firstWhere('alumno_id', $s['alumnos'][1]);
    expect($diego['situacion'])->toBe('Requiere recuperación')->and($acta->json('merito.0.puesto'))->toBe(1);

    enDemo($coordinador, 'PUT', '/recuperacion', ['alumno_id' => $s['alumnos'][1], 'anio_escolar_id' => $s['anio'], 'competencia_id' => $s['competencias'][0], 'valor' => 'X', 'seccion_id' => $s['seccion']])->assertUnprocessable();
    $r = enDemo($coordinador, 'PUT', '/recuperacion', ['alumno_id' => $s['alumnos'][1], 'anio_escolar_id' => $s['anio'], 'competencia_id' => $s['competencias'][0], 'valor' => 'B', 'seccion_id' => $s['seccion']])->assertOk();
    $diego = collect($r->json('filas'))->firstWhere('alumno_id', $s['alumnos'][1]);
    expect($diego['situacion'])->toBe('Promovido')->and($diego['notas'][$s['competencias'][0]])->toMatchArray(['final' => 'B', 'anual' => 'C', 'recuperacion' => 'B']);
    enDemo($s['tutor'], 'PUT', '/recuperacion', ['alumno_id' => $s['alumnos'][1], 'anio_escolar_id' => $s['anio'], 'competencia_id' => $s['competencias'][0], 'valor' => 'A', 'seccion_id' => $s['seccion']])->assertForbidden();

    actuarComo($coordinador, true, demoId());
    $this->get("/api/v1/actas/{$s['seccion']}/pdf", ['X-Institucion' => (string) demoId()])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/api/v1/actas/{$s['seccion']}/xlsx", ['X-Institucion' => (string) demoId()])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('recibe en bloque las lecturas de QR hechas sin conexión: hora real, idempotente y gana el registro más reciente (EDUN-215)', function () {
    $s = seccionS19();
    $db = dueno();
    $config = json_decode($db->table('instituciones')->where('id', demoId())->value('configuracion') ?? '{}', true) ?: [];
    $db->table('instituciones')->where('id', demoId())->update(['configuracion' => json_encode(array_merge($config, ['asistencia' => ['hora_entrada' => '07:45', 'tolerancia_min' => 10]]))]);
    $auxiliar = personaDemo('auxiliar');
    [$carla, $diego] = $s['alumnos'];
    $ayer = now()->subDay()->toDateString();
    $db->table('asistencias')->whereIn('alumno_id', [$carla, $diego])->where('fecha', $ayer)->delete();
    // El tutor marcó a Carla como falta a las 09:00 de ayer; la lectura sin conexión de las 07:50 prueba que llegó.
    enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $ayer, 'registros' => [
        ['alumno_id' => $carla, 'estado' => 'F', 'registrado_at' => Carbon::parse("$ayer 09:00", 'America/Lima')->toIso8601String()],
    ]])->assertOk();
    $codigo = fn ($id) => $db->table('alumnos')->where('id', $id)->value('codigo_carne');
    $lecturas = [
        ['id' => 'l1', 'codigo' => $codigo($diego), 'leido_at' => Carbon::parse("$ayer 07:40", 'America/Lima')->toIso8601String()],
        ['id' => 'l2', 'codigo' => $codigo($carla), 'leido_at' => Carbon::parse("$ayer 07:50", 'America/Lima')->toIso8601String()],
        ['id' => 'l3', 'codigo' => 'NO-EXISTE', 'leido_at' => now()->toIso8601String()],
        ['id' => 'l4', 'codigo' => $codigo($diego), 'leido_at' => now()->addHour()->toIso8601String()],
    ];
    $r = collect(enDemo($auxiliar, 'POST', '/asistencias/lote', ['lecturas' => $lecturas])->assertOk()->json('resultados'))->keyBy('id');
    expect($r['l1'])->toMatchArray(['ok' => true, 'estado' => 'P', 'hora' => '07:40', 'repetido' => false])
        // Revisión: la falta marcada después no borra la llegada comprobada con el carné.
        ->and($r['l2'])->toMatchArray(['ok' => true, 'estado' => 'P', 'corrigio_falta' => true])
        ->and($r['l3']['ok'])->toBeFalse()->and($r['l3']['mensaje'])->toContain('Carné')
        ->and($r['l4']['ok'])->toBeFalse();
    $fila = $db->table('asistencias')->where('alumno_id', $diego)->where('fecha', $ayer)->first();
    expect($fila->estado)->toBe('P')->and($fila->origen)->toBe('offline')->and(substr($fila->hora_registro, 0, 5))->toBe('07:40');

    // Reenviar el mismo bloque (se cortó la respuesta) no duplica ni cambia nada.
    $r2 = collect(enDemo($auxiliar, 'POST', '/asistencias/lote', ['lecturas' => array_slice($lecturas, 0, 2)])->assertOk()->json('resultados'))->keyBy('id');
    expect($r2['l1'])->toMatchArray(['ok' => true, 'repetido' => true]);
    expect($db->table('asistencias')->where('alumno_id', $diego)->where('fecha', $ayer)->count())->toBe(1)
        ->and($db->table('asistencias')->where('alumno_id', $carla)->where('fecha', $ayer)->value('estado'))->toBe('P');
    enDemo($auxiliar, 'POST', '/asistencias/lote', ['lecturas' => [['id' => 'a', 'codigo' => 'X', 'leido_at' => 'x'], ['id' => 'a', 'codigo' => 'Y', 'leido_at' => now()->toIso8601String()]]])->assertUnprocessable();
    enDemo(personaDemo('apoderado'), 'POST', '/asistencias/lote', ['lecturas' => $lecturas])->assertForbidden();
});
