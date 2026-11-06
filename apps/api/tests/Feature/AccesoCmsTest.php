<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/*
| EDUN-226 / SIT-042 · Acceso al Panel CMS solo para el superadmin del SaaS.
*/

function tokenDe(string $usuario): string
{
    return test()->postJson('/api/v1/auth/ingresar', ['usuario' => $usuario, 'password' => DemoSeeder::DEMO_PASSWORD])->assertOk()->json('token');
}

it('sin sesión, /api/v1/cms/* responde 401', function () {
    $this->getJson('/api/v1/cms/sesion')->assertUnauthorized();
});

it('un usuario de colegio (promotor y director) recibe 403', function () {
    // El director del colegio demo también es promotor (propietario): tampoco entra.
    $this->withToken(tokenDe('director@demo.edunova.pe'))->getJson('/api/v1/cms/sesion')
        ->assertForbidden()->assertJson(['message' => 'No tienes acceso al Panel CMS.']);
});

it('el superadmin entra, ve su nombre y la transacción queda con app.rol_cms', function () {
    $this->withToken(tokenDe('superadmin@demo.edunova.pe'))->getJson('/api/v1/cms/sesion')->assertOk()
        ->assertJsonPath('usuario.nombre', 'Ana Administradora')
        ->assertJsonPath('usuario.rol', 'superadmin')
        ->assertJsonPath('rol_cms', 'superadmin');
});

it('un superadmin desactivado ya no entra', function () {
    $db = DB::connection('propietario');
    $id = $db->table('users')->where('email', 'superadmin@demo.edunova.pe')->value('id');
    $token = tokenDe('superadmin@demo.edunova.pe');

    $db->table('roles_plataforma')->where('user_id', $id)->update(['activo' => false]);
    try {
        $this->withToken($token)->getJson('/api/v1/cms/sesion')->assertForbidden();
    } finally {
        $db->table('roles_plataforma')->where('user_id', $id)->update(['activo' => true]);
    }
});
