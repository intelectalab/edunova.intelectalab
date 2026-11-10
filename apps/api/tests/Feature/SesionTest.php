<?php

use App\Models\User;
use App\Modulos\SEG\Services\Sesion;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/*
| EDUN-35 / SEG-H01 · Iniciar sesión y elegir colegio.
*/

const CLAVE = DemoSeeder::DEMO_PASSWORD;

function ingresar(string $usuario, string $password = CLAVE)
{
    return test()->postJson('/api/v1/auth/ingresar', ['usuario' => $usuario, 'password' => $password]);
}

/** Usuario con membresía en el colegio demo y en un segundo colegio. */
function usuarioDeDosColegios(): array
{
    static $r = null;
    if ($r) {
        return $r;
    }
    $db = DB::connection('propietario');
    $demo = (int) $db->table('instituciones')->where('slug', 'smp')->value('id');
    $otro = $db->table('instituciones')->insertGetId(['slug' => 'segundo-'.uniqid(), 'nombre' => 'Colegio Segundo', 'codigo_modular' => '0000002', 'ugel' => 'UGEL Y', 'nivel_educativo' => 'primaria', 'estado' => 'activo']);
    $id = $db->table('users')->insertGetId(['nombres' => 'Rosa', 'apellidos' => 'Dos Colegios', 'numero_documento' => '29000123', 'email' => 'rosa@pruebas.edunova.local', 'password' => Hash::make(CLAVE)]);
    $db->table('membresias')->insert([
        ['institucion_id' => $demo, 'user_id' => $id, 'rol_id' => $db->table('roles')->where('codigo', 'secretaria')->value('id')],
        ['institucion_id' => $otro, 'user_id' => $id, 'rol_id' => $db->table('roles')->where('codigo', 'director')->value('id')],
    ]);

    return $r = ['usuario' => User::query()->find($id), 'demo' => $demo, 'otro' => $otro];
}

it('ingresa con correo y, con un solo colegio, queda elegido', function () {
    $r = ingresar('Director@Demo.Edunova.pe')->assertOk();

    expect($r->json('token'))->toBeString()
        ->and($r->json('usuario.nombres'))->toBe('María')
        ->and($r->json('colegio_activo.slug'))->toBe('smp')
        ->and($r->json('colegio_activo.roles'))->toContain('director', 'promotor')
        ->and($r->json('debe_elegir_colegio'))->toBeFalse();
});

it('ingresa con DNI', function () {
    ingresar('29000001')->assertOk()->assertJsonPath('usuario.email', 'director@demo.edunova.pe');
});

it('con datos errados da un mensaje que no revela si el usuario existe', function () {
    ingresar('director@demo.edunova.pe', 'otra-clave')->assertUnprocessable()->assertJsonPath('errors.usuario.0', Sesion::ERROR_CREDENCIALES);
    ingresar('nadie@demo.edunova.pe')->assertUnprocessable()->assertJsonPath('errors.usuario.0', Sesion::ERROR_CREDENCIALES);
});

it('una cuenta desactivada no ingresa', function () {
    $db = DB::connection('propietario');
    $id = $db->table('users')->insertGetId(['nombres' => 'Inactivo', 'apellidos' => 'X', 'numero_documento' => '29000777', 'password' => Hash::make(CLAVE), 'activo' => false]);
    ingresar('29000777')->assertUnprocessable();
    $db->table('users')->where('id', $id)->delete();
});

it('bloquea tras 5 intentos fallidos', function () {
    foreach (range(1, 5) as $i) {
        ingresar('29000001', 'mala')->assertUnprocessable();
    }
    ingresar('29000001')->assertStatus(429)->assertJsonPath('errors.usuario.0', fn ($m) => str_starts_with($m, 'Demasiados intentos'));
    RateLimiter::clear('ingreso:29000001|127.0.0.1');
});

it('con varios colegios debe elegir uno, y la sesión usa ese colegio', function () {
    $c = usuarioDeDosColegios();
    $r = ingresar('rosa@pruebas.edunova.local')->assertOk();
    expect($r->json('debe_elegir_colegio'))->toBeTrue()
        ->and(collect($r->json('colegios'))->pluck('id')->sort()->values()->all())->toBe(collect([$c['demo'], $c['otro']])->sort()->values()->all());
    $token = $r->json('token');

    // Sin colegio elegido, las rutas del colegio piden elegir.
    $this->withToken($token)->getJson('/api/v1/alumnos')->assertStatus(400);

    $this->withToken($token)->postJson('/api/v1/auth/colegio', ['institucion_id' => $c['demo']])
        ->assertOk()->assertJsonPath('colegio_activo.id', $c['demo'])->assertJsonPath('colegio_activo.roles', ['secretaria']);

    // La sesión fija app.institucion_id: ve los 8 alumnos del colegio demo (y no los del otro).
    $ids = $this->withToken($token)->getJson('/api/v1/alumnos')->assertOk()->json('data');
    expect(count($ids))->toBeGreaterThanOrEqual(8);

    // En el otro colegio es directora: primero el segundo factor (EDUN-38); allí no hay alumnos.
    $this->withToken($token)->postJson('/api/v1/auth/colegio', ['institucion_id' => $c['otro']])->assertOk();
    $this->withToken($token)->getJson('/api/v1/alumnos')->assertForbidden()->assertJsonPath('codigo', 'dos_pasos');
    verificarToken($token);
    expect($this->withToken($token)->getJson('/api/v1/alumnos')->assertOk()->json('data'))->toBe([]);
});

it('no puede elegir un colegio al que no pertenece', function () {
    $c = usuarioDeDosColegios();
    $token = ingresar('29000001')->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/colegio', ['institucion_id' => $c['otro']])
        ->assertForbidden()->assertJsonPath('errors.institucion_id.0', 'No perteneces a ese colegio.');
});

it('el token de un colegio no sirve con la cabecera de otro', function () {
    $c = usuarioDeDosColegios();
    $token = ingresar('29000001')->json('token');
    verificarToken($token);

    // El token del director tiene fijado el colegio demo: la cabecera no lo cambia.
    $this->withToken($token)->getJson('/api/v1/alumnos', ['X-Institucion' => (string) $c['otro']])->assertOk();
    expect(count($this->withToken($token)->getJson('/api/v1/alumnos')->json('data')))->toBeGreaterThanOrEqual(8);
});

it('quién soy muestra los roles de plataforma del superadmin', function () {
    $token = ingresar('superadmin@demo.edunova.pe')->assertOk()->json('token');

    $this->withToken($token)->getJson('/api/v1/auth/yo')->assertOk()
        ->assertJsonPath('roles_plataforma', ['superadmin'])
        ->assertJsonPath('colegios', []);
});

it('cerrar sesión invalida el token', function () {
    $token = ingresar('29000001')->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/salir')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/yo')->assertUnauthorized();
});

it('la base impide dar un rol de colegio como rol de plataforma', function () {
    $db = DB::connection('propietario');
    $db->table('roles_plataforma')->insert([
        'user_id' => $db->table('users')->where('email', 'director@demo.edunova.pe')->value('id'),
        'rol_id' => $db->table('roles')->where('codigo', 'director')->value('id'),
    ]);
})->throws(QueryException::class, 'ámbito plataforma');
