<?php

use Anthropic\Client;
use App\Modulos\SIT\Services\AsistenteSeo;
use App\Modulos\SIT\Services\Seo\GeneradorSeo;
use App\Modulos\SIT\Services\Seo\GeneradorSeoClaude;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| Sprint 23 · Panel CMS: previsualización del blog de WordPress (EDUN-367/368/370 · SIT-023) y
| asistente SEO con IA (EDUN-411/412/414 · SIT-030). Usan dueno(), superadmin() y personaDemo().
*/

function blogS23(): void
{
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'blog_wp_url'], ['valor' => json_encode('https://wp.test')]);
    Cache::forget('edunova:integraciones');
    Http::fake([
        'wp.test/wp-json/wp/v2/categories*' => Http::response([['id' => 7, 'name' => 'Gestión escolar', 'slug' => 'gestion-escolar']]),
        'wp.test/wp-json/wp/v2/posts*' => Http::response([
            ['id' => 31, 'slug' => 'matricula-sin-colas', 'title' => ['rendered' => 'Matrícula sin colas'], 'excerpt' => ['rendered' => '<p>Cómo</p>'],
                'content' => ['rendered' => '<p>Texto <script>x()</script></p>'], 'categories' => [7], 'date_gmt' => '2026-11-03T10:00:00', 'modified_gmt' => '2026-11-04T10:00:00', 'link' => 'https://wp.test/matricula-sin-colas'],
            ['id' => 32, 'slug' => 'pensiones-al-dia', 'title' => ['rendered' => 'Pensiones al día'], 'excerpt' => ['rendered' => ''], 'content' => ['rendered' => '<p>Dos</p>'], 'categories' => [7], 'date_gmt' => '2026-11-01T10:00:00'],
        ]),
    ]);
}

function seoS23(string $slug): array
{
    return (array) dueno()->table('cms.seo_paginas')->where('slug', $slug)->first();
}

it('blog en el CMS: sincroniza, lista con estado y fecha, enlaza a wp-admin y previsualiza aunque esté oculto', function () {
    blogS23();
    Sanctum::actingAs(superadmin());
    $r = $this->postJson('/api/v1/cms/blog/sincronizar')->assertOk();
    $posts = collect($r->json('data'))->keyBy('slug');
    expect($posts['matricula-sin-colas'])->toMatchArray(['estado' => 'publicado', 'oculto' => false, 'categoria_nombre' => 'Gestión escolar',
        'editar_url' => 'https://wp.test/wp-admin/post.php?post=31&action=edit'])
        ->and($r->json('wp_admin'))->toBe('https://wp.test/wp-admin/')
        ->and(dueno()->table('cms.auditoria')->where('seccion', 'blog')->where('entidad_id', 'sincronizacion')->exists())->toBeTrue();

    $v = $this->getJson('/api/v1/cms/blog/matricula-sin-colas')->assertOk();
    expect($v->json('data.contenido'))->toContain('Texto')->not->toContain('<script');
    $this->getJson('/api/v1/cms/blog/no-existe')->assertNotFound();
});

it('blog en el CMS: ocultar se publica al instante en el sitio, queda en la auditoría y se puede volver a mostrar', function () {
    blogS23();
    Sanctum::actingAs(superadmin());
    $this->postJson('/api/v1/cms/blog/sincronizar')->assertOk();

    $this->patchJson('/api/v1/cms/blog/pensiones-al-dia', ['oculto' => true])->assertOk()->assertJsonPath('data.oculto', true);
    expect(collect($this->getJson('/api/v1/sitio/blog')->json('data'))->pluck('slug')->all())->not->toContain('pensiones-al-dia');
    $this->getJson('/api/v1/sitio/blog/pensiones-al-dia')->assertNotFound();
    expect(collect($this->getJson('/api/v1/sitio/blog/matricula-sin-colas')->json('relacionados'))->pluck('slug')->all())->not->toContain('pensiones-al-dia');
    // La vista previa del CMS sí lo muestra, y una nueva sincronización no lo vuelve a publicar.
    $this->getJson('/api/v1/cms/blog/pensiones-al-dia')->assertOk()->assertJsonPath('data.oculto', true);
    $this->postJson('/api/v1/cms/blog/sincronizar')->assertOk();
    $this->getJson('/api/v1/sitio/blog/pensiones-al-dia')->assertNotFound();

    $a = dueno()->table('cms.auditoria')->where('seccion', 'blog')->where('entidad_id', 'pensiones-al-dia')->orderByDesc('id')->first();
    expect($a->accion)->toBe('ocultado')->and(json_decode($a->valor_despues, true))->toEqual(['titulo' => 'Pensiones al día', 'oculto' => true])
        ->and(dueno()->table('cms.versiones')->where('seccion', 'blog')->where('entidad_id', 'pensiones-al-dia')->exists())->toBeTrue();

    // Revisión: si WordPress lo deja de listar un rato, el post oculto no se borra ni reaparece visible.
    Http::fake(['wp.test/wp-json/wp/v2/categories*' => Http::response([]), 'wp.test/wp-json/wp/v2/posts*' => Http::response([])]);
    $this->postJson('/api/v1/cms/blog/sincronizar')->assertOk();
    expect(dueno()->table('cms.blog_wp_cache')->where('slug', 'pensiones-al-dia')->value('oculto'))->toBeTrue();
    blogS23();
    $this->postJson('/api/v1/cms/blog/sincronizar')->assertOk();
    $this->getJson('/api/v1/sitio/blog/pensiones-al-dia')->assertNotFound();

    $this->patchJson('/api/v1/cms/blog/pensiones-al-dia', ['oculto' => false])->assertOk();
    $this->getJson('/api/v1/sitio/blog/pensiones-al-dia')->assertOk();
    expect(dueno()->table('cms.auditoria')->where('seccion', 'blog')->where('entidad_id', 'pensiones-al-dia')->orderByDesc('id')->value('accion'))->toBe('publicado');
    $this->patchJson('/api/v1/cms/blog/pensiones-al-dia', [])->assertUnprocessable();
});

it('blog y asistente SEO: sin permiso de CMS la API responde 403 (y 401 sin sesión)', function () {
    $this->getJson('/api/v1/cms/blog')->assertUnauthorized();
    Sanctum::actingAs(personaDemo('director'));
    $this->getJson('/api/v1/cms/blog')->assertForbidden();
    $this->patchJson('/api/v1/cms/blog/matricula-sin-colas', ['oculto' => true])->assertForbidden();
    $this->postJson('/api/v1/cms/blog/sincronizar')->assertForbidden();
    $this->getJson('/api/v1/cms/seo-asistente')->assertForbidden();
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'claude'])->assertForbidden();
    $this->postJson('/api/v1/cms/seo/inicio/sugerencias')->assertForbidden();
    $this->postJson('/api/v1/cms/seo/inicio/analisis', ['meta_titulo' => 'x', 'meta_descripcion' => 'y'])->assertForbidden();
});

it('asistente SEO simulado: 3 meta descripciones con largo y legibilidad, keywords, y queda registrado', function () {
    Sanctum::actingAs(superadmin());
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'simulado'])->assertOk()->assertJsonPath('data.proveedor', 'simulado');
    $antes = dueno()->table('cms.seo_sugerencias')->count();
    $r = $this->postJson('/api/v1/cms/seo/inicio/sugerencias', ['contexto' => 'Software escolar con matrícula en línea y pagos de pensiones.'])->assertOk();
    $d = $r->json('data.descripciones');
    expect($d)->toHaveCount(3)->and(collect($d)->every(fn ($x) => mb_strlen($x['texto']) <= 155 && $x['largo'] === mb_strlen($x['texto']) && is_int($x['legibilidad'])))->toBeTrue()
        ->and($r->json('data.keywords'))->not->toBeEmpty()->and(mb_strlen($r->json('data.titulo')))->toBeLessThanOrEqual(60)
        ->and(dueno()->table('cms.seo_sugerencias')->count())->toBe($antes + 1);
    $this->postJson('/api/v1/cms/seo/no-existe/sugerencias')->assertNotFound();
    $this->postJson('/api/v1/cms/seo/inicio/sugerencias', ['contexto' => str_repeat('x', 2001)])->assertUnprocessable();
});

it('asistente SEO: detecta títulos o descripciones repetidos en otras páginas y mide la legibilidad', function () {
    Sanctum::actingAs(superadmin());
    $otra = 'prueba-s23-'.substr(uniqid(), -6);
    dueno()->table('cms.seo_paginas')->insert(['slug' => $otra, 'meta_titulo' => 'Planes para colegios privados | Edunova', 'meta_descripcion' => 'Conoce los planes de Edunova para colegios privados del Perú, con matrícula y pagos en línea.']);
    $o = seoS23($otra);
    $r = $this->postJson('/api/v1/cms/seo/inicio/analisis', ['meta_titulo' => $o['meta_titulo'], 'meta_descripcion' => mb_strtoupper($o['meta_descripcion'])])->assertOk();
    expect($r->json('data.titulo.duplicado_en'))->toContain($otra)->and($r->json('data.descripcion.duplicado_en'))->toContain($otra);
    $u = $this->postJson('/api/v1/cms/seo/inicio/analisis', ['meta_titulo' => 'Título único de prueba '.uniqid(), 'meta_descripcion' => ''])->assertOk();
    expect($u->json('data.titulo.duplicado_en'))->toBe([])->and($u->json('data.descripcion.estado'))->toBe('vacio');

    expect(AsistenteSeo::legibilidad('El colegio abre la matrícula. Paga en línea.'))->toBeGreaterThan(AsistenteSeo::legibilidad('La implementación institucional de herramientas administrativas interoperables optimiza significativamente procedimientos organizacionales.'));
});

it('asistente SEO: elegir una sugerencia y guardarla publica en el sitio y queda en la auditoría', function () {
    Sanctum::actingAs(superadmin());
    $original = seoS23('inicio');
    try {
        $s = $this->postJson('/api/v1/cms/seo/inicio/sugerencias')->assertOk()->json('data');
        $elegida = $s['descripciones'][1]['texto'];
        $pagina = $this->getJson('/api/v1/cms/seo/inicio')->json('data');
        $this->putJson('/api/v1/cms/seo/inicio', [...array_intersect_key($pagina, array_flip(['meta_titulo', 'canonical_url', 'robots', 'og_titulo', 'og_descripcion', 'og_imagen_id'])),
            'meta_descripcion' => $elegida, 'keywords' => array_slice($s['keywords'], 0, 5)])->assertOk();
        expect($this->getJson('/api/v1/sitio/landing')->json('seo.descripcion'))->toBe($elegida)
            ->and(dueno()->table('cms.auditoria')->where('seccion', 'seo')->where('entidad_id', 'inicio')->orderByDesc('id')->value('valor_despues'))->toContain(mb_substr($elegida, 0, 20));
    } finally {
        dueno()->table('cms.seo_paginas')->where('slug', 'inicio')->update(array_intersect_key($original, array_flip(['meta_titulo', 'meta_descripcion', 'keywords'])));
    }
});

it('asistente SEO con Claude: sin clave o sin respuesta avisa claro y deja el error registrado; con respuesta usa el modelo configurado', function () {
    Sanctum::actingAs(superadmin());
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'claude', 'modelo' => 'claude-opus-5-5'])->assertOk()
        ->assertJsonPath('data.proveedor', 'claude')->assertJsonMissingPath('data.clave');
    expect(dueno()->table('cms.auditoria')->where('seccion', 'asistente_seo')->orderByDesc('id')->value('detalle'))->toContain('Claude');

    config(['services.anthropic.clave' => null]);
    $this->postJson('/api/v1/cms/seo/inicio/sugerencias')->assertUnprocessable()->assertJsonPath('errors.proveedor.0', fn ($m) => str_contains($m, 'clave'));
    expect(dueno()->table('cms.seo_sugerencias')->orderByDesc('id')->value('error'))->toContain('clave');

    // Servidor de la IA inalcanzable: mensaje claro, sin detalles internos.
    app()->instance(GeneradorSeoClaude::class, new GeneradorSeoClaude(new Client(apiKey: 'sk-prueba', baseUrl: 'http://127.0.0.1:9', requestOptions: ['maxRetries' => 0, 'timeout' => 2])));
    $this->postJson('/api/v1/cms/seo/inicio/sugerencias')->assertUnprocessable()->assertJsonPath('errors.proveedor.0', 'La IA no respondió. Intenta otra vez en unos minutos.');

    $falso = new class implements GeneradorSeo
    {
        public ?string $modelo = null;

        public function sugerir(array $pagina, string $modelo): array
        {
            $this->modelo = $modelo;

            return ['descripciones' => ['Uno uno uno.', 'Dos dos.', 'Tres.'], 'titulo' => 'Software escolar | Edunova', 'keywords' => ['software escolar'], 'modelo' => $modelo, 'tokens_entrada' => 120, 'tokens_salida' => 80];
        }
    };
    app()->instance(GeneradorSeoClaude::class, $falso);
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'claude', 'modelo' => 'claude-haiku-5-5'])->assertOk();
    $this->postJson('/api/v1/cms/seo/inicio/sugerencias')->assertOk()->assertJsonPath('data.proveedor', 'claude')->assertJsonPath('data.titulo', 'Software escolar | Edunova');
    expect($falso->modelo)->toBe('claude-haiku-5-5')
        ->and((array) dueno()->table('cms.seo_sugerencias')->orderByDesc('id')->first(['proveedor', 'modelo', 'tokens_entrada']))->toBe(['proveedor' => 'claude', 'modelo' => 'claude-haiku-5-5', 'tokens_entrada' => 120]);
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'otro'])->assertUnprocessable();
    $this->putJson('/api/v1/cms/seo-asistente', ['proveedor' => 'simulado'])->assertOk();
});
