<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Ayudas compartidas por varias pruebas de la API (se cargan desde tests/Pest.php).
*/

/** Conexión dueña (sin RLS): para preparar y revisar datos en las pruebas. */
function dueno()
{
    return DB::connection('propietario');
}

/** Cabeceras del servidor del sitio con la IP del visitante (SITIO_PROXY_SECRETO). */
function visitante(?string $ip = null): array
{
    config()->set('services.sitio.proxy_secreto', 'secreto-de-prueba');

    return ['X-Sitio-Secreto' => 'secreto-de-prueba', 'X-Visitante-Ip' => $ip ?? '198.51.100.'.random_int(1, 254)];
}

/** El superadmin de la plataforma sembrado por DemoSeeder. */
function superadmin(): User
{
    $id = dueno()->table('roles_plataforma as rp')->join('roles as r', 'r.id', '=', 'rp.rol_id')->where('r.codigo', 'superadmin')->value('rp.user_id');

    return User::findOrFail($id);
}

/** Leads de prueba para la bandeja (correo @bandeja.prueba). */
function sembrarLeads(int $n, array $datos = []): void
{
    $filas = [];
    for ($i = 1; $i <= $n; $i++) {
        $filas[] = [
            'source' => 'demo', 'nombre' => "Contacto {$i}", 'cargo' => 'Director(a)', 'email' => "lead{$i}-".uniqid().'@bandeja.prueba',
            'telefono' => '987654321', 'colegio' => "Colegio {$i}", 'alumnos' => 300, 'plan_interes' => $i % 3 === 0 ? 'integral' : 'inicial',
            'ciudad' => $i % 2 ? 'Arequipa' : 'Lima', 'consentimiento_version' => 'privacidad-v1.0', ...$datos,
        ];
    }
    dueno()->table('cms.leads')->insert($filas);
}
