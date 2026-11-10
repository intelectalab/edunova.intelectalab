<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/*
| Ayudas compartidas por varias pruebas de la API (se cargan desde tests/Pest.php).
*/

/** Conexión dueña (sin RLS): para preparar y revisar datos en las pruebas. */
function dueno()
{
    return DB::connection('propietario');
}

/**
 * Sesión de prueba (Sanctum::actingAs) con el segundo factor ya verificado (EDUN-38): los
 * promotores, directores y tesoreros lo necesitan para las rutas del colegio.
 */
function actuarComo(User $usuario, bool $dosPasos = true, ?int $institucion = null): User
{
    Sanctum::actingAs($usuario);
    $token = new PersonalAccessToken;
    $token->forceFill(['dos_pasos_ok' => $dosPasos, 'institucion_id' => $institucion]);
    $usuario->withAccessToken($token);

    return $usuario;
}

/** Marca como verificada (segundo factor) la sesión de un token real. */
function verificarToken(string $token): void
{
    dueno()->table('personal_access_tokens')->where('id', (int) explode('|', $token)[0])->update(['dos_pasos_ok' => true]);
    // El guard guarda el usuario y su token entre peticiones de la misma prueba.
    app('auth')->forgetGuards();
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

// ── Colegio de demostración (Sprint 12 en adelante)

function demoId(): int
{
    return (int) dueno()->table('instituciones')->where('slug', 'smp')->value('id');
}

function directorDemo(): User
{
    return User::query()->where('email', 'director@demo.edunova.pe')->firstOrFail();
}

/** Usuario nuevo con un rol en el colegio demo (y contraseña, para probar el ingreso). */
function personaDemo(string $rol, ?int $institucion = null): User
{
    $db = dueno();
    $id = $db->table('users')->insertGetId([
        'nombres' => ucfirst($rol), 'apellidos' => 'Prueba '.uniqid(), 'numero_documento' => (string) random_int(10000000, 99999999),
        'email' => $rol.'.'.uniqid().'@gestion.prueba', 'password' => Hash::make(DemoSeeder::DEMO_PASSWORD), 'celular' => '987654321',
    ]);
    $db->table('membresias')->insert(['institucion_id' => $institucion ?? demoId(), 'user_id' => $id, 'rol_id' => $db->table('roles')->where('codigo', $rol)->value('id')]);

    return User::query()->findOrFail($id);
}

/** Petición como $usuario (segundo factor verificado) en el colegio demo. */
function enDemo(User $usuario, string $metodo, string $url, array $datos = [], ?int $institucion = null)
{
    actuarComo($usuario, true, $institucion ?? demoId());

    return test()->json($metodo, '/api/v1'.$url, $datos, ['X-Institucion' => (string) ($institucion ?? demoId())]);
}

/** PNG de 64×64 (las pruebas no dependen de la extensión GD). */
const LOGO_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAT0lEQVR42u3PQQkAAAgEsCtiJfMbxwi+hcEKLNXzWgQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQELgsKmTEtqQeZBAAAAABJRU5ErkJggg==';
