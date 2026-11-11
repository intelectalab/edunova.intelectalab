<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| EDUN-96 · CAL-H06 Justificar inasistencia: desde la app con foto del sustento, el tutor acepta o
| rechaza y la falta pasa de F a J.
*/

beforeEach(function () {
    Http::fake();
    Storage::fake('objetos');
});

/** Sección «J» con tutor, un alumno con apoderado con usuario y otro tutor ajeno. */
function seccionJustificaciones(): array
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
    $personal = $db->table('personal')->insertGetId(['institucion_id' => $inst, 'user_id' => $tutor->id, 'numero_documento' => (string) random_int(10000000, 99999999), 'apellidos' => 'Tutor J', 'nombres' => 'Luis', 'cargo' => 'Docente']);
    $seccion = $db->table('secciones')->insertGetId(['institucion_id' => $inst, 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => 'J'.substr(uniqid(), -3), 'tutor_personal_id' => $personal]);
    $alumno = $db->table('alumnos')->insertGetId(['institucion_id' => $inst, 'apellido_paterno' => 'Justo', 'nombres' => 'Mía', 'numero_documento' => (string) random_int(70000000, 79999999)]);
    $db->table('matriculas')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'anio_escolar_id' => $anio, 'seccion_id' => $seccion]);
    $padreId = $db->table('users')->insertGetId(['nombres' => 'Madre', 'apellidos' => 'J '.uniqid(), 'numero_documento' => (string) random_int(20000000, 29999999), 'email' => 'madre.'.uniqid().'@familia.prueba', 'password' => Hash::make('x')]);
    $db->table('membresias')->insert(['institucion_id' => $inst, 'user_id' => $padreId, 'rol_id' => $db->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $apo = $db->table('apoderados')->insertGetId(['institucion_id' => $inst, 'user_id' => $padreId, 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'J', 'nombres' => 'Madre']);
    $db->table('alumno_apoderado')->insert(['institucion_id' => $inst, 'alumno_id' => $alumno, 'apoderado_id' => $apo, 'parentesco' => 'madre', 'es_responsable_pago' => true]);

    return $s = ['seccion' => $seccion, 'tutor' => $tutor, 'alumno' => $alumno, 'madre' => User::query()->findOrFail($padreId)];
}

it('la familia pide la justificación con foto, el tutor la acepta y las faltas pasan a J', function () {
    $s = seccionJustificaciones();
    $ayer = now()->subDay()->toDateString();
    $anteayer = now()->subDays(2)->toDateString();
    enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $anteayer, 'registros' => [['alumno_id' => $s['alumno'], 'estado' => 'F']]])->assertOk();
    enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $ayer, 'registros' => [['alumno_id' => $s['alumno'], 'estado' => 'F']]])->assertOk();

    $foto = UploadedFile::fake()->create('receta.jpg', 300, 'image/jpeg');
    $j = enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => $anteayer, 'fecha_hasta' => $ayer, 'motivo' => 'Fiebre, con receta médica.', 'sustento' => $foto])->assertCreated();
    expect($j->json('estado'))->toBe('pendiente')->and($j->json('faltas'))->toBe(2)->and($j->json('tiene_sustento'))->toBeTrue();
    $id = $j->json('id');
    // No se duplica para los mismos días ni se justifica a un alumno ajeno.
    enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => $ayer, 'fecha_hasta' => $ayer, 'motivo' => 'Otra vez el mismo día'])->assertUnprocessable();
    enDemo(personaDemo('apoderado'), 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => $ayer, 'fecha_hasta' => $ayer, 'motivo' => 'No es mi hijo'])->assertForbidden();
    enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => now()->subDays(40)->toDateString(), 'fecha_hasta' => now()->subDays(39)->toDateString(), 'motivo' => 'Muy antigua'])->assertUnprocessable();
    enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => $ayer, 'fecha_hasta' => $ayer, 'motivo' => 'Con archivo raro', 'sustento' => UploadedFile::fake()->create('x.exe', 10)])->assertUnprocessable();

    // La bandeja: el tutor ve la de su sección; otro tutor no la ve ni la resuelve.
    expect(collect(enDemo($s['tutor'], 'GET', '/asistencia/justificaciones?estado=pendiente')->assertOk()->json('data'))->pluck('id')->all())->toContain($id);
    $otro = personaDemo('tutor');
    expect(collect(enDemo($otro, 'GET', '/asistencia/justificaciones')->json('data'))->pluck('id')->all())->not->toContain($id);
    enDemo($otro, 'POST', "/asistencia/justificaciones/$id", ['decision' => 'aceptar'])->assertForbidden();
    enDemo($s['madre'], 'GET', '/asistencia/justificaciones')->assertForbidden();

    enDemo($s['tutor'], 'GET', "/justificaciones/$id/sustento")->assertOk()->assertJsonStructure(['url']);
    enDemo($s['madre'], 'GET', "/justificaciones/$id/sustento")->assertOk();
    enDemo(personaDemo('apoderado'), 'GET', "/justificaciones/$id/sustento")->assertNotFound();

    $r = enDemo($s['tutor'], 'POST', "/asistencia/justificaciones/$id", ['decision' => 'aceptar'])->assertOk();
    expect($r->json('estado'))->toBe('aceptada')->and($r->json('dias_justificados'))->toBe(2)
        ->and(dueno()->table('asistencias')->where('alumno_id', $s['alumno'])->whereIn('fecha', [$anteayer, $ayer])->pluck('estado')->unique()->all())->toBe(['J']);
    enDemo($s['tutor'], 'POST', "/asistencia/justificaciones/$id", ['decision' => 'rechazar', 'respuesta' => 'tarde'])->assertUnprocessable();
    expect(collect(enDemo($s['madre'], 'GET', '/familia/justificaciones')->json('data'))->firstWhere('id', $id)['estado'])->toBe('aceptada');
});

it('el rechazo exige explicación; una justificación aceptada por adelantado convierte la falta en J', function () {
    $s = seccionJustificaciones();
    $manana = now()->addDay()->toDateString();
    $hoy = now()->toDateString();
    $j = enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => $hoy, 'fecha_hasta' => $manana, 'motivo' => 'Viaje familiar por salud'])->assertCreated();
    enDemo($s['tutor'], 'POST', '/asistencia/justificaciones/'.$j->json('id'), ['decision' => 'rechazar'])->assertUnprocessable()->assertJsonValidationErrors('respuesta');
    enDemo($s['tutor'], 'POST', '/asistencia/justificaciones/'.$j->json('id'), ['decision' => 'aceptar'])->assertOk();

    // Hoy el tutor toma asistencia y la marca F: entra como J por la justificación aceptada.
    enDemo($s['tutor'], 'PUT', "/asistencia/secciones/{$s['seccion']}", ['fecha' => $hoy, 'registros' => [['alumno_id' => $s['alumno'], 'estado' => 'F']]])->assertOk();
    expect(dueno()->table('asistencias')->where('alumno_id', $s['alumno'])->where('fecha', $hoy)->value('estado'))->toBe('J');

    $k = enDemo($s['madre'], 'POST', '/familia/justificaciones', ['alumno_id' => $s['alumno'], 'fecha_desde' => now()->subDays(5)->toDateString(), 'fecha_hasta' => now()->subDays(5)->toDateString(), 'motivo' => 'Cita médica'])->assertCreated();
    $r = enDemo($s['tutor'], 'POST', '/asistencia/justificaciones/'.$k->json('id'), ['decision' => 'rechazar', 'respuesta' => 'Ese día asistió.'])->assertOk();
    expect($r->json('estado'))->toBe('rechazada')->and($r->json('respuesta'))->toBe('Ese día asistió.');
});
