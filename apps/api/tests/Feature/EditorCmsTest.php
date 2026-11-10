<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
| EDUN-233 / SIT-044 · Guardar borrador y publicar cambios.
*/

function tokenSuperadmin(): string
{
    static $t = null;

    return $t ??= test()->postJson('/api/v1/auth/ingresar', ['usuario' => 'superadmin@demo.edunova.pe', 'password' => DemoSeeder::DEMO_PASSWORD])->json('token');
}

function heroValido(array $cambios = []): array
{
    return $cambios + [
        'insignia' => 'Gestión escolar K-12',
        'titulo' => 'Tu colegio en un solo lugar',
        'subtitulo' => 'Matrículas, notas y pagos integrados.',
        'cta_principal' => 'Solicitar demo',
    ];
}

function publicado(string $clave): array
{
    return json_decode(DB::connection('propietario')->table('cms.secciones')->where('clave', $clave)->value('config'), true);
}

beforeEach(function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['services.sitio.revalidar_url' => 'http://sitio.test/api/revalidar', 'services.sitio.revalidar_secreto' => 's3cr3t']);
    // Cada prueba parte sin borradores.
    DB::connection('propietario')->table('cms.secciones')->update(['borrador' => null]);
});

it('guardar borrador no cambia lo que ve la landing', function () {
    $antes = publicado('hero');

    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido(['titulo' => 'Titular en borrador'])])
        ->assertOk()->assertJsonPath('data.borrador.titulo', 'Titular en borrador')->assertJsonPath('message', 'Borrador guardado.');

    expect(publicado('hero'))->toBe($antes);
    $landing = $this->getJson('/api/v1/sitio/landing')->assertOk();
    expect($landing->json('secciones.hero'))->toBe($antes)
        ->and(json_encode($landing->json()))->not->toContain('Titular en borrador');
});

it('la franja muestra cambios sin publicar mientras haya borrador', function () {
    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido()])->assertOk();

    $this->withToken(tokenSuperadmin())->getJson('/api/v1/cms/estado')->assertOk()->assertJsonPath('pendientes', ['hero']);
});

it('publicar copia el borrador, crea versión, audita «publicado» y revalida el sitio', function () {
    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido(['titulo' => 'Titular nuevo'])])->assertOk();

    $r = $this->withToken(tokenSuperadmin())->postJson('/api/v1/cms/publicar', ['claves' => ['hero']])->assertOk();

    expect($r->json('publicadas'))->toBe(['hero'])
        ->and($r->json('pendientes'))->toBe([])
        ->and($r->json('ultima_publicacion.por'))->toBe('Ana Administradora');
    expect($this->getJson('/api/v1/sitio/landing')->json('secciones.hero.titulo'))->toBe('Titular nuevo');

    $db = DB::connection('propietario');
    expect($db->table('cms.secciones')->where('clave', 'hero')->value('borrador'))->toBeNull()
        ->and($db->table('cms.auditoria')->where('seccion', 'hero')->where('accion', 'publicado')->latest('id')->value('detalle'))->toBe('Hero y Portada · publicado')
        ->and($db->table('cms.auditoria')->where('seccion', 'hero')->where('accion', 'borrador')->exists())->toBeTrue()
        ->and(json_decode($db->table('cms.versiones')->where('seccion', 'hero')->latest('id')->value('contenido'), true)['titulo'])->toBe('Titular nuevo');

    Http::assertSent(fn ($req) => $req->url() === 'http://sitio.test/api/revalidar' && $req->header('X-Edunova-Revalidar')[0] === 's3cr3t' && $req['etiquetas'] === ['landing', 'politicas']);
});

it('publicar es atómico: si una sección no existe, no se publica ninguna', function () {
    $antes = publicado('hero');
    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido(['titulo' => 'No debe salir'])])->assertOk();

    $this->withToken(tokenSuperadmin())->postJson('/api/v1/cms/publicar', ['claves' => ['hero', 'no-existe']])
        ->assertUnprocessable()->assertJsonPath('errors.claves.0', 'La sección «no-existe» no existe.');

    expect(publicado('hero'))->toBe($antes);
    Http::assertNothingSent();
});

it('se conservan solo 5 versiones por sección', function () {
    foreach (range(1, 7) as $i) {
        $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido(['titulo' => "Versión {$i}"])])->assertOk();
        $this->withToken(tokenSuperadmin())->postJson('/api/v1/cms/publicar', ['claves' => ['hero']])->assertOk();
    }

    $id = (string) DB::connection('propietario')->table('cms.secciones')->where('clave', 'hero')->value('id');
    expect(DB::connection('propietario')->table('cms.versiones')->where('entidad', 'secciones')->where('entidad_id', $id)->count())->toBe(5);
});

it('valida los campos con mensajes en español (insignia de máximo 45)', function () {
    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido(['insignia' => str_repeat('x', 46), 'titulo' => ''])])
        ->assertUnprocessable()
        ->assertJsonPath('errors.insignia.0', 'Máximo 45 caracteres.')
        ->assertJsonPath('errors.titulo.0', 'Este campo es obligatorio.');
});

it('una sección sin reglas no se edita y un usuario de colegio no entra', function () {
    $this->withToken(tokenSuperadmin())->putJson('/api/v1/cms/secciones/footer', ['contenido' => []])->assertNotFound();

    $director = $this->postJson('/api/v1/auth/ingresar', ['usuario' => 'director@demo.edunova.pe', 'password' => DemoSeeder::DEMO_PASSWORD])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($director)->putJson('/api/v1/cms/secciones/hero', ['contenido' => heroValido()])->assertForbidden();
});

it('la landing solo expone la configuración pública', function () {
    $config = $this->getJson('/api/v1/sitio/landing')->assertOk()->json('config');

    expect($config)->toHaveKey('telefono_central')
        ->and($config)->not->toHaveKey('crm_endpoint')
        ->and($config)->not->toHaveKey('blog_wp_url');
});
