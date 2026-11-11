<?php

use App\Models\User;
use App\Modulos\SEG\Correo\RecuperarClave;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/*
| Formas fáciles de entrar: recuperar la contraseña por correo y acceso de demostración por rol
| (solo con EDUNOVA_DEMO_ACCESO activo).
*/

function usuarioAcceso(): User
{
    $id = dueno()->table('users')->insertGetId(['nombres' => 'Clara', 'apellidos' => 'Acceso '.uniqid(), 'numero_documento' => (string) random_int(30000000, 39999999),
        'email' => 'clara.'.uniqid().'@acceso.prueba', 'password' => Hash::make('Vieja.Clave.2026')]);

    return User::query()->findOrFail($id);
}

it('recupera la contraseña con el DNI: enlace de un solo uso, cierra las sesiones y no revela si el usuario existe', function () {
    Queue::fake();
    $u = usuarioAcceso();
    $u->createToken('web');
    $generico = $this->postJson('/api/v1/auth/recuperar', ['usuario' => '00000000'])->assertOk()->json('message');
    $r = $this->postJson('/api/v1/auth/recuperar', ['usuario' => $u->numero_documento])->assertOk();
    expect($r->json('message'))->toBe($generico)
        ->and(dueno()->table('correos_bitacora')->where('destinatario', $u->email)->where('plantilla', 'recuperar_clave')->exists())->toBeTrue();

    // El enlace lleva un token que solo conocemos aquí: se reemplaza por uno conocido para la prueba.
    dueno()->table('password_reset_tokens')->where('email', $u->email)->update(['token' => Hash::make(str_repeat('a', 64))]);
    $this->postJson('/api/v1/auth/restablecer', ['correo' => $u->email, 'token' => str_repeat('b', 64), 'password' => 'Nueva.Clave.2026', 'password_confirmation' => 'Nueva.Clave.2026'])
        ->assertUnprocessable()->assertJsonValidationErrors('token');
    $this->postJson('/api/v1/auth/restablecer', ['correo' => $u->email, 'token' => str_repeat('a', 64), 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/restablecer', ['correo' => $u->email, 'token' => str_repeat('a', 64), 'password' => 'Nueva.Clave.2026', 'password_confirmation' => 'Nueva.Clave.2026'])->assertOk();
    expect(Hash::check('Nueva.Clave.2026', $u->fresh()->password))->toBeTrue()->and($u->tokens()->count())->toBe(0);
    // El mismo enlace no sirve dos veces.
    $this->postJson('/api/v1/auth/restablecer', ['correo' => $u->email, 'token' => str_repeat('a', 64), 'password' => 'Otra.Clave.2026', 'password_confirmation' => 'Otra.Clave.2026'])->assertUnprocessable();
});

it('el correo de recuperación lleva el enlace a la web con el token', function () {
    $html = (new RecuperarClave(['nombre' => 'Clara', 'url' => 'https://ejemplo.prueba/app/#/restablecer?token=x', 'minutos' => 60]))->render();
    expect($html)->toContain('#/restablecer?token=x')->toContain('60 minutos');
});

it('el acceso de demostración solo existe si el servidor lo activa, y entra por rol sin segundo factor', function () {
    config(['edunova.demo.activo' => false]);
    expect($this->getJson('/api/v1/auth/demo')->assertOk()->json('activo'))->toBeFalse();
    $this->postJson('/api/v1/auth/demo', ['rol' => 'director'])->assertNotFound();

    config(['edunova.demo.activo' => true, 'edunova.demo.colegio' => 'smp']);
    $o = $this->getJson('/api/v1/auth/demo')->assertOk();
    expect($o->json('activo'))->toBeTrue()->and(collect($o->json('roles'))->pluck('codigo')->all())->toContain('director');
    $r = $this->postJson('/api/v1/auth/demo', ['rol' => 'director'])->assertOk();
    expect($r->json('token'))->toBeString()->and($r->json('dos_pasos'))->toBe('ok')->and($r->json('colegio_activo.id'))->toBe(demoId())
        ->and($r->json('colegio_activo.permisos'))->not->toBeEmpty();
    $this->withToken($r->json('token'))->getJson('/api/v1/auth/yo')->assertOk()->assertJsonPath('dos_pasos', 'ok');
    $this->postJson('/api/v1/auth/demo', ['rol' => 'superadmin'])->assertUnprocessable();
});
