<?php

use App\Models\User;
use App\Modulos\SEG\Correo\InvitacionUsuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 14 · Familias y matrícula:
| EDUN-37 activar padres · EDUN-41 consentimientos · EDUN-52 ficha completa · EDUN-54 condiciones
| EDUN-56 matrícula en ventanilla · EDUN-57 retiro o traslado · EDUN-58 cambio de sección · EDUN-59 carné QR
*/

function seccionDemo(string $nombre = 'A'): int
{
    $anio = dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
    $grado = dueno()->table('grados')->where('institucion_id', demoId())->where('nivel', 'secundaria')->where('numero', 3)->value('id');
    $id = dueno()->table('secciones')->where('anio_escolar_id', $anio)->where('grado_id', $grado)->where('nombre', $nombre)->value('id');

    return (int) ($id ?? dueno()->table('secciones')->insertGetId(['institucion_id' => demoId(), 'anio_escolar_id' => $anio, 'grado_id' => $grado, 'nombre' => $nombre]));
}

/** Alumno nuevo del demo (sin matrícula) con un apoderado. */
function alumnoConApoderado(array $apoderado = []): array
{
    $r = enDemo(directorDemo(), 'POST', '/alumnos', [
        'apellido_paterno' => 'Familia', 'nombres' => 'Hijo '.uniqid(), 'numero_documento' => (string) random_int(60000000, 69999999),
        'apoderados' => [array_merge(['numero_documento' => (string) random_int(30000000, 39999999), 'apellidos' => 'Padre Prueba', 'nombres' => 'Carlos',
            'parentesco' => 'padre', 'es_responsable_pago' => true], $apoderado)],
    ])->assertCreated();

    return ['alumno' => $r->json('id'), 'apoderado' => $r->json('apoderados.0.id'), 'invitaciones' => $r->json('invitaciones')];
}

function publicarCondiciones2027(): void
{
    static $hecho = false;
    if (! $hecho) {
        $anio = dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
        enDemo(directorDemo(), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => str_repeat('Matrícula S/ 0, pensión mensual según cronograma. ', 3)])->assertCreated();
        $hecho = true;
    }
}

function publicarTextos(): void
{
    static $hecho = false;
    if (! $hecho) {
        foreach (['datos', 'imagen'] as $f) {
            enDemo(directorDemo(), 'POST', '/consentimientos/textos', ['finalidad' => $f, 'version' => '2027.1', 'contenido' => str_repeat("Autorizo el uso de {$f} de mi hijo(a) según la Ley 29733. ", 3)])->assertCreated();
        }
        $hecho = true;
    }
}

// ─────────────────────────────── EDUN-37 · Activar padres

it('al registrar un apoderado con correo se le crea el acceso y recibe la invitación', function () {
    Mail::fake();
    $correo = 'papa.'.uniqid().'@familia.prueba';
    $r = alumnoConApoderado(['email' => $correo]);
    expect($r['invitaciones'])->toHaveCount(1)->and($r['invitaciones'][0])->toMatchArray(['canal' => 'correo', 'reutilizado' => false]);
    $usuario = dueno()->table('apoderados')->where('id', $r['apoderado'])->value('user_id');
    expect($usuario)->not->toBeNull()
        ->and(dueno()->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.user_id', $usuario)->where('m.institucion_id', demoId())->value('r.codigo'))->toBe('apoderado');
    Mail::assertSent(InvitacionUsuario::class, fn ($m) => $m->hasTo($correo) && str_contains($m->d['roles'], 'Apoderado'));

    // Sin correo ni celular: no hay a quién invitar.
    expect(alumnoConApoderado()['invitaciones'])->toBe([]);
    // Solo celular: queda el enlace de WhatsApp para enviarlo.
    expect(alumnoConApoderado(['celular' => '987111999'])['invitaciones'][0]['whatsapp_url'])->toStartWith('https://wa.me/51987111999');
});

it('si el DNI ya es de un usuario de Edunova en otro colegio, se reutiliza sin crear otro', function () {
    Mail::fake();
    $otro = dueno()->table('instituciones')->insertGetId(['slug' => 'fam-'.uniqid(), 'nombre' => 'Otro colegio', 'codigo_modular' => (string) random_int(9000000, 9999999), 'ugel' => 'U', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
    $dni = (string) random_int(30000000, 39999999);
    $existente = dueno()->table('users')->insertGetId(['nombres' => 'Rosa', 'apellidos' => 'Madre', 'numero_documento' => $dni, 'email' => 'rosa.'.uniqid().'@familia.prueba', 'password' => Hash::make('ClaveSegura123')]);
    dueno()->table('membresias')->insert(['institucion_id' => $otro, 'user_id' => $existente, 'rol_id' => dueno()->table('roles')->where('codigo', 'apoderado')->value('id')]);
    $usuarios = dueno()->table('users')->count();

    $r = alumnoConApoderado(['numero_documento' => $dni, 'apellidos' => 'Madre', 'nombres' => 'Rosa', 'parentesco' => 'madre', 'celular' => '987000555']);
    expect($r['invitaciones'][0])->toMatchArray(['reutilizado' => true, 'canal' => null])
        ->and(dueno()->table('users')->count())->toBe($usuarios)
        ->and(dueno()->table('apoderados')->where('id', $r['apoderado'])->value('user_id'))->toBe($existente);
    Mail::assertNothingSent();

    // Ya entra con su clave y ahora ve los dos colegios.
    $ingreso = test()->postJson('/api/v1/auth/ingresar', ['usuario' => $dni, 'password' => 'ClaveSegura123'])->assertOk();
    expect(collect($ingreso->json('colegios'))->pluck('id'))->toContain(demoId(), $otro);
});

// ─────────────────────────────── EDUN-41 · Consentimientos

it('el padre acepta y revoca desde la app con la versión del texto; sin imagen autorizada no se publica la foto', function () {
    Storage::fake('objetos');
    publicarTextos();
    $r = alumnoConApoderado(['email' => 'cons.'.uniqid().'@familia.prueba']);
    $padre = User::query()->findOrFail(dueno()->table('apoderados')->where('id', $r['apoderado'])->value('user_id'));

    $familia = enDemo($padre, 'GET', '/me/familia')->assertOk();
    expect($familia->json('hijos'))->toHaveCount(1)
        ->and($familia->json('hijos.0.consentimientos.imagen.estado'))->toBe('pendiente')
        ->and($familia->json('textos.imagen.version'))->toBe('2027.1');

    // La foto existe, pero sin consentimiento de imagen el carné no la lleva.
    enDemo(directorDemo(), 'POST', "/alumnos/{$r['alumno']}/foto", ['foto' => UploadedFile::fake()->createWithContent('foto.png', base64_decode(LOGO_PNG))])->assertOk()
        ->assertJsonPath('imagen_autorizada', false);
    expect(enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/carne")->json('foto'))->toBeNull();

    enDemo($padre, 'POST', '/me/consentimientos', ['alumno_id' => $r['alumno'], 'finalidad' => 'imagen', 'otorgado' => true])->assertOk()
        ->assertJsonPath('consentimientos.imagen.estado', 'otorgado')->assertJsonPath('consentimientos.imagen.version', '2027.1');
    expect(enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/carne")->json('foto'))->toStartWith('data:image/png;base64,');

    enDemo($padre, 'POST', '/me/consentimientos', ['alumno_id' => $r['alumno'], 'finalidad' => 'imagen', 'otorgado' => false])->assertOk()
        ->assertJsonPath('consentimientos.imagen.estado', 'revocado');
    expect(enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/carne")->json('foto'))->toBeNull();

    // No puede decidir por un alumno que no es su hijo.
    $ajeno = dueno()->table('alumnos')->where('institucion_id', demoId())->value('id');
    enDemo($padre, 'POST', '/me/consentimientos', ['alumno_id' => $ajeno, 'finalidad' => 'datos', 'otorgado' => true])->assertNotFound();
    enDemo($padre, 'GET', "/me/carne/{$ajeno}")->assertNotFound();

    // La secretaría ve el estado y registra el consentimiento firmado en papel.
    $secretaria = personaDemo('secretaria');
    enDemo($secretaria, 'POST', '/consentimientos', ['alumno_id' => $r['alumno'], 'finalidad' => 'datos', 'otorgado' => true])->assertOk();
    $lista = collect(enDemo($secretaria, 'GET', '/consentimientos')->assertOk()->json('data'))->firstWhere('id', $r['alumno']);
    expect($lista['consentimientos']['datos']['estado'])->toBe('otorgado')->and($lista['consentimientos']['imagen']['estado'])->toBe('revocado');

    // Los alumnos migrados con «datos_e_imagen» cuentan como otorgados en ambas.
    $migrado = dueno()->table('consentimientos')->where('institucion_id', demoId())->where('finalidad', 'datos_e_imagen')->value('titular_id');
    expect(collect(enDemo($secretaria, 'GET', '/consentimientos')->json('data'))->firstWhere('id', $migrado)['consentimientos']['imagen']['estado'])->toBe('otorgado');

    // Nueva versión del texto: el consentimiento anterior queda desactualizado.
    enDemo(directorDemo(), 'POST', '/consentimientos/textos', ['finalidad' => 'datos', 'version' => '2027.2', 'contenido' => str_repeat('Nuevo texto de datos personales conforme a la Ley 29733. ', 3)])->assertCreated();
    enDemo(directorDemo(), 'POST', '/consentimientos/textos', ['finalidad' => 'datos', 'version' => '2027.2', 'contenido' => str_repeat('Otro texto. ', 10)])->assertUnprocessable();
    expect(enDemo($padre, 'GET', '/me/familia')->json('hijos.0.consentimientos.datos.desactualizado'))->toBeTrue();
    enDemo(personaDemo('docente'), 'GET', '/consentimientos')->assertForbidden();
});

// ─────────────────────────────── EDUN-52 · Ficha completa

it('la ficha completa trae historial, deuda, conducta y documentos; salud solo con permiso y auditada', function () {
    $alumno = (int) dueno()->table('matriculas')->where('seccion_id', seccionDemo('A'))->value('alumno_id');
    dueno()->table('incidencias_conducta')->insert(['institucion_id' => demoId(), 'alumno_id' => $alumno, 'fecha' => '2027-04-10', 'tipo' => 'merito', 'detalle' => 'Ayudó a un compañero.']);
    DB::connection('propietario')->transaction(function () use ($alumno) {
        dueno()->select("select set_config('app.acceso_salud', 'on', true), set_config('app.institucion_id', ?, true)", [(string) demoId()]);
        dueno()->table('salud.fichas')->insertOrIgnore(['institucion_id' => demoId(), 'alumno_id' => $alumno, 'alergias' => 'Penicilina']);
    });

    $f = enDemo(directorDemo(), 'GET', "/alumnos/{$alumno}/expediente")->assertOk();
    expect($f->json('historial.0.anio'))->toBe(2027)
        ->and($f->json('deuda.cargos'))->not->toBeEmpty()
        ->and($f->json('deuda'))->toHaveKeys(['vencida', 'por_vencer'])
        ->and($f->json('conducta.0.detalle'))->toBe('Ayudó a un compañero.')
        ->and($f->json('documentos'))->toHaveKeys(['expediente', 'emitidos'])
        ->and($f->json())->not->toHaveKey('salud')
        ->and($f->json('secciones'))->not->toContain('salud');

    $enfermeria = personaDemo('enfermeria');
    $salud = enDemo($enfermeria, 'GET', "/alumnos/{$alumno}/expediente")->assertOk();
    expect($salud->json('salud.alergias'))->toBe('Penicilina')->and($salud->json())->not->toHaveKey('deuda');
    expect(dueno()->table('auditoria')->where('accion', 'salud_consultada')->where('registro_id', $alumno)->where('user_id', $enfermeria->id)->exists())->toBeTrue();

    // El docente solo ve la ficha de alumnos de sus secciones.
    enDemo(personaDemo('docente'), 'GET', "/alumnos/{$alumno}/expediente")->assertForbidden();
});

// ─────────────────────────────── EDUN-54 · Condiciones económicas

it('publica las condiciones económicas del año con versiones', function () {
    $anio = enDemo(directorDemo(), 'POST', '/anios-escolares', ['anio' => 2034, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2034-03-01', 'fecha_fin' => '2034-12-15'])->json('id');
    $texto = str_repeat('Pensión mensual de marzo a diciembre, vence el último día hábil. ', 2);
    enDemo(directorDemo(), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => $texto])->assertCreated()->assertJsonPath('data.0.version', 1);
    enDemo(directorDemo(), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => $texto])->assertUnprocessable();
    enDemo(directorDemo(), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => $texto.' Mora según ley.'])->assertCreated()->assertJsonPath('data.0.version', 2);
    expect(enDemo(personaDemo('secretaria'), 'GET', "/condiciones-economicas?anio_escolar_id={$anio}")->assertOk()->json('data'))->toHaveCount(2);
    enDemo(personaDemo('docente'), 'POST', '/condiciones-economicas', ['anio_escolar_id' => $anio, 'contenido' => $texto.' otra'])->assertForbidden();
});

// ─────────────────────────────── EDUN-56 · Matrícula en ventanilla

it('matricula en ventanilla con aceptación de condiciones, genera los cargos y la constancia', function () {
    $r = alumnoConApoderado();
    $secretaria = personaDemo('secretaria');
    $anioSin = enDemo(directorDemo(), 'POST', '/anios-escolares', ['anio' => 2035, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2035-03-01', 'fecha_fin' => '2035-12-15'])->json('id');
    $seccionSin = dueno()->table('secciones')->insertGetId(['institucion_id' => demoId(), 'anio_escolar_id' => $anioSin, 'grado_id' => dueno()->table('secciones')->where('id', seccionDemo('J'))->value('grado_id'), 'nombre' => 'A']);
    enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => $seccionSin, 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('condiciones');

    publicarCondiciones2027();
    enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => false])
        ->assertJsonValidationErrors('acepta_condiciones');
    $otroApoderado = dueno()->table('apoderados')->where('institucion_id', demoId())->where('id', '<>', $r['apoderado'])->value('id');
    enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $otroApoderado, 'acepta_condiciones' => true])
        ->assertJsonValidationErrors('apoderado_id');

    $m = enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])->assertCreated();
    $matricula = $m->json('matricula_id');
    expect($m->json('cargos'))->toBeGreaterThan(0)
        ->and(dueno()->table('condiciones_aceptaciones')->where('matricula_id', $matricula)->where('apoderado_id', $r['apoderado'])->exists())->toBeTrue()
        ->and(dueno()->table('cargos')->where('matricula_id', $matricula)->where('descripcion', 'Pensión mensual marzo 2027')->exists())->toBeTrue();
    enDemo($secretaria, 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])
        ->assertJsonValidationErrors('seccion_id');

    $pdf = enDemo($secretaria, 'GET', "/matriculas/{$matricula}/constancia")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    enDemo(personaDemo('docente'), 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])->assertForbidden();
});

// ─────────────────────────────── EDUN-57 / EDUN-58 · Retiro, traslado y cambio de sección

it('el retiro anula los cargos futuros, quita el acceso al aula y a Moodle y avisa para el SIAGIE', function () {
    publicarCondiciones2027();
    $r = alumnoConApoderado();
    $matricula = enDemo(directorDemo(), 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])->json('matricula_id');
    // El alumno tiene usuario en el aula y en Moodle.
    $usuario = dueno()->table('users')->insertGetId(['nombres' => 'Alumno', 'apellidos' => 'Aula', 'email' => 'alu.'.uniqid().'@prueba']);
    dueno()->table('alumnos')->where('id', $r['alumno'])->update(['user_id' => $usuario]);
    dueno()->table('membresias')->insert(['institucion_id' => demoId(), 'user_id' => $usuario, 'rol_id' => dueno()->table('roles')->where('codigo', 'alumno')->value('id')]);
    $conexionPrevia = dueno()->table('moodle_conexiones')->where('institucion_id', demoId())->value('id');
    $conexion = $conexionPrevia ?? dueno()->table('moodle_conexiones')->insertGetId(['institucion_id' => demoId(), 'url' => 'https://moodle.prueba', 'token_cifrado' => 'x']);
    dueno()->table('moodle_usuarios')->insert(['institucion_id' => demoId(), 'moodle_conexion_id' => $conexion, 'user_id' => $usuario, 'moodle_user_id' => random_int(1000, 99999), 'username' => 'alu'.uniqid()]);

    $pendientesAntes = dueno()->table('cargos')->where('matricula_id', $matricula)->where('estado', 'pendiente')->where('vencimiento', '>', '2027-06-30')->count();
    enDemo(directorDemo(), 'POST', "/matriculas/{$matricula}/retiro", ['tipo' => 'traslado', 'fecha' => '2027-06-30', 'motivo' => 'Cambio de domicilio'])
        ->assertJsonValidationErrors('ie_destino');
    $res = enDemo(personaDemo('secretaria'), 'POST', "/matriculas/{$matricula}/retiro", ['tipo' => 'traslado', 'fecha' => '2027-06-30', 'motivo' => 'Cambio de domicilio', 'ie_destino' => 'IE 40001 Arequipa'])->assertOk();
    expect($res->json('cargos_anulados'))->toBe($pendientesAntes)->and($pendientesAntes)->toBeGreaterThan(0)
        ->and(dueno()->table('matriculas')->where('id', $matricula)->value('estado'))->toBe('trasladado')
        ->and(dueno()->table('membresias')->where('user_id', $usuario)->value('activo'))->toBeFalse()
        ->and(dueno()->table('moodle_usuarios')->where('user_id', $usuario)->value('suspendido'))->toBeTrue()
        ->and(dueno()->table('cargos')->where('matricula_id', $matricula)->where('vencimiento', '<=', '2027-06-30')->where('estado', 'anulado')->exists())->toBeFalse();

    $pendiente = collect(enDemo(personaDemo('secretaria'), 'GET', '/siagie/pendientes')->assertOk()->json('data'))->firstWhere('tipo', 'traslado_salida');
    expect($pendiente['detalle'])->toContain('IE 40001 Arequipa');
    enDemo(personaDemo('secretaria'), 'POST', "/siagie/pendientes/{$pendiente['id']}/atendido")->assertOk();
    expect(collect(enDemo(personaDemo('secretaria'), 'GET', '/siagie/pendientes')->json('data'))->pluck('id'))->not->toContain($pendiente['id']);
    enDemo(directorDemo(), 'POST', "/matriculas/{$matricula}/retiro", ['tipo' => 'retiro', 'fecha' => '2027-07-01', 'motivo' => 'X'])->assertUnprocessable();
    dueno()->table('moodle_usuarios')->where('user_id', $usuario)->delete();
    if (! $conexionPrevia) {
        dueno()->table('moodle_conexiones')->where('id', $conexion)->delete();
    }
});

it('el cambio de sección mantiene las notas del periodo y avisa para el SIAGIE', function () {
    publicarCondiciones2027();
    $r = alumnoConApoderado();
    $matricula = enDemo(directorDemo(), 'POST', '/matriculas', ['alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'apoderado_id' => $r['apoderado'], 'acepta_condiciones' => true])->json('matricula_id');
    $periodo = dueno()->table('periodos')->where('institucion_id', demoId())->where('estado', 'abierto')->orderBy('numero')->value('id');
    dueno()->table('calificaciones')->insert(['institucion_id' => demoId(), 'alumno_id' => $r['alumno'], 'seccion_id' => seccionDemo('J'), 'competencia_id' => dueno()->table('competencias')->value('id'), 'periodo_id' => $periodo, 'valor' => 'A']);

    $destino = seccionDemo('H');
    enDemo(directorDemo(), 'POST', "/matriculas/{$matricula}/cambio-seccion", ['seccion_id' => seccionDemo('J'), 'fecha' => '2027-05-02'])->assertJsonValidationErrors('seccion_id');
    $res = enDemo(directorDemo(), 'POST', "/matriculas/{$matricula}/cambio-seccion", ['seccion_id' => $destino, 'fecha' => '2027-05-02', 'motivo' => 'Pedido de la familia'])->assertOk();
    expect($res->json('notas_movidas'))->toBe(1)
        ->and(dueno()->table('matriculas')->where('id', $matricula)->value('seccion_id'))->toBe($destino)
        ->and(dueno()->table('calificaciones')->where('alumno_id', $r['alumno'])->value('seccion_id'))->toBe($destino)
        ->and(dueno()->table('siagie_pendientes')->where('matricula_id', $matricula)->where('tipo', 'cambio_seccion')->exists())->toBeTrue();
    $historial = enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/expediente")->json('historial.0.movimientos.0');
    expect($historial)->toMatchArray(['tipo' => 'cambio_seccion', 'seccion_destino' => 'H']);
});

// ─────────────────────────────── EDUN-59 · Carné con QR

it('genera el carné con un QR único que sirve para asistencia y comedor; regenerar invalida el anterior', function () {
    $r = alumnoConApoderado(['email' => 'carne.'.uniqid().'@familia.prueba']);
    $carne = enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/carne")->assertOk();
    $codigo = $carne->json('codigo');
    expect($codigo)->toMatch('/^EN[A-Z0-9]{10}$/')->and($carne->json('qr'))->toStartWith('data:image/svg+xml;base64,');
    expect(enDemo(directorDemo(), 'GET', "/alumnos/{$r['alumno']}/carne")->json('codigo'))->toBe($codigo);

    // El mismo código lo usan portería, asistencia y comedor.
    enDemo(personaDemo('porteria'), 'GET', "/carnes/{$codigo}")->assertOk()->assertJsonPath('id', $r['alumno']);
    enDemo(personaDemo('comedor'), 'GET', '/carnes/'.strtolower($codigo))->assertOk();

    $nuevo = enDemo(directorDemo(), 'POST', "/alumnos/{$r['alumno']}/carne")->assertOk()->json('codigo');
    expect($nuevo)->not->toBe($codigo);
    enDemo(personaDemo('porteria'), 'GET', "/carnes/{$codigo}")->assertNotFound();
    enDemo(personaDemo('porteria'), 'GET', "/carnes/{$nuevo}")->assertOk();

    // La familia lo ve en la app; el PDF sale para imprimir.
    $padre = User::query()->findOrFail(dueno()->table('apoderados')->where('id', $r['apoderado'])->value('user_id'));
    enDemo($padre, 'GET', "/me/carne/{$r['alumno']}")->assertOk()->assertJsonPath('codigo', $nuevo);
    $pdf = enDemo(directorDemo(), 'GET', '/carnes/pdf?seccion_id='.seccionDemo('J'))->assertOk();
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    enDemo(personaDemo('porteria'), 'POST', "/alumnos/{$r['alumno']}/carne")->assertForbidden();
});
