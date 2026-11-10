<?php

use App\Modulos\SEG\Correo\InvitacionUsuario;
use App\Modulos\SEG\Services\Totp;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| Sprint 12 · Base de la gestión del colegio:
| EDUN-24 alta de colegio · EDUN-25 módulos · EDUN-26 aula y Moodle · EDUN-36 invitaciones
| EDUN-38 segundo factor · EDUN-40 auditoría · EDUN-42 archivos · EDUN-43 institución · EDUN-44 año escolar
*/

const CLAVE_GC = DemoSeeder::DEMO_PASSWORD;

/** PNG de 64×64 (las pruebas no dependen de la extensión GD). */
const LOGO_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsCtiJfMbxwi+hcEKLNXzWgQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQELgsKmTEtqQeZBAAAAABJRU5ErkJggg==';

function altaValida(array $cambios = []): array
{
    $n = random_int(1000000, 8999999);

    return array_replace_recursive([
        'nombre' => 'IEP Prueba '.$n, 'ugel' => 'UGEL Arequipa Sur', 'ruc' => '20'.random_int(100000000, 999999999),
        'slug' => 'prueba-'.$n,
        'servicios' => [['nivel' => 'primaria', 'codigo_modular' => (string) $n], ['nivel' => 'secundaria', 'codigo_modular' => (string) ($n + 1)]],
        'plan' => 'P300', 'aula_opcion' => 'medida', 'modulos_extra' => ['BIB'],
        'promotor' => ['nombres' => 'Paula', 'apellidos' => 'Promotora', 'numero_documento' => (string) random_int(10000000, 99999999), 'email' => "promotora{$n}@gestion.prueba"],
    ], $cambios);
}

beforeEach(function () {
    Cache::flush();
});

// ─────────────────────────────── EDUN-38 · Segundo factor

it('TOTP coincide con el vector de prueba del RFC 6238', function () {
    // Secreto ASCII «12345678901234567890» en base32; en t=59 el código de 8 dígitos es 94287082.
    $secreto = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    expect(Totp::codigo($secreto, 59))->toBe('287082')
        ->and(Totp::verificar($secreto, '287082', 59))->not->toBeNull()
        ->and(Totp::verificar($secreto, '287083', 59))->toBeNull()
        ->and(Totp::codigo(Totp::generarSecreto()))->toMatch('/^\d{6}$/');
});

it('el director debe activar el segundo factor antes de usar el colegio, y luego verificarlo en cada ingreso', function () {
    $director = personaDemo('director');
    $r = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $director->email, 'password' => CLAVE_GC])->assertOk();
    expect($r->json('dos_pasos'))->toBe('configurar')->and($r->json('colegio_activo'))->not->toHaveKey('permisos');
    $token = $r->json('token');

    // Sin segundo factor, las rutas del colegio lo piden.
    $this->withToken($token)->getJson('/api/v1/institucion')->assertForbidden()->assertJsonPath('codigo', 'dos_pasos');

    $qr = $this->withToken($token)->postJson('/api/v1/auth/dos-pasos/iniciar')->assertOk();
    expect($qr->json('qr'))->toStartWith('data:image/svg+xml;base64,')->and($qr->json('uri'))->toStartWith('otpauth://totp/');
    $secreto = str_replace(' ', '', $qr->json('secreto'));

    $this->withToken($token)->postJson('/api/v1/auth/dos-pasos/activar', ['codigo' => '000000'])->assertUnprocessable();
    $activado = $this->withToken($token)->postJson('/api/v1/auth/dos-pasos/activar', ['codigo' => Totp::codigo($secreto)])->assertOk();
    $respaldos = $activado->json('codigos_respaldo');
    expect($respaldos)->toHaveCount(10);
    app('auth')->forgetGuards();

    // El secreto queda cifrado y los respaldos con hash.
    $fila = dueno()->table('users')->find($director->id);
    expect(Crypt::decryptString($fila->dos_pasos_secreto))->toBe($secreto)->and($fila->dos_pasos_respaldo)->not->toContain($respaldos[0]);

    $this->withToken($token)->getJson('/api/v1/institucion')->assertOk();
    expect($this->withToken($token)->getJson('/api/v1/auth/yo')->json('colegio_activo.permisos'))->toContain('adm.configuracion.editar');

    // Nuevo ingreso: pide el código; un código de respaldo sirve una sola vez.
    $otro = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $director->email, 'password' => CLAVE_GC])->assertOk();
    expect($otro->json('dos_pasos'))->toBe('verificar');
    $t2 = $otro->json('token');
    app('auth')->forgetGuards();
    $this->withToken($t2)->postJson('/api/v1/auth/dos-pasos/verificar', ['codigo' => $respaldos[0]])->assertOk()->assertJsonPath('uso_respaldo', true)->assertJsonPath('respaldos_restantes', 9);
    app('auth')->forgetGuards();
    $this->withToken($t2)->getJson('/api/v1/institucion')->assertOk();

    $t3 = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $director->email, 'password' => CLAVE_GC])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($t3)->postJson('/api/v1/auth/dos-pasos/verificar', ['codigo' => $respaldos[0]])->assertUnprocessable();
    RateLimiter::clear('dos-pasos:'.$director->id);
});

it('el mismo código de la app no se acepta dos veces', function () {
    $director = personaDemo('tesoreria');
    $secreto = Totp::generarSecreto();
    dueno()->table('users')->where('id', $director->id)->update(['dos_pasos_secreto' => Crypt::encryptString($secreto), 'dos_pasos_activo_at' => now()]);
    $codigo = Totp::codigo($secreto);

    $t1 = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $director->email, 'password' => CLAVE_GC])->json('token');
    $this->withToken($t1)->postJson('/api/v1/auth/dos-pasos/verificar', ['codigo' => $codigo])->assertOk();
    app('auth')->forgetGuards();
    $t2 = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $director->email, 'password' => CLAVE_GC])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($t2)->postJson('/api/v1/auth/dos-pasos/verificar', ['codigo' => $codigo])->assertUnprocessable();
    RateLimiter::clear('dos-pasos:'.$director->id);
});

it('la secretaría no necesita segundo factor y recibe permisos, módulos y aula del colegio', function () {
    $secretaria = personaDemo('secretaria');
    $r = $this->postJson('/api/v1/auth/ingresar', ['usuario' => $secretaria->email, 'password' => CLAVE_GC])->assertOk();
    expect($r->json('dos_pasos'))->toBe('no_requerido')
        ->and($r->json('colegio_activo.permisos'))->toContain('adm.alumnos.ver')
        ->and($r->json('colegio_activo.modulos'))->toContain('ADM', 'PLA', 'SEG', 'AUL')
        ->and($r->json('colegio_activo.aula_opcion'))->toBe('medida');
});

// ─────────────────────────────── EDUN-24 · Alta de un colegio

it('solo el superadmin con segundo factor usa la consola de la plataforma', function () {
    actuarComo(superadmin(), dosPasos: false);
    $this->getJson('/api/v1/plataforma/instituciones')->assertForbidden()->assertJsonPath('codigo', 'dos_pasos');
    actuarComo(directorDemo());
    $this->getJson('/api/v1/plataforma/instituciones')->assertForbidden();
    actuarComo(superadmin());
    $this->getJson('/api/v1/plataforma/instituciones')->assertOk()->assertJsonPath('data.0.nombre', fn ($n) => is_string($n));
});

it('da de alta un colegio con sus códigos modulares, plan, módulos y promotor invitado por correo', function () {
    Mail::fake();
    actuarComo(superadmin());
    $datos = altaValida();
    $r = $this->postJson('/api/v1/plataforma/instituciones', $datos)->assertCreated();
    $id = $r->json('id');

    expect($r->json('estado'))->toBe('implementacion')
        ->and($r->json('nivel_educativo'))->toBe('multinivel')
        ->and(collect($r->json('servicios'))->pluck('codigo_modular')->all())->toBe(array_column($datos['servicios'], 'codigo_modular'))
        ->and($r->json('subdominio'))->toBe($datos['slug'].'.'.config('edunova.dominio_colegios'))
        ->and($r->json('suscripcion.plan'))->toBe('P300')
        ->and(collect($r->json('modulos'))->where('activo', true)->pluck('codigo')->all())->toContain('ADM', 'PAG', 'AUL', 'BIB')->not->toContain('MDL', 'MOV');

    $promotor = dueno()->table('users')->where('email', $datos['promotor']['email'])->first();
    expect($promotor->password)->toBeNull()
        ->and(dueno()->table('membresias')->where('institucion_id', $id)->where('user_id', $promotor->id)->count())->toBe(1)
        ->and(dueno()->table('invitaciones')->where('institucion_id', $id)->where('user_id', $promotor->id)->whereNull('usada_at')->count())->toBe(1)
        ->and(dueno()->table('auditoria')->where('institucion_id', $id)->where('accion', 'alta_institucion')->count())->toBe(1);
    Mail::assertSent(InvitacionUsuario::class, fn ($m) => $m->hasTo($datos['promotor']['email']) && str_contains($m->d['url'], '?invitacion='));
});

it('no repite códigos modulares ni subdominios y no da subdominios reservados', function () {
    Mail::fake();
    actuarComo(superadmin());
    $this->postJson('/api/v1/plataforma/instituciones', altaValida(['servicios' => [['nivel' => 'primaria', 'codigo_modular' => '0784512']]]))
        ->assertUnprocessable()->assertJsonPath('errors.servicios.0', 'El código modular 0784512 ya está registrado en Edunova.');
    $this->postJson('/api/v1/plataforma/instituciones', altaValida(['slug' => 'smp']))->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson('/api/v1/plataforma/instituciones', altaValida(['slug' => 'admin']))->assertUnprocessable()->assertJsonPath('errors.slug.0', 'Ese subdominio está reservado.');
    $this->postJson('/api/v1/plataforma/instituciones', altaValida(['servicios' => [['nivel' => 'primaria', 'codigo_modular' => '1234567'], ['nivel' => 'primaria', 'codigo_modular' => '1234568']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('servicios');
    $this->postJson('/api/v1/plataforma/instituciones', altaValida(['servicios' => [['nivel' => 'primaria', 'codigo_modular' => '12345']]]))
        ->assertUnprocessable()->assertJsonPath('errors', fn ($e) => isset($e['servicios.0.codigo_modular']));
});

// ─────────────────────────────── EDUN-25 · Módulos

it('apagar un módulo responde 403 en sus rutas de inmediato y queda en auditoría', function () {
    Route::middleware(['api', 'auth:sanctum', 'colegio', 'modulo:MOV'])->get('/api/v1/prueba-modulo-mov', fn () => ['ok' => true]);
    $director = directorDemo();

    enDemo($director, 'GET', '/prueba-modulo-mov')->assertOk();

    actuarComo(superadmin());
    $this->putJson('/api/v1/plataforma/instituciones/'.demoId().'/modulos/MOV', ['activo' => false])->assertOk();
    enDemo($director, 'GET', '/prueba-modulo-mov')->assertForbidden()->assertJsonPath('codigo', 'modulo_inactivo')
        ->assertJsonPath('message', 'El módulo «Movilidad escolar» no está activo para tu colegio.');
    expect(enDemo($director, 'GET', '/auth/yo')->json())->toBeArray();

    actuarComo(superadmin());
    $this->putJson('/api/v1/plataforma/instituciones/'.demoId().'/modulos/MOV', ['activo' => true])->assertOk();
    enDemo($director, 'GET', '/prueba-modulo-mov')->assertOk();

    $acciones = dueno()->table('auditoria')->where('institucion_id', demoId())->where('tabla', 'suscripcion_modulos')->orderBy('id')->pluck('accion')->all();
    expect(array_slice($acciones, -2))->toBe(['modulo_desactivado', 'modulo_activado']);

    actuarComo(superadmin());
    $this->putJson('/api/v1/plataforma/instituciones/'.demoId().'/modulos/ADM', ['activo' => false])->assertUnprocessable();
});

// ─────────────────────────────── EDUN-26 · Aula y Moodle

it('la opción de aula enciende Moodle y su conector; con aula a medida el conector no existe', function () {
    $director = directorDemo();
    enDemo($director, 'GET', '/moodle/conexion')->assertForbidden();

    actuarComo(superadmin());
    $r = $this->putJson('/api/v1/plataforma/instituciones/'.demoId().'/aula', ['aula_opcion' => 'moodle'])->assertOk();
    expect(collect($r->json('modulos'))->firstWhere('codigo', 'MDL')['activo'])->toBeTrue()
        ->and(collect($r->json('modulos'))->firstWhere('codigo', 'AUL')['activo'])->toBeFalse();

    enDemo($director, 'GET', '/moodle/conexion')->assertOk()->assertJsonPath('configurado', false);
    enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'http://moodle.colegio.pe'])->assertUnprocessable()->assertJsonValidationErrors(['url', 'token']);
    $guardado = enDemo($director, 'PUT', '/moodle/conexion', ['url' => 'https://moodle.colegio.pe/', 'token' => 'abcdef0123456789abcdef'])->assertOk();
    expect($guardado->json())->toMatchArray(['configurado' => true, 'url' => 'https://moodle.colegio.pe', 'tiene_token' => true])->not->toHaveKey('token');
    expect(Crypt::decryptString(dueno()->table('moodle_conexiones')->where('institucion_id', demoId())->value('token_cifrado')))->toBe('abcdef0123456789abcdef');
    expect(enDemo($director, 'GET', '/auth/yo')->json('colegio_activo.aula_opcion'))->toBe('moodle');

    actuarComo(superadmin());
    $this->putJson('/api/v1/plataforma/instituciones/'.demoId().'/aula', ['aula_opcion' => 'medida'])->assertOk();
    enDemo($director, 'GET', '/moodle/conexion')->assertForbidden();
    expect(dueno()->table('auditoria')->where('institucion_id', demoId())->where('accion', 'aula_cambiada')->count())->toBeGreaterThanOrEqual(2);
});

// ─────────────────────────────── EDUN-36 · Invitar usuarios por rol

it('invita por WhatsApp con varios roles; el enlace sirve una sola vez y el invitado crea su contraseña', function () {
    $director = directorDemo();
    $r = enDemo($director, 'POST', '/usuarios', [
        'nombres' => 'Lucía', 'apellidos' => 'Invitada', 'numero_documento' => '45000123', 'celular' => '987 111 222',
        'roles' => ['secretaria', 'admision'], 'canal' => 'whatsapp',
    ])->assertCreated();
    $wa = $r->json('invitacion.whatsapp_url');
    expect($wa)->toStartWith('https://wa.me/51987111222?text=');
    preg_match('/invitacion%3D([A-Za-z0-9]{48})/', $wa, $m);
    $token = $m[1];

    $usuarioId = $r->json('usuario_id');
    expect(dueno()->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->where('m.user_id', $usuarioId)->where('m.activo', true)->pluck('r.codigo')->sort()->values()->all())
        ->toBe(['admision', 'secretaria']);
    // Solo se guarda el hash del enlace.
    expect(dueno()->table('invitaciones')->where('user_id', $usuarioId)->value('token_hash'))->toBe(hash('sha256', $token));

    $this->getJson("/api/v1/invitaciones/{$token}")->assertOk()->assertJson(['nombres' => 'Lucía', 'tiene_password' => false]);
    $this->postJson("/api/v1/invitaciones/{$token}/aceptar", ['password' => 'corta', 'password_confirmation' => 'corta'])->assertUnprocessable();
    $this->postJson("/api/v1/invitaciones/{$token}/aceptar", ['password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123'])->assertOk();
    $this->postJson("/api/v1/invitaciones/{$token}/aceptar", ['password' => 'OtraClave1234', 'password_confirmation' => 'OtraClave1234'])->assertStatus(410);
    $this->getJson("/api/v1/invitaciones/{$token}")->assertStatus(410);

    $ingreso = $this->postJson('/api/v1/auth/ingresar', ['usuario' => '45000123', 'password' => 'ClaveSegura123'])->assertOk();
    expect($ingreso->json('colegio_activo.roles'))->toEqualCanonicalizing(['secretaria', 'admision']);
    expect(dueno()->table('auditoria')->where('accion', 'usuario_invitado')->where('registro_id', $usuarioId)->count())->toBe(1);
});

it('invita por correo y reutiliza a quien ya usa Edunova en otro colegio', function () {
    Mail::fake();
    $otro = dueno()->table('instituciones')->insertGetId(['slug' => 'reuso-'.uniqid(), 'nombre' => 'Colegio Reuso', 'codigo_modular' => (string) random_int(9000000, 9999999), 'ugel' => 'UGEL', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
    $docente = personaDemo('docente', $otro);

    $r = enDemo(directorDemo(), 'POST', '/usuarios', [
        'nombres' => 'X', 'apellidos' => 'Y', 'email' => strtoupper($docente->email), 'roles' => ['docente'], 'canal' => 'correo',
    ])->assertCreated();
    expect($r->json('usuario_id'))->toBe($docente->id)->and($r->json('invitacion.whatsapp_url'))->toBeNull();
    Mail::assertSent(InvitacionUsuario::class, fn ($m) => $m->hasTo($docente->email));

    // Ya es usuario del colegio: no se invita dos veces.
    enDemo(directorDemo(), 'POST', '/usuarios', ['nombres' => 'X', 'apellidos' => 'Y', 'email' => $docente->email, 'roles' => ['tutor'], 'canal' => 'correo'])
        ->assertUnprocessable();
});

it('solo un promotor asigna el rol de promotor y nadie cambia sus propios roles', function () {
    $soloDirector = personaDemo('director');
    $invitado = personaDemo('docente');

    enDemo($soloDirector, 'PUT', "/usuarios/{$invitado->id}/roles", ['roles' => ['promotor']])->assertUnprocessable()
        ->assertJsonPath('errors.roles.0', 'Solo un promotor puede asignar el rol de promotor.');
    enDemo($soloDirector, 'PUT', "/usuarios/{$soloDirector->id}/roles", ['roles' => ['docente']])->assertUnprocessable();
    enDemo($soloDirector, 'PUT', "/usuarios/{$invitado->id}/roles", ['roles' => ['docente', 'tutor']])->assertOk();
    expect(collect(enDemo($soloDirector, 'GET', '/usuarios')->json('data'))->firstWhere('id', $invitado->id)['roles'])->toBe(['docente', 'tutor']);
    expect(collect(enDemo($soloDirector, 'GET', '/usuarios')->json('roles'))->pluck('codigo'))->not->toContain('promotor', 'apoderado');

    enDemo($soloDirector, 'PATCH', "/usuarios/{$invitado->id}/estado", ['activo' => false])->assertOk();
    expect(dueno()->table('membresias')->where('user_id', $invitado->id)->where('activo', true)->count())->toBe(0);
    $registro = dueno()->table('auditoria')->where('accion', 'roles_cambiados')->where('registro_id', $invitado->id)->first();
    expect(json_decode($registro->antes, true))->toBe(['roles' => ['docente']])->and(json_decode($registro->despues, true))->toBe(['roles' => ['docente', 'tutor']]);

    // La secretaría no administra usuarios.
    enDemo(personaDemo('secretaria'), 'GET', '/usuarios')->assertForbidden();
});

// ─────────────────────────────── EDUN-40 · Auditoría

it('la auditoría muestra antes y después, filtra, exporta y no se puede borrar', function () {
    $director = directorDemo();
    enDemo($director, 'PUT', '/institucion', ['nombre' => 'IEP San Martín de Porres', 'ugel' => 'UGEL Arequipa Norte', 'telefono' => '054 111 222'])->assertOk();

    $lista = enDemo($director, 'GET', '/auditoria?accion=configuracion_editada')->assertOk();
    $fila = $lista->json('data.0');
    expect($fila['accion_nombre'])->toBe('Datos del colegio editados')
        ->and($fila['despues']['telefono'])->toBe('054 111 222')
        ->and($fila)->toHaveKeys(['antes', 'despues', 'usuario', 'ip']);
    expect(collect(enDemo($director, 'GET', '/auditoria?accion=alta_institucion')->json('data'))->every(fn ($f) => $f['accion'] === 'alta_institucion'))->toBeTrue();
    enDemo($director, 'GET', '/auditoria?desde=2026-02-01&hasta=2026-01-01')->assertUnprocessable();
    $opciones = enDemo($director, 'GET', '/auditoria/opciones')->assertOk();
    expect(collect($opciones->json('acciones'))->pluck('codigo'))->toContain('configuracion_editada')->and($opciones->json('usuarios'))->not->toBeEmpty();

    $csv = enDemo($director, 'GET', '/auditoria/exportar?accion=configuracion_editada')->assertOk();
    expect($csv->streamedContent())->toContain('Fecha,Usuario,Acción')->toContain('Datos del colegio editados');

    // No existe ruta para borrar ni editar.
    enDemo($director, 'DELETE', '/auditoria/'.$fila['id'])->assertNotFound();
    enDemo($director, 'PUT', '/auditoria/'.$fila['id'], ['accion' => 'x'])->assertNotFound();
    expect(fn () => DB::transaction(function () {
        DB::select("select set_config('app.institucion_id', ?, true)", [(string) demoId()]);
        DB::table('auditoria')->where('institucion_id', demoId())->delete();
    }))->toThrow(QueryException::class);

    enDemo(personaDemo('docente'), 'GET', '/auditoria')->assertForbidden();
});

// ─────────────────────────────── EDUN-42 · Archivos

it('los archivos confidenciales solo se descargan con permiso, por enlace de 5 minutos', function () {
    Storage::fake('objetos');
    config()->set('filesystems.disks.objetos.temporary_url', 'http://localhost:9000');
    $secretaria = personaDemo('secretaria');
    $docente = personaDemo('docente');

    $conf = enDemo($secretaria, 'POST', '/archivos', ['archivo' => UploadedFile::fake()->create('informe.pdf', 20, 'application/pdf'), 'confidencial' => true])->assertCreated();
    $comun = enDemo($secretaria, 'POST', '/archivos', ['archivo' => UploadedFile::fake()->create('horario.pdf', 20, 'application/pdf')])->assertCreated();
    enDemo($secretaria, 'POST', '/archivos', ['archivo' => UploadedFile::fake()->create('script.exe', 5)])->assertUnprocessable();

    enDemo($docente, 'GET', "/archivos/{$conf->json('id')}/enlace")->assertForbidden();
    enDemo($docente, 'GET', "/archivos/{$comun->json('id')}/enlace")->assertOk();
    enDemo($secretaria, 'GET', "/archivos/{$conf->json('id')}/enlace")->assertOk();
    $enlace = enDemo(directorDemo(), 'GET', "/archivos/{$conf->json('id')}/enlace")->assertOk();
    expect($enlace->json('url'))->toContain('X-Amz-Expires=300')->and($enlace->json('vence_en_segundos'))->toBe(300);

    // De otro colegio: no existe.
    $otro = dueno()->table('instituciones')->insertGetId(['slug' => 'arch-'.uniqid(), 'nombre' => 'Otro', 'codigo_modular' => (string) random_int(9000000, 9999999), 'ugel' => 'U', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
    enDemo(personaDemo('director', $otro), 'GET', "/archivos/{$comun->json('id')}/enlace", [], $otro)->assertNotFound();
});

// ─────────────────────────────── EDUN-43 · Datos de la institución

it('el director edita datos MINEDU, códigos modulares, logo y color, que llegan a la web', function () {
    Storage::fake('objetos');
    $director = directorDemo();
    $datos = enDemo($director, 'GET', '/institucion')->assertOk();
    expect($datos->json('servicios'))->toHaveCount(3)->and($datos->json('estado'))->toBe('activo');

    enDemo($director, 'PUT', '/institucion', ['nombre' => 'IEP San Martín de Porres', 'ugel' => 'UGEL Arequipa Norte', 'ruc' => '123', 'color_primario' => 'azul'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ruc', 'color_primario']);
    enDemo($director, 'PUT', '/institucion', [
        'nombre' => 'IEP San Martín de Porres', 'ugel' => 'UGEL Arequipa Norte', 'ruc' => '20600000001', 'color_primario' => '#1D4ED8',
        'correo' => 'secretaria@smp.edu.pe', 'horario_atencion' => 'Lunes a viernes, 7:30 a 15:00', 'estado' => 'baja', 'slug' => 'otro',
    ])->assertOk()->assertJsonPath('color_primario', '#1d4ed8')->assertJsonPath('horario_atencion', 'Lunes a viernes, 7:30 a 15:00')
        ->assertJsonPath('estado', 'activo')->assertJsonPath('slug', 'smp');

    // Varios códigos modulares: un nivel sin grados se puede quitar; se cambia el código de primaria.
    $sinGrados = dueno()->table('servicios_educativos as s')->where('s.institucion_id', demoId())
        ->whereNotExists(fn ($q) => $q->from('grados as g')->whereColumn('g.servicio_educativo_id', 's.id'))->exists();
    enDemo($director, 'PUT', '/institucion/servicios', ['servicios' => [
        ['nivel' => 'secundaria', 'codigo_modular' => '0784512'], ['nivel' => 'primaria', 'codigo_modular' => '0784521'],
        ...($sinGrados ? [] : [['nivel' => 'inicial', 'codigo_modular' => '0784538']]),
    ]])->assertOk()->assertJsonCount($sinGrados ? 2 : 3, 'servicios')->assertJsonPath('nivel_educativo', 'multinivel');
    // Secundaria tiene grados: no se puede quitar.
    enDemo($director, 'PUT', '/institucion/servicios', ['servicios' => [['nivel' => 'primaria', 'codigo_modular' => '0784521']]])
        ->assertUnprocessable()->assertJsonPath('errors.servicios.0', 'No puedes quitar un nivel que ya tiene grados, secciones o alumnos.');
    enDemo($director, 'PUT', '/institucion/servicios', ['servicios' => [
        ['nivel' => 'secundaria', 'codigo_modular' => '0784512'], ['nivel' => 'primaria', 'codigo_modular' => '0784521'], ['nivel' => 'inicial', 'codigo_modular' => '0784538'],
    ]])->assertOk()->assertJsonCount(3, 'servicios');

    // Logo: se sube, se ve por la API y llega a /auth/yo con el color.
    enDemo($director, 'POST', '/institucion/logo', ['logo' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')])->assertUnprocessable();
    enDemo($director, 'POST', '/institucion/logo', ['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(LOGO_PNG))])->assertOk()->assertJsonPath('tiene_logo', true);
    $logo = enDemo(personaDemo('docente'), 'GET', '/institucion/logo')->assertOk();
    expect($logo->headers->get('Content-Type'))->toBe('image/png');
    expect(enDemo($director, 'GET', '/auth/yo')->json('colegio_activo.marca'))->toBe(['color_primario' => '#1d4ed8', 'tiene_logo' => true]);

    // La app no puede tocar el estado del colegio aunque lo intente directo en la base.
    expect(fn () => DB::transaction(function () {
        DB::select("select set_config('app.institucion_id', ?, true)", [(string) demoId()]);
        DB::table('instituciones')->where('id', demoId())->update(['estado' => 'baja']);
    }))->toThrow(QueryException::class);

    enDemo(personaDemo('secretaria'), 'PUT', '/institucion', ['nombre' => 'X', 'ugel' => 'Y'])->assertForbidden();
    expect(dueno()->table('auditoria')->where('institucion_id', demoId())->whereIn('accion', ['servicios_editados', 'logo_cambiado'])->count())->toBeGreaterThanOrEqual(2);
});

// ─────────────────────────────── EDUN-44 · Año escolar

it('crea el año por bimestres con periodos, límite de notas y feriados nacionales; luego se edita', function () {
    $director = directorDemo();
    $r = enDemo($director, 'POST', '/anios-escolares', ['anio' => 2026, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18'])->assertCreated();
    $id = $r->json('id');
    $periodos = $r->json('periodos');
    expect($periodos)->toHaveCount(4)
        ->and($periodos[0])->toMatchArray(['nombre' => 'I Bimestre', 'fecha_inicio' => '2026-03-02'])
        ->and($periodos[3]['fecha_fin'])->toBe('2026-12-18')
        ->and($periodos[0]['fecha_limite_notas'])->toBe(Carbon\Carbon::parse($periodos[0]['fecha_fin'])->addDays(7)->toDateString())
        ->and(collect($r->json('feriados'))->pluck('fecha'))->toContain('2026-07-28')->not->toContain('2026-01-01')
        ->and($r->json('aviso'))->toBeNull();
    for ($i = 1; $i < 4; $i++) {
        expect(Carbon\Carbon::parse($periodos[$i - 1]['fecha_fin'])->addDay()->toDateString())->toBe($periodos[$i]['fecha_inicio']);
    }

    enDemo($director, 'POST', '/anios-escolares', ['anio' => 2026, 'tipo_periodo' => 'trimestre', 'fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18'])
        ->assertUnprocessable()->assertJsonPath('errors.anio.0', 'El año escolar 2026 ya existe.');
    enDemo($director, 'POST', '/anios-escolares', ['anio' => 2029, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2029-03-01', 'fecha_fin' => '2030-01-10'])
        ->assertUnprocessable();

    // Fechas editables: vacaciones de medio año entre el II y el III bimestre, y un feriado propio.
    $editados = $periodos;
    $editados[1]['fecha_fin'] = '2026-07-17';
    $editados[2]['fecha_inicio'] = '2026-08-03';
    $feriados = [...$r->json('feriados'), ['fecha' => '2026-08-15', 'motivo' => 'Aniversario de Arequipa']];
    $editado = enDemo($director, 'PUT', "/anios-escolares/{$id}", ['fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18', 'periodos' => $editados, 'feriados' => $feriados])->assertOk();
    expect($editado->json('periodos.2.fecha_inicio'))->toBe('2026-08-03')->and(collect($editado->json('feriados'))->pluck('motivo'))->toContain('Aniversario de Arequipa');

    $cruce = $editados;
    $cruce[2]['fecha_inicio'] = '2026-07-10';
    enDemo($director, 'PUT', "/anios-escolares/{$id}", ['fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18', 'periodos' => $cruce])
        ->assertUnprocessable()->assertJsonPath('errors.periodos.0', 'El III Bimestre se cruza con el II Bimestre.');
    enDemo($director, 'PUT', "/anios-escolares/{$id}", ['fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18', 'feriados' => [['fecha' => '2026-12-25', 'motivo' => 'Navidad']]])
        ->assertUnprocessable()->assertJsonPath('errors.feriados.0', 'El feriado del 2026-12-25 está fuera del año escolar.');

    // Pasar a trimestres mientras nada se abrió.
    $tri = enDemo($director, 'PUT', "/anios-escolares/{$id}", ['tipo_periodo' => 'trimestre', 'fecha_inicio' => '2026-03-02', 'fecha_fin' => '2026-12-18'])->assertOk();
    expect(collect($tri->json('periodos'))->pluck('nombre')->all())->toBe(['I Trimestre', 'II Trimestre', 'III Trimestre']);

    // El año 2027 del demo ya tiene periodos abiertos: no cambia de tipo.
    $anio2027 = dueno()->table('anios_escolares')->where('institucion_id', demoId())->where('anio', 2027)->value('id');
    enDemo($director, 'PUT', "/anios-escolares/{$anio2027}", ['tipo_periodo' => 'trimestre', 'fecha_inicio' => '2027-03-08', 'fecha_fin' => '2027-12-18'])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_periodo');

    expect(dueno()->table('auditoria')->where('tabla', 'anios_escolares')->where('registro_id', $id)->pluck('accion')->all())
        ->toContain('anio_creado', 'anio_editado');
    enDemo(personaDemo('secretaria'), 'POST', '/anios-escolares', ['anio' => 2030, 'tipo_periodo' => 'bimestre', 'fecha_inicio' => '2030-03-01', 'fecha_fin' => '2030-12-15'])->assertForbidden();
});

it('sin feriados nacionales cargados para el año, avisa que se agreguen a mano', function () {
    $r = enDemo(directorDemo(), 'POST', '/anios-escolares', ['anio' => 2031, 'tipo_periodo' => 'trimestre', 'fecha_inicio' => '2031-03-03', 'fecha_fin' => '2031-12-19'])->assertCreated();
    expect($r->json('feriados'))->toBe([])->and($r->json('aviso'))->toContain('2031')->and($r->json('periodos'))->toHaveCount(3);
});

// ─────────────────────────────── Aislamiento de las tablas nuevas

it('toda tabla con institucion_id tiene RLS forzado, incluidas las invitaciones', function () {
    $sinRls = dueno()->select("
        select c.relname from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'institucion_id' and not a.attisdropped
        where n.nspname in ('public', 'salud') and c.relkind = 'r'
          and c.relname not in ('instituciones', 'personal_access_tokens')
          and not (c.relrowsecurity and c.relforcerowsecurity)
    ");
    expect(array_column($sinRls, 'relname'))->toBe([]);

    // Un colegio no ve las invitaciones de otro.
    $otro = dueno()->table('instituciones')->insertGetId(['slug' => 'inv-'.uniqid(), 'nombre' => 'Otro', 'codigo_modular' => (string) random_int(9000000, 9999999), 'ugel' => 'U', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
    $visibles = DB::transaction(function () use ($otro) {
        DB::select("select set_config('app.institucion_id', ?, true)", [(string) $otro]);

        return DB::table('invitaciones')->count();
    });
    expect(dueno()->table('invitaciones')->where('institucion_id', '<>', $otro)->count())->toBeGreaterThan(0)->and($visibles)->toBe(0);
});
