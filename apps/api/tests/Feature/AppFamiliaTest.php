<?php

use App\Models\User;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/*
| S36 · App de la familia: inicio con resumen, asistencia del mes, horario y foto del hijo.
*/

beforeEach(function () {
    Storage::fake(AlmacenArchivos::DISCO);
    $db = dueno();
    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    foreach (['ADM', 'CAL', 'PAG', 'COM', 'AUL'] as $codigo) {
        $m = $db->table('modulos_catalogo')->where('codigo', $codigo)->value('id');
        if (! $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->exists()) {
            $db->table('suscripcion_modulos')->insert(['institucion_id' => demoId(), 'suscripcion_id' => $sus, 'modulo_id' => $m]);
        }
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $m)->update(['activo' => true]);
    }
});

/** Sección con horario, un hijo con datos del día y otro alumno ajeno a la familia. */
function familiaApp(): array
{
    $db = dueno();
    $inst = demoId();
    $hoy = now();
    $anio = (int) $db->table('anios_escolares')->where('institucion_id', $inst)->where('anio', 2027)->value('id');
    $grado = $db->table('grados')->where('institucion_id', $inst)->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'numero_documento' => (string) random_int(10000000, 99999999), 'apellidos' => 'Torres', 'nombres' => 'Ana', 'cargo' => 'Docente']);
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'X'.strtoupper(substr(uniqid(), -3))]);
    $area = $db->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
    $carga = $db->table('cargas_docentes')->insertGetId(['institucion_id' => $inst, 'seccion_id' => $seccion, 'area_curricular_id' => $area, 'personal_id' => $personal]);
    $turno = $db->table('secciones')->where('id', $seccion)->value('turno');
    $bloque = $db->table('bloques_horarios')->where('institucion_id', $inst)->where('turno', $turno)->where('es_recreo', false)->orderBy('orden')->value('id')
        ?? $db->table('bloques_horarios')->insertGetId(['institucion_id' => $inst, 'turno' => $turno, 'orden' => 1, 'hora_inicio' => '07:30', 'hora_fin' => '08:15']);
    $db->table('horario_sesiones')->insert(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion, 'dia_semana' => 1, 'bloque_horario_id' => $bloque, 'carga_docente_id' => $carga, 'personal_id' => $personal]);

    $alumnos = [];
    foreach (['Valeria', 'Otro'] as $n) {
        $a = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Huamán', 'nombres' => $n, 'numero_documento' => (string) random_int(70000000, 79999999)]);
        $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $a, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion]);
        $alumnos[] = $a;
    }
    [$hijo, $otro] = $alumnos;

    $padreId = $db->table('users')->insertGetId(['nombres' => 'Padre', 'apellidos' => 'App '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'padre.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $padreId, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $padreId, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'App', 'nombres' => 'Padre']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $hijo, 'apoderado_id' => $apo, 'parentesco' => 'padre', 'es_responsable_pago' => true]);

    // Asistencia: hoy puntual a las 07:22 y una falta el primer día del mes (si no es hoy).
    $db->table('asistencias')->insert(['institucion_id' => $inst, 'alumno_id' => $hijo, 'seccion_id' => $seccion, 'fecha' => $hoy->toDateString(), 'estado' => 'P', 'hora_registro' => '07:22:10', 'origen' => 'qr']);
    if ($hoy->day > 1) {
        $db->table('asistencias')->insert(['institucion_id' => $inst, 'alumno_id' => $hijo, 'seccion_id' => $seccion, 'fecha' => $hoy->copy()->startOfMonth()->toDateString(), 'estado' => 'F', 'origen' => 'web']);
    }
    // Pensión emitida que vence en 10 días.
    $db->table('cargos')->insert(['institucion_id' => $inst, 'alumno_id' => $hijo, 'concepto_cobro_id' => $db->table('conceptos_cobro')->where('institucion_id', $inst)->where('codigo', 'PEN')->value('id'),
        'descripcion' => 'Pensión de prueba', 'emision' => $hoy->copy()->subDays(5)->toDateString(), 'vencimiento' => $hoy->copy()->addDays(10)->toDateString(), 'monto' => 350]);
    // Nota de una actividad.
    $periodo = $db->table('periodos')->where('anio_escolar_id', $anio)->orderBy('numero')->value('id');
    $competencia = $db->table('competencias')->where('area_curricular_id', $area)->orderBy('numero')->value('id');
    $actividad = $db->table('actividades_evaluacion')->insertGetId(['institucion_id' => $inst, 'carga_docente_id' => $carga, 'periodo_id' => $periodo, 'competencia_id' => $competencia, 'nombre' => 'Evaluación Álgebra II', 'fecha' => $hoy->toDateString()]);
    $db->table('notas_actividad')->insert(['institucion_id' => $inst, 'actividad_evaluacion_id' => $actividad, 'alumno_id' => $hijo, 'valor' => 'AD']);
    // Comunicado que pide confirmación.
    $comunicado = $db->table('comunicados')->insertGetId(['institucion_id' => $inst, 'titulo' => 'Entrega de libretas', 'contenido' => 'Los esperamos el viernes.', 'alcance' => json_encode(['tipo' => 'colegio']),
        'requiere_confirmacion' => true, 'enviado_at' => now(), 'autor_id' => $padreId, 'estado' => 'enviado']);
    $db->table('comunicado_destinatarios')->insert(['institucion_id' => $inst, 'comunicado_id' => $comunicado, 'user_id' => $padreId, 'alumno_id' => $hijo]);
    // Foto del hijo.
    $ruta = "$inst/pruebas/".uniqid().'.png';
    Storage::disk(AlmacenArchivos::DISCO)->put($ruta, base64_decode(LOGO_PNG));
    $archivo = $db->table('archivos')->insertGetId(['institucion_id' => $inst, 'disco' => AlmacenArchivos::DISCO, 'ruta' => $ruta, 'nombre_original' => 'foto.png', 'mime' => 'image/png', 'bytes' => 100, 'sha256' => str_repeat('a', 64), 'confidencial' => true]);
    $db->table('alumnos')->where('id', $hijo)->update(['foto_archivo_id' => $archivo]);

    return ['hijo' => $hijo, 'otro' => $otro, 'padre' => User::query()->findOrFail($padreId)];
}

it('arma el inicio de la familia con pensión, asistencia de hoy, notas recientes, foto y el comunicado por confirmar', function () {
    $f = familiaApp();
    $r = enDemo($f['padre'], 'GET', '/app/familia')->assertOk();

    expect(collect($r->json('hijos'))->pluck('id')->all())->toBe([$f['hijo']]);
    $h = $r->json('hijos.0');
    expect($h['nombres'])->toBe('Valeria')
        ->and($h['apellidos'])->toBe('Quispe Huamán')
        ->and($h['tiene_foto'])->toBeTrue()
        ->and($h['pension']['proximo']['saldo'])->toEqual(350)
        ->and($h['pension']['proximo']['vencido'])->toBeFalse()
        ->and($h['pension']['deuda_vencida'])->toEqual(0)
        ->and($h['asistencia']['hoy'])->toBe(['estado' => 'P', 'hora' => '07:22', 'origen' => 'qr'])
        ->and($h['notas_recientes'][0])->toMatchArray(['area' => $h['notas_recientes'][0]['area'], 'actividad' => 'Evaluación Álgebra II', 'valor' => 'AD', 'docente' => 'Ana Torres']);
    expect($h['comunicado']['titulo'])->toBe('Entrega de libretas')
        ->and($h['comunicado']['requiere_confirmacion'])->toBeTrue()
        ->and($h['comunicados_sin_leer'])->toBe(1)
        ->and($r->json('comunicados_sin_leer'))->toBe(1);

    // Confirmado, ya no aparece en el inicio de ese hijo.
    enDemo($f['padre'], 'POST', "/familia/comunicados/{$h['comunicado']['id']}", ['confirmar' => true])->assertOk();
    $r = enDemo($f['padre'], 'GET', '/app/familia')->assertOk();
    expect($r->json('hijos.0.comunicado'))->toBeNull()->and($r->json('comunicados_sin_leer'))->toBe(0);
});

it('da la asistencia del mes, el horario y la foto solo de sus hijos', function () {
    $f = familiaApp();
    $asis = enDemo($f['padre'], 'GET', "/familia/asistencia?alumno_id={$f['hijo']}")->assertOk();
    expect(collect($asis->json('dias'))->firstWhere('fecha', now()->toDateString()))->toBe(['fecha' => now()->toDateString(), 'estado' => 'P', 'hora' => '07:22'])
        ->and($asis->json('resumen.P'))->toBe(1)
        ->and($asis->json('porcentaje'))->toBe(now()->day > 1 ? 50 : 100);

    $horario = enDemo($f['padre'], 'GET', "/familia/horario?alumno_id={$f['hijo']}")->assertOk()->json('horario');
    expect($horario['celdas'][0]['docente'])->toBe('Ana Torres')
        ->and($horario['celdas'][0]['dia_semana'])->toBe(1)
        ->and($horario)->not->toHaveKey('cargas')
        ->and($horario)->not->toHaveKey('ambientes');

    $foto = enDemo($f['padre'], 'GET', "/familia/alumnos/{$f['hijo']}/foto")->assertOk();
    expect($foto->headers->get('Content-Type'))->toBe('image/png');

    $notas = enDemo($f['padre'], 'GET', "/familia/notas-periodo?alumno_id={$f['hijo']}")->assertOk();
    expect($notas->json('periodos'))->not->toBeEmpty()
        ->and($notas->json('cursos.0.notas.0'))->toMatchArray(['actividad' => 'Evaluación Álgebra II', 'valor' => 'AD'])
        ->and($notas->json('cursos.0.docente'))->toBe('Ana Torres');
    enDemo($f['padre'], 'GET', "/familia/notas-periodo?alumno_id={$f['otro']}")->assertNotFound();
    enDemo($f['padre'], 'GET', "/familia/notas-periodo?alumno_id={$f['hijo']}&periodo_id=999999999")->assertNotFound();
    enDemo($f['padre'], 'GET', "/familia/asistencia?alumno_id={$f['otro']}")->assertNotFound();
    enDemo($f['padre'], 'GET', "/familia/horario?alumno_id={$f['otro']}")->assertNotFound();
    enDemo($f['padre'], 'GET', "/familia/alumnos/{$f['otro']}/foto")->assertNotFound();
    enDemo(personaDemo('docente'), 'GET', '/app/familia')->assertOk()->assertJsonPath('hijos', []);
});

it('el alumno con cuenta propia se ve a sí mismo y sin módulo de notas no hay asistencia', function () {
    $f = familiaApp();
    $db = dueno();
    $u = $db->table('users')->insertGetId(['nombres' => 'Valeria', 'apellidos' => 'Quispe', 'numero_documento' => (string) random_int(60000000, 69999999), 'email' => 'v.'.uniqid().'@alumno.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => demoId(), 'user_id' => $u, 'rol_id' => $db->table('roles')->where('codigo', 'alumno')->value('id')]);
    $db->table('alumnos')->where('id', $f['hijo'])->update(['user_id' => $u]);
    $alumno = User::query()->findOrFail($u);
    expect(enDemo($alumno, 'GET', '/app/familia')->assertOk()->json('hijos.0.id'))->toBe($f['hijo']);

    $sus = $db->table('suscripciones')->where('institucion_id', demoId())->value('id');
    $cal = $db->table('modulos_catalogo')->where('codigo', 'CAL')->value('id');
    $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $cal)->update(['activo' => false]);
    try {
        $r = enDemo($f['padre'], 'GET', '/app/familia')->assertOk();
        expect($r->json('hijos.0.asistencia'))->toBeNull()->and($r->json('modulos'))->not->toContain('CAL');
        enDemo($f['padre'], 'GET', "/familia/asistencia?alumno_id={$f['hijo']}")->assertForbidden();
    } finally {
        $db->table('suscripcion_modulos')->where('suscripcion_id', $sus)->where('modulo_id', $cal)->update(['activo' => true]);
    }
});
