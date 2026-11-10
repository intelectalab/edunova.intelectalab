<?php

use App\Models\User;
use App\Modulos\ADM\Services\ImportacionAlumnos;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/*
| Sprint 13 · Estructura académica y alumnos:
| EDUN-45 plan de estudios · EDUN-46 secciones · EDUN-47 personal · EDUN-48 carga docente
| EDUN-49 horario sin cruces · EDUN-50 alumno y apoderados · EDUN-51 importar desde Excel
*/

function anio2027(): int
{
    return (int) dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
}

/** Año escolar nuevo del demo para probar copias (se crea una vez). */
function anioNuevo(): int
{
    static $id = null;

    return $id ??= enDemo(directorDemo(), 'POST', '/anios-escolares', ['anio' => 2032, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2032-03-01', 'fecha_fin' => '2032-12-17'])->assertCreated()->json('id');
}

function grado3Sec(): int
{
    return (int) dueno()->table('grados')->where('institucion_id', demoId())->where('nivel', 'secundaria')->where('numero', 3)->value('id');
}

function seccion3A(): int
{
    return (int) dueno()->table('secciones')->where('institucion_id', demoId())->where('anio_escolar_id', anio2027())->where('grado_id', grado3Sec())->where('nombre', 'A')->value('id');
}

/** Docente nuevo del personal del demo. */
function docenteNuevo(string $nombre = 'Docente'): int
{
    return (int) dueno()->table('personal')->insertGetId([
        'institucion_id' => demoId(), 'numero_documento' => (string) random_int(40000000, 49999999), 'apellidos' => 'Prueba '.uniqid(),
        'nombres' => $nombre, 'cargo' => 'Docente',
    ]);
}

function coordinador(): User
{
    static $u = null;

    return $u ??= personaDemo('coordinador');
}

// ─────────────────────────────── EDUN-45 · Plan de estudios

it('genera los grados de cada nivel y arma el plan con áreas del CNEB, horas y escala', function () {
    $r = enDemo(coordinador(), 'POST', '/plan-estudios/grados')->assertOk();
    expect($r->json('creados'))->toBeGreaterThan(0);
    expect(enDemo(coordinador(), 'POST', '/plan-estudios/grados')->json('creados'))->toBe(0);

    $plan = enDemo(coordinador(), 'GET', '/plan-estudios?anio_escolar_id='.anio2027())->assertOk();
    $nombres = collect($plan->json('grados'))->pluck('nombre')->all();
    expect($nombres)->toContain('3 años', '1.° de Primaria', '6.° de Primaria', '3.° de Secundaria')
        ->and(collect($plan->json('grados'))->firstWhere('nombre', '3.° de Secundaria')['areas'])->not->toBeEmpty()
        ->and($plan->json('catalogo.primaria'))->not->toBeEmpty();

    $primero = collect($plan->json('grados'))->firstWhere('nombre', '1.° de Primaria')['id'];
    $areasPri = collect($plan->json('catalogo.primaria'))->take(3)->values();
    $guardado = enDemo(coordinador(), 'PUT', "/plan-estudios/grados/{$primero}", [
        'anio_escolar_id' => anio2027(), 'escala' => 'vigesimal',
        'areas' => $areasPri->map(fn ($a, $i) => ['area_curricular_id' => $a['id'], 'horas_semanales' => 4 + $i])->all(),
    ])->assertOk();
    $g = collect($guardado->json('grados'))->firstWhere('id', $primero);
    expect($g['escala'])->toBe('vigesimal')->and(collect($g['areas'])->pluck('horas_semanales')->all())->toBe([4, 5, 6]);

    // Un área de otro nivel no entra.
    $areaSec = $plan->json('catalogo.secundaria.0.id');
    enDemo(coordinador(), 'PUT', "/plan-estudios/grados/{$primero}", ['anio_escolar_id' => anio2027(), 'escala' => 'literal', 'areas' => [['area_curricular_id' => $areaSec]]])
        ->assertUnprocessable();

    // Un área con docente asignado no se quita del plan.
    enDemo(coordinador(), 'PUT', '/plan-estudios/grados/'.grado3Sec(), ['anio_escolar_id' => anio2027(), 'escala' => 'literal', 'areas' => []])
        ->assertUnprocessable()->assertJsonPath('errors.areas.0', fn ($m) => str_contains($m, 'ya tiene docente asignado'));

    expect(dueno()->table('auditoria')->where('accion', 'plan_estudios_editado')->where('registro_id', $primero)->count())->toBe(1);
    enDemo(personaDemo('secretaria'), 'PUT', "/plan-estudios/grados/{$primero}", ['anio_escolar_id' => anio2027(), 'escala' => 'literal', 'areas' => []])->assertForbidden();
});

it('copia el plan del año anterior solo a los grados que aún no tienen plan', function () {
    $nuevo = anioNuevo();
    $r = enDemo(coordinador(), 'POST', '/plan-estudios/copiar', ['desde_anio_id' => anio2027(), 'hacia_anio_id' => $nuevo])->assertOk();
    expect($r->json('grados'))->toBeGreaterThan(0);
    $plan = enDemo(coordinador(), 'GET', "/plan-estudios?anio_escolar_id={$nuevo}")->json('grados');
    expect(collect($plan)->firstWhere('id', grado3Sec())['areas'])->not->toBeEmpty();

    $otra = enDemo(coordinador(), 'POST', '/plan-estudios/copiar', ['desde_anio_id' => anio2027(), 'hacia_anio_id' => $nuevo])->assertOk();
    expect($otra->json('grados'))->toBe(0)->and($otra->json('omitidos'))->toBeGreaterThan(0);
});

// ─────────────────────────────── EDUN-46 · Secciones

it('crea secciones con turno, aula, tutor y vacantes, y las copia al año siguiente', function () {
    $tutor = docenteNuevo('Tutora');
    $aula = enDemo(coordinador(), 'POST', '/ambientes', ['nombre' => 'Aula 305', 'capacidad' => 30])->assertCreated()->json('id');
    enDemo(coordinador(), 'POST', '/ambientes', ['nombre' => 'aula 305'])->assertUnprocessable();

    $b = enDemo(coordinador(), 'POST', '/secciones', [
        'anio_escolar_id' => anio2027(), 'grado_id' => grado3Sec(), 'nombre' => 'e', 'turno' => 'tarde', 'ambiente_id' => $aula, 'tutor_personal_id' => $tutor, 'vacantes' => 30,
    ])->assertCreated()->json('id');
    enDemo(coordinador(), 'POST', '/secciones', ['anio_escolar_id' => anio2027(), 'grado_id' => grado3Sec(), 'nombre' => 'E', 'turno' => 'manana'])
        ->assertUnprocessable()->assertJsonPath('errors.nombre.0', 'Ya existe la sección E en ese grado y año.');

    $lista = enDemo(coordinador(), 'GET', '/secciones?anio_escolar_id='.anio2027())->assertOk();
    $fila = collect($lista->json('data'))->firstWhere('id', $b);
    expect($fila)->toMatchArray(['nombre' => 'E', 'turno' => 'tarde', 'ambiente' => 'Aula 305', 'vacantes' => 30])
        ->and($fila['tutor'])->toStartWith('Tutora')
        ->and(collect($lista->json('data'))->firstWhere('id', seccion3A())['matriculados'])->toBeGreaterThanOrEqual(8);

    // La 3.° A tiene alumnos: no se borra ni baja sus vacantes por debajo de los matriculados.
    enDemo(coordinador(), 'DELETE', '/secciones/'.seccion3A())->assertUnprocessable();
    enDemo(coordinador(), 'PUT', '/secciones/'.seccion3A(), ['anio_escolar_id' => anio2027(), 'grado_id' => grado3Sec(), 'nombre' => 'A', 'turno' => 'manana', 'vacantes' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('vacantes');

    $copia = enDemo(coordinador(), 'POST', '/secciones/copiar', ['desde_anio_id' => anio2027(), 'hacia_anio_id' => anioNuevo()])->assertOk();
    expect($copia->json('creadas'))->toBeGreaterThanOrEqual(2);
    expect(collect(enDemo(coordinador(), 'GET', '/secciones?anio_escolar_id='.anioNuevo())->json('data'))->pluck('nombre'))->toContain('A', 'E');
});

// ─────────────────────────────── EDUN-47 · Personal

it('registra al personal con contrato y le crea el usuario con invitación', function () {
    $director = directorDemo();
    $r = enDemo($director, 'POST', '/personal', [
        'numero_documento' => '47112233', 'apellidos' => 'Flores Díaz', 'nombres' => 'Carmen', 'cargo' => 'Docente', 'especialidad' => 'Ciencia y Tecnología',
        'tipo_contrato' => 'plazo_fijo', 'contrato_fin' => now()->addDays(10)->toDateString(), 'celular' => '987 222 333',
        'invitar' => true, 'roles' => ['docente', 'tutor'], 'canal' => 'whatsapp',
    ])->assertCreated();
    $id = $r->json('id');
    expect($r->json('invitacion.invitacion.whatsapp_url'))->toStartWith('https://wa.me/51987222333');

    $fila = collect(enDemo($director, 'GET', '/personal?buscar=carmen')->json('data'))->firstWhere('id', $id);
    expect($fila)->toMatchArray(['cargo' => 'Docente', 'contrato' => 'por_vencer', 'usuario' => 'invitado'])->and($fila['user_id'])->not->toBeNull();
    $roles = dueno()->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.user_id', $fila['user_id'])->where('m.institucion_id', demoId())->pluck('r.codigo')->sort()->values()->all();
    expect($roles)->toBe(['docente', 'tutor']);

    enDemo($director, 'POST', '/personal', ['numero_documento' => '47112233', 'apellidos' => 'X', 'nombres' => 'Y', 'cargo' => 'Docente'])
        ->assertUnprocessable()->assertJsonPath('errors.numero_documento.0', 'Ya hay una persona del personal con ese documento.');
    enDemo($director, 'POST', '/personal', ['numero_documento' => '123', 'apellidos' => 'X', 'nombres' => 'Y', 'cargo' => 'Docente'])->assertJsonValidationErrors('numero_documento');
    enDemo($director, 'PUT', "/personal/{$id}", ['numero_documento' => '47112233', 'apellidos' => 'Flores Díaz', 'nombres' => 'Carmen', 'cargo' => 'Docente', 'contrato_fin' => now()->subDay()->toDateString()])->assertOk();
    expect(collect(enDemo($director, 'GET', '/personal')->json('data'))->firstWhere('id', $id)['contrato'])->toBe('vencido');

    // El coordinador no administra personal.
    enDemo(coordinador(), 'GET', '/personal')->assertForbidden();
});

// ─────────────────────────────── EDUN-48 · Carga docente

it('asigna docente por área y sección y crea el curso del aula virtual o de Moodle', function () {
    $docente = docenteNuevo('Ciencias');
    $area = dueno()->table('area_curricular_grado as acg')->join('areas_curriculares as a', 'a.id', '=', 'acg.area_curricular_id')
        ->where('acg.grado_id', grado3Sec())->where('acg.anio_escolar_id', anio2027())->whereNotIn('a.codigo', ['SEC-MAT', 'SEC-COM'])->value('a.id');

    $r = enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => seccion3A(), 'area_curricular_id' => $area, 'personal_id' => $docente, 'horas_semanales' => 4])->assertOk();
    $carga = $r->json('carga_id');
    expect(dueno()->table('aula_cursos')->where('carga_docente_id', $carga)->value('titulo'))->toContain('3.° de Secundaria A');

    $lista = enDemo(coordinador(), 'GET', '/cargas?anio_escolar_id='.anio2027())->assertOk();
    $celda = collect(collect($lista->json('secciones'))->firstWhere('seccion_id', seccion3A())['areas'])->firstWhere('area_curricular_id', $area);
    expect($celda)->toMatchArray(['carga_id' => $carga, 'personal_id' => $docente, 'horas_semanales' => 4, 'curso_aula' => true]);

    // Un área fuera del plan del grado no se asigna.
    $ajena = dueno()->table('areas_curriculares')->where('nivel', 'primaria')->value('id');
    enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => seccion3A(), 'area_curricular_id' => $ajena, 'personal_id' => $docente])->assertUnprocessable();

    // Con Moodle: sin conector avisa; con conector queda el curso pendiente de crear.
    actuarComo(superadmin());
    test()->putJson('/api/v1/plataforma/instituciones/'.demoId().'/aula', ['aula_opcion' => 'ambas'])->assertOk();
    $otra = dueno()->table('area_curricular_grado')->where('grado_id', grado3Sec())->where('anio_escolar_id', anio2027())
        ->whereNotIn('area_curricular_id', dueno()->table('cargas_docentes')->where('seccion_id', seccion3A())->pluck('area_curricular_id'))->value('area_curricular_id');
    dueno()->table('moodle_conexiones')->where('institucion_id', demoId())->delete();
    $sinConector = enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => seccion3A(), 'area_curricular_id' => $otra, 'personal_id' => $docente])->assertOk();
    expect($sinConector->json('aviso'))->toContain('conector de Moodle');
    dueno()->table('moodle_conexiones')->insert(['institucion_id' => demoId(), 'url' => 'https://moodle.prueba', 'token_cifrado' => 'x']);
    enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => seccion3A(), 'area_curricular_id' => $otra, 'personal_id' => null])->assertOk();
    $conConector = enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => seccion3A(), 'area_curricular_id' => $otra, 'personal_id' => $docente])->assertOk();
    expect(dueno()->table('moodle_cursos')->where('carga_docente_id', $conConector->json('carga_id'))->value('estado'))->toBe('pendiente');
    actuarComo(superadmin());
    test()->putJson('/api/v1/plataforma/instituciones/'.demoId().'/aula', ['aula_opcion' => 'medida'])->assertOk();
    dueno()->table('moodle_cursos')->where('institucion_id', demoId())->delete();
    dueno()->table('moodle_conexiones')->where('institucion_id', demoId())->delete();
});

// ─────────────────────────────── EDUN-49 · Horario sin cruces

it('arma el horario sin cruces de docente ni de aula, por sección y por docente, y lo exporta en PDF', function () {
    $bloques = enDemo(coordinador(), 'PUT', '/bloques-horarios', ['turno' => 'manana', 'bloques' => [
        ['hora_inicio' => '08:00', 'hora_fin' => '08:45'], ['hora_inicio' => '08:45', 'hora_fin' => '09:30'],
        ['hora_inicio' => '09:30', 'hora_fin' => '09:50', 'es_recreo' => true], ['hora_inicio' => '09:50', 'hora_fin' => '10:35'],
    ]])->assertOk()->json('data');
    enDemo(coordinador(), 'PUT', '/bloques-horarios', ['turno' => 'manana', 'bloques' => [['hora_inicio' => '08:00', 'hora_fin' => '08:45'], ['hora_inicio' => '08:30', 'hora_fin' => '09:00']]])
        ->assertUnprocessable();
    [$b1, , $recreo] = array_column($bloques, 'id');

    // Sección C (mañana) con la misma docente de Matemática de la 3.° A.
    $c = enDemo(coordinador(), 'POST', '/secciones', ['anio_escolar_id' => anio2027(), 'grado_id' => grado3Sec(), 'nombre' => 'F', 'turno' => 'manana'])->assertCreated()->json('id');
    $mat = dueno()->table('areas_curriculares')->where('codigo', 'SEC-MAT')->value('id');
    $cargaA = dueno()->table('cargas_docentes')->where('seccion_id', seccion3A())->where('area_curricular_id', $mat)->first();
    $cargaC = enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => $c, 'area_curricular_id' => $mat, 'personal_id' => $cargaA->personal_id])->json('carga_id');
    $lab = enDemo(coordinador(), 'POST', '/ambientes', ['nombre' => 'Laboratorio de cómputo', 'tipo' => 'computo'])->json('id');

    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => seccion3A(), 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaA->id, 'ambiente_id' => $lab])->assertOk();
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaC])
        ->assertUnprocessable()->assertJsonPath('errors.carga_docente_id.0', fn ($m) => str_contains($m, 'ya tiene clase el lunes a las 08:00 en 3.° de Secundaria A'));
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 1, 'bloque_horario_id' => $recreo, 'carga_docente_id' => $cargaC])
        ->assertUnprocessable()->assertJsonPath('errors.bloque_horario_id.0', 'En el recreo no se asignan clases.');

    // El aula también se cruza: otra docente en la C usando el laboratorio a la misma hora.
    $com = dueno()->table('areas_curriculares')->where('codigo', 'SEC-COM')->value('id');
    $cargaCom = enDemo(coordinador(), 'PUT', '/cargas', ['seccion_id' => $c, 'area_curricular_id' => $com, 'personal_id' => docenteNuevo()])->json('carga_id');
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaCom, 'ambiente_id' => $lab])
        ->assertUnprocessable()->assertJsonPath('errors.ambiente_id.0', fn ($m) => str_contains($m, 'Laboratorio de cómputo ya está ocupado'));
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 2, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaC])->assertOk();

    // La base también lo impide aunque se salte la validación.
    expect(fn () => DB::transaction(function () use ($c, $b1, $cargaC, $cargaA) {
        DB::select("select set_config('app.institucion_id', ?, true)", [(string) demoId()]);
        DB::table('horario_sesiones')->insert(['institucion_id' => demoId(), 'seccion_id' => $c, 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaC, 'personal_id' => $cargaA->personal_id]);
    }))->toThrow(QueryException::class);

    $porSeccion = enDemo(coordinador(), 'GET', '/horarios?anio_escolar_id='.anio2027().'&seccion_id='.seccion3A())->assertOk();
    expect($porSeccion->json('celdas.0'))->toMatchArray(['dia_semana' => 1, 'area' => 'Matemática', 'ambiente' => 'Laboratorio de cómputo'])
        ->and(collect($porSeccion->json('cargas'))->pluck('area'))->toContain('Matemática');
    $porDocente = enDemo(personaDemo('docente'), 'GET', '/horarios?anio_escolar_id='.anio2027().'&personal_id='.$cargaA->personal_id)->assertOk();
    expect(collect($porDocente->json('celdas'))->pluck('seccion')->unique()->sort()->values()->all())->toBe(['3.° de Secundaria A', '3.° de Secundaria F']);

    $pdf = enDemo(coordinador(), 'GET', '/horarios/pdf?anio_escolar_id='.anio2027().'&seccion_id='.seccion3A())->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    // Quitar la clase deja la celda libre.
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => seccion3A(), 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => null])->assertOk();
    enDemo(coordinador(), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 1, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaC])->assertOk();
    enDemo(personaDemo('docente'), 'PUT', '/horarios/celda', ['seccion_id' => $c, 'dia_semana' => 3, 'bloque_horario_id' => $b1, 'carga_docente_id' => $cargaC])->assertForbidden();
});

// ─────────────────────────────── EDUN-50 · Alumno y apoderados

it('registra al alumno con apoderados, responsable de pago, autorizados a recoger y matrícula', function () {
    $secretaria = personaDemo('secretaria');
    $base = ['apellido_paterno' => 'Mendoza', 'apellido_materno' => 'Ríos', 'nombres' => 'Tomás', 'sexo' => 'H', 'fecha_nacimiento' => '2013-05-04'];
    enDemo($secretaria, 'POST', '/alumnos', $base + ['numero_documento' => '1234567'])->assertJsonPath('errors.numero_documento.0', 'El DNI del alumno debe tener 8 dígitos.');
    enDemo($secretaria, 'POST', '/alumnos', $base + ['codigo_estudiante_siagie' => '123'])->assertJsonPath('errors.codigo_estudiante_siagie.0', 'El código SIAGIE debe tener 14 dígitos.');

    $papa = ['numero_documento' => '40111222', 'apellidos' => 'Mendoza Paz', 'nombres' => 'Raúl', 'parentesco' => 'padre', 'celular' => '987111000', 'es_responsable_pago' => false];
    $mama = ['numero_documento' => '40111333', 'apellidos' => 'Ríos León', 'nombres' => 'Inés', 'parentesco' => 'madre', 'email' => 'ines@correo.pe', 'es_responsable_pago' => false, 'puede_recoger' => true];
    enDemo($secretaria, 'POST', '/alumnos', $base + ['numero_documento' => '78111222', 'apoderados' => [$papa, $mama]])
        ->assertUnprocessable()->assertJsonPath('errors.apoderados.0', 'Marca al menos un apoderado como responsable de pago.');

    $mama['es_responsable_pago'] = true;
    $r = enDemo($secretaria, 'POST', '/alumnos', $base + [
        'numero_documento' => '78111222', 'codigo_estudiante_siagie' => '12345678901234', 'apoderados' => [$papa, $mama],
        'autorizados' => [['nombres' => 'Rosa Ríos (tía)', 'numero_documento' => '40999888', 'parentesco' => 'tía']], 'seccion_id' => seccion3A(),
    ])->assertCreated();
    $id = $r->json('id');
    expect($r->json('apoderados'))->toHaveCount(2)
        ->and($r->json('apoderados.0'))->toMatchArray(['nombres' => 'Inés', 'es_responsable_pago' => true])
        ->and($r->json('autorizados.0.nombres'))->toBe('Rosa Ríos (tía)')
        ->and($r->json('matriculas.0.seccion'))->toBe('3.° de Secundaria A');

    // Hermano: reutiliza a los mismos apoderados.
    $antes = dueno()->table('apoderados')->where('institucion_id', demoId())->count();
    enDemo($secretaria, 'POST', '/alumnos', ['apellido_paterno' => 'Mendoza', 'nombres' => 'Lía', 'numero_documento' => '78111333', 'apoderados' => [$mama]])->assertCreated();
    expect(dueno()->table('apoderados')->where('institucion_id', demoId())->count())->toBe($antes);

    enDemo($secretaria, 'POST', '/alumnos', ['apellido_paterno' => 'Otro', 'nombres' => 'Alumno', 'numero_documento' => '78111222'])
        ->assertUnprocessable()->assertJsonPath('errors.numero_documento.0', 'Ya hay un alumno con ese documento.');

    // Editar: quitar al papá y el autorizado.
    enDemo($secretaria, 'PUT', "/alumnos/{$id}", $base + ['numero_documento' => '78111222', 'apoderados' => [$mama], 'autorizados' => []])->assertOk()
        ->assertJsonCount(1, 'apoderados')->assertJsonCount(0, 'autorizados');
    expect(collect(enDemo($secretaria, 'GET', '/alumnos?buscar=mendoza')->json('data'))->pluck('nombres'))->toContain('Tomás', 'Lía');

    // El docente no registra alumnos; el coordinador (sin adm.matricula.crear) no matricula.
    enDemo(personaDemo('docente'), 'POST', '/alumnos', $base)->assertForbidden();
    expect(dueno()->table('auditoria')->where('accion', 'alumno_registrado')->where('registro_id', $id)->count())->toBe(1);
});

// ─────────────────────────────── EDUN-51 · Importar desde Excel

function excelDePrueba(array $filas): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $w = new Writer;
    $w->openToFile($ruta);
    $w->addRow(Row::fromValues(array_values(ImportacionAlumnos::COLUMNAS)));
    foreach ($filas as $f) {
        $w->addRow(Row::fromValues($f));
    }
    $w->close();

    return new UploadedFile($ruta, 'alumnos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('importa alumnos desde Excel con plantilla, vista previa con errores por fila y confirmación', function () {
    $secretaria = personaDemo('secretaria');
    $plantilla = enDemo($secretaria, 'GET', '/alumnos/importar/plantilla?anio_escolar_id='.anio2027())->assertOk();
    expect($plantilla->headers->get('Content-Type'))->toContain('spreadsheetml');

    $archivo = excelDePrueba([
        ['79000001', '', 'Gómez', 'Salas', 'Ana', 'M', '04/03/2012', '3° secundaria', 'a', '41000111', 'Salas Ruiz', 'Marta', 'Mamá', '987000111', 'marta@correo.pe', 'Sí'],
        [79000002, '', 'Pérez', '', 'Luis', 'H', '', '3.° de Secundaria', 'A', '', '', '', '', '', '', ''],
        ['7900003', '', 'Ruiz', '', 'Eva', 'X', '31/02/2012', '3.° de Secundaria', 'A', '', '', '', '', '', '', ''],
        ['79000004', '', '', '', 'Sin', '', '', '9.° de Secundaria', 'Z', '41000222', 'Lopez', 'Juan', 'vecino', '', '', ''],
        ['79000001', '', 'Gómez', '', 'Repetida', '', '', '3.° de Secundaria', 'A', '', '', '', '', '', '', ''],
    ]);
    $vista = enDemo($secretaria, 'POST', '/alumnos/importar/vista-previa', ['anio_escolar_id' => anio2027(), 'archivo' => $archivo])->assertOk();
    expect($vista->json())->toMatchArray(['total' => 5, 'validas' => 2, 'con_errores' => 3]);
    $filas = collect($vista->json('filas'))->keyBy('fila');
    expect($filas[2]['errores'])->toBe([])->and($filas[2]['datos']['apoderado']['parentesco'])->toBe('madre')
        ->and($filas[3]['datos']['dni'])->toBe('79000002')
        ->and($filas[4]['errores'])->toContain('El DNI del alumno debe tener 8 dígitos.', 'El sexo debe ser H o M.', 'La fecha de nacimiento no es válida (usa dd/mm/aaaa).')
        ->and(implode(' ', $filas[5]['errores']))->toContain('Falta el apellido paterno.')->toContain('No existe la sección 9.° de Secundaria Z')->toContain('parentesco no es válido')
        ->and($filas[6]['errores'])->toContain('El DNI se repite en la fila 2.');
    // La vista previa no guarda nada.
    expect(dueno()->table('alumnos')->where('numero_documento', '79000001')->exists())->toBeFalse();

    $r = enDemo($secretaria, 'POST', '/alumnos/importar/confirmar', ['token' => $vista->json('token')])->assertOk();
    expect($r->json())->toMatchArray(['alumnos_nuevos' => 2, 'matriculas' => 2, 'apoderados' => 1, 'omitidas' => 3]);
    $ana = dueno()->table('alumnos')->where('institucion_id', demoId())->where('numero_documento', '79000001')->first();
    expect($ana->fecha_nacimiento)->toBe('2012-03-04')
        ->and(dueno()->table('matriculas')->where('alumno_id', $ana->id)->value('seccion_id'))->toBe(seccion3A())
        ->and(dueno()->table('alumno_apoderado')->where('alumno_id', $ana->id)->value('es_responsable_pago'))->toBeTrue();

    enDemo($secretaria, 'POST', '/alumnos/importar/confirmar', ['token' => $vista->json('token')])->assertUnprocessable();

    // Reimportar a Ana: ya existe y ya está matriculada → no se duplica.
    $otra = enDemo($secretaria, 'POST', '/alumnos/importar/vista-previa', ['anio_escolar_id' => anio2027(), 'archivo' => excelDePrueba([
        ['79000001', '', 'Gómez', 'Salas', 'Ana', 'M', '', '3.° de Secundaria', 'A', '', '', '', '', '', '', ''],
    ])])->assertOk();
    $r2 = enDemo($secretaria, 'POST', '/alumnos/importar/confirmar', ['token' => $otra->json('token')])->assertOk();
    expect($r2->json())->toMatchArray(['alumnos_nuevos' => 0, 'alumnos_existentes' => 1, 'matriculas' => 0]);

    enDemo(coordinador(), 'GET', '/alumnos/importar/plantilla?anio_escolar_id='.anio2027())->assertForbidden();
});
