<?php

use App\Models\User;
use App\Modulos\Compartido\Monitoreo\LimpiarDatosSensibles;
use App\Modulos\Compartido\Monitoreo\Monitoreo;
use App\Modulos\SIT\Services\BlogWordpress;
use App\Modulos\SIT\Services\EnvioCrm;
use App\Modulos\SIT\Services\Integraciones;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Sprint 11 · Monitoreo (EDUN-206), tracking e integraciones (EDUN-403), blog desde WordPress
| (EDUN-363), chatbot (EDUN-375), preguntas frecuentes (EDUN-371), detalle de módulos (EDUN-347)
| y demo larga (EDUN-359). Usan dueno(), visitante() y superadmin() de tests/Ayudas.php y
| guardarTablasPanel()/restaurarTablasPanel() de PanelCmsTest.php.
*/

const TABLAS_CANALES = ['cms.config', 'cms.tracking', 'cms.faq', 'cms.chatbot_flujos', 'cms.chatbot_nodos', 'cms.modulos_web', 'cms.secciones', 'cms.blog_wp_cache', 'cms.categorias_blog'];

function copiaCanales(): array
{
    return collect(TABLAS_CANALES)->mapWithKeys(fn ($t) => [$t => dueno()->table($t)->get()->map(fn ($f) => (array) $f)->all()])->all();
}

function restaurarCanales(array $copia): void
{
    dueno()->transaction(function () use ($copia) {
        // Hijos antes que padres al borrar.
        foreach (['cms.chatbot_nodos', 'cms.blog_wp_cache'] as $t) {
            dueno()->table($t)->delete();
        }
        foreach ($copia as $tabla => $filas) {
            $clave = $tabla === 'cms.config' ? 'clave' : 'id';
            dueno()->table($tabla)->whereNotIn($clave, array_column($filas, $clave) ?: [$clave === 'id' ? -1 : '__ninguno__'])->delete();
            foreach ($filas as $f) {
                dueno()->table($tabla)->updateOrInsert([$clave => $f[$clave]], $f);
            }
        }
    });
}

function publicarSeccion(string $clave, array $contenido): void
{
    test()->putJson("/api/v1/cms/secciones/{$clave}", ['contenido' => $contenido])->assertOk();
    test()->postJson('/api/v1/cms/publicar', ['claves' => [$clave]])->assertOk();
}

function integracionesBase(array $cambios = []): array
{
    $tracking = collect(Integraciones::TRACKING)->map(fn () => ['valor' => null, 'activo' => false])->all();

    return array_replace_recursive([
        'tracking' => $tracking,
        'crm' => ['url' => null, 'token' => null],
        'smtp' => ['host' => null, 'puerto' => 587, 'usuario' => null, 'cifrado' => 'tls', 'remitente' => null, 'clave' => null],
        'ia' => ['proveedor' => null, 'clave' => null],
        'wordpress' => ['url' => null],
    ], $cambios);
}

beforeEach(function () {
    Queue::fake();
    Cache::forget('edunova:integraciones');
    $this->copiaCanales = copiaCanales();
});

afterEach(function () {
    restaurarCanales($this->copiaCanales);
    Cache::forget('edunova:integraciones');
    Cache::forget('edunova:blog:sincronizando');
    dueno()->table('cms.leads')->where('email', 'like', '%@s11.prueba')->delete();
});

// --- EDUN-206 · Monitoreo --------------------------------------------------------------------

it('los registros ocultan contraseñas, tokens y documentos', function () {
    $limpio = LimpiarDatosSensibles::limpiar(['password' => 'x', 'usuario' => ['token' => 'abc', 'numero_doc' => '45678912'], 'nota' => 'Bearer 12|abcdefghijklmnopqrstuvwxyz0123456789ABCD dni 45678912']);
    expect($limpio['password'])->toBe('[oculto]')
        ->and($limpio['usuario']['token'])->toBe('[oculto]')
        ->and($limpio['usuario']['numero_doc'])->toBe('[oculto]')
        ->and($limpio['nota'])->not->toContain('abcdefghij')->not->toContain('45678912');
});

it('el monitoreo avisa al webhook una sola vez cuando hay errores 5xx', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['services.alertas.webhook' => 'https://alertas.test/hook']);
    Cache::flush();
    foreach (range(1, Monitoreo::UMBRAL_5XX) as $i) {
        Monitoreo::contarError5xx();
    }
    $problemas = app(Monitoreo::class)->revisar();
    expect(implode(' ', $problemas))->toContain('5xx');
    Http::assertSent(fn ($r) => $r->url() === 'https://alertas.test/hook' && str_contains($r['text'], '5xx'));

    Http::fake(['*' => Http::response(['ok' => true])]);
    app(Monitoreo::class)->revisar();
    Http::assertNothingSent();
});

// --- EDUN-403 · Tracking e integraciones --------------------------------------------------

it('integraciones: valida los IDs, cifra las claves, no las devuelve ni las audita y las borra con su marca', function () {
    Sanctum::actingAs(superadmin());
    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['tracking' => ['ga4' => ['valor' => 'UA-12345', 'activo' => true]]]))
        ->assertUnprocessable()->assertJsonValidationErrors('tracking.ga4.valor');

    $r = $this->putJson('/api/v1/cms/integraciones', integracionesBase([
        'tracking' => ['ga4' => ['valor' => 'G-ABC123XYZ', 'activo' => true], 'gtm' => ['valor' => 'GTM-XXXXXXX', 'activo' => true]],
        'crm' => ['url' => 'https://crm.test/leads', 'token' => 'clave-secreta-del-crm'],
    ]))->assertOk();
    expect($r->json('data.crm.token'))->toBe(['configurado' => true])
        ->and(json_encode($r->json()))->not->toContain('clave-secreta-del-crm')
        ->and(dueno()->table('cms.config')->where('clave', 'integraciones')->value('valor'))->not->toContain('clave-secreta-del-crm')
        ->and(json_encode(dueno()->table('cms.auditoria')->where('seccion', 'integraciones')->get()))->not->toContain('clave-secreta-del-crm');

    // El sitio recibe GA4 válido y activo; el GTM de ejemplo nunca se publica.
    $tracking = $this->getJson('/api/v1/sitio/landing')->json('tracking');
    expect($tracking['ga4'])->toBe('G-ABC123XYZ')->and($tracking['gtm'])->toBeNull();

    // El CRM usa lo del panel.
    Cache::forget('edunova:integraciones');
    expect(Integraciones::crm())->toBe(['url' => 'https://crm.test/leads', 'token' => 'clave-secreta-del-crm'])
        ->and(app(EnvioCrm::class)->configurado())->toBeTrue();

    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['crm' => ['url' => null, 'token' => null, 'token_borrar' => true]]))
        ->assertOk()->assertJsonPath('data.crm.token.configurado', false);
});

it('integraciones: reCAPTCHA, alertas, correo, IA y demo se configuran desde el panel y ganan al .env', function () {
    Sanctum::actingAs(superadmin());
    config(['services.recaptcha.secreto' => null, 'services.alertas.webhook' => null, 'services.correo.webhook_secreto' => null, 'services.anthropic.clave' => null, 'edunova.demo.activo' => false]);
    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['recaptcha' => ['sitio' => 'corta', 'umbral' => 2]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['recaptcha.sitio', 'recaptcha.umbral']);
    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['demo' => ['activo' => true, 'colegio' => 'no-existe']]))
        ->assertUnprocessable()->assertJsonValidationErrors('demo.colegio');

    $r = $this->putJson('/api/v1/cms/integraciones', integracionesBase([
        'ia' => ['proveedor' => 'anthropic', 'clave' => 'sk-ant-clave-del-panel'],
        'recaptcha' => ['sitio' => str_repeat('A', 40), 'secreto' => 'secreto-recaptcha-panel', 'umbral' => 0.7],
        'alertas' => ['webhook' => 'https://alertas.test/panel'],
        'correo' => ['webhook_secreto' => str_repeat('s', 32)],
        'demo' => ['activo' => true, 'colegio' => 'smp'],
    ]))->assertOk();
    expect($r->json('data.recaptcha.secreto.configurado'))->toBeTrue()->and($r->json('data.alertas.webhook.configurado'))->toBeTrue()
        ->and($r->json('data.demo.activo'))->toBeTrue()->and($r->json('data.conectores'))->toHaveKeys(['pasarela_simulada', 'facturacion_simulada', 'whatsapp_simulado'])
        ->and(json_encode($r->json()))->not->toContain('secreto-recaptcha-panel')->not->toContain('alertas.test/panel')->not->toContain('sk-ant-clave');

    Cache::forget('edunova:integraciones');
    expect(Integraciones::recaptcha())->toBe(['sitio' => str_repeat('A', 40), 'secreto' => 'secreto-recaptcha-panel', 'umbral' => 0.7])
        ->and(Integraciones::webhookAlertas())->toBe('https://alertas.test/panel')
        ->and(Integraciones::secretoCorreo())->toBe(str_repeat('s', 32))
        ->and(Integraciones::claveIa())->toBe('sk-ant-clave-del-panel');
    $this->getJson('/api/v1/sitio/landing')->assertJsonPath('recaptcha_sitio', str_repeat('A', 40));
    $this->getJson('/api/v1/auth/demo')->assertJsonPath('activo', true);

    // Guardar sin las partes nuevas no las borra (clientes anteriores del panel).
    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['ia' => ['proveedor' => 'anthropic']]))->assertOk()
        ->assertJsonPath('data.alertas.webhook.configurado', true)->assertJsonPath('data.ia.clave.configurado', true);

    // Apagar la demo desde el panel la apaga aunque el .env la tenga encendida.
    config(['edunova.demo.activo' => true]);
    $this->putJson('/api/v1/cms/integraciones', integracionesBase(['demo' => ['activo' => false, 'colegio' => 'smp']]))->assertOk();
    Cache::forget('edunova:integraciones');
    $this->getJson('/api/v1/auth/demo')->assertJsonPath('activo', false);
});

it('integraciones: «probar conexión» de WordPress, CRM, alertas y SMTP con lo guardado', function () {
    Sanctum::actingAs(superadmin());
    Http::fake([
        'blog.prueba/wp-json/*' => Http::response([['id' => 1]], 200, ['X-WP-Total' => '7']),
        'crm.test/*' => Http::response(['ok' => true]),
        'alertas.test/*' => Http::response('', 500),
    ]);
    $this->postJson('/api/v1/cms/integraciones/probar/wordpress')->assertOk()->assertJsonPath('ok', false);
    $this->postJson('/api/v1/cms/integraciones/probar/desconocido')->assertNotFound();

    $this->putJson('/api/v1/cms/integraciones', integracionesBase([
        'wordpress' => ['url' => 'https://blog.prueba'], 'crm' => ['url' => 'https://crm.test/leads', 'token' => 'tok-crm'],
        'alertas' => ['webhook' => 'https://alertas.test/hook'],
    ]))->assertOk();
    $this->postJson('/api/v1/cms/integraciones/probar/wordpress')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('mensaje', 'Conectado con WordPress: 7 artículos publicados.');
    $this->postJson('/api/v1/cms/integraciones/probar/crm')->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn ($r) => $r->url() === 'https://crm.test/leads' && $r->hasHeader('Authorization', 'Bearer tok-crm') && $r['prueba'] === true);
    $this->postJson('/api/v1/cms/integraciones/probar/alertas')->assertOk()->assertJsonPath('ok', false);
    $this->postJson('/api/v1/cms/integraciones/probar/smtp')->assertOk()->assertJsonPath('ok', false);
});

// --- EDUN-363 · Blog desde WordPress --------------------------------------------------------

it('blog: trae los artículos de WordPress, limpia el HTML y muestra el artículo con relacionados', function () {
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'blog_wp_url'], ['valor' => json_encode('https://wp.test')]);
    Cache::forget('edunova:integraciones');
    Http::fake([
        'wp.test/wp-json/wp/v2/categories*' => Http::response([['id' => 5, 'name' => 'Gestión', 'slug' => 'gestion']]),
        'wp.test/wp-json/wp/v2/posts*' => Http::response([
            ['id' => 1, 'slug' => 'primer-articulo', 'title' => ['rendered' => 'Primer &amp; artículo'], 'excerpt' => ['rendered' => '<p>Resumen</p>'],
                'content' => ['rendered' => '<p>Hola <script>alert(1)</script><a href="javascript:x()">mal</a> <img src="https://wp.test/a.png" onerror="x()"> <img src="http://inseguro/a.png"></p>'],
                'categories' => [5], 'date_gmt' => '2026-11-01T10:00:00', 'modified_gmt' => '2026-11-02T10:00:00', 'link' => 'https://wp.test/primer-articulo'],
            ['id' => 2, 'slug' => 'segundo', 'title' => ['rendered' => 'Segundo'], 'excerpt' => ['rendered' => ''], 'content' => ['rendered' => '<p>Dos</p>'], 'categories' => [5], 'date_gmt' => '2026-10-01T10:00:00'],
        ]),
    ]);

    $l = $this->getJson('/api/v1/sitio/blog?categoria=gestion')->assertOk();
    expect($l->json('configurado'))->toBeTrue()->and(collect($l->json('data'))->pluck('slug')->all())->toBe(['primer-articulo', 'segundo'])
        ->and($l->json('categorias.0.nombre'))->toBe('Gestión');

    $a = $this->getJson('/api/v1/sitio/blog/primer-articulo')->assertOk();
    expect($a->json('titulo'))->toBe('Primer & artículo')
        ->and($a->json('contenido'))->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror')->not->toContain('http://inseguro')
        ->toContain('<img src="https://wp.test/a.png"')
        ->and(collect($a->json('relacionados'))->pluck('slug')->all())->toBe(['segundo']);
    $this->getJson('/api/v1/sitio/blog/no-existe')->assertNotFound();
});

it('blog: si WordPress no responde se sirve lo guardado; sin URL, «configurado» es falso', function () {
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'blog_wp_url'], ['valor' => json_encode(null)]);
    Cache::forget('edunova:integraciones');
    expect($this->getJson('/api/v1/sitio/blog')->json('configurado'))->toBeFalse();

    dueno()->table('cms.config')->updateOrInsert(['clave' => 'blog_wp_url'], ['valor' => json_encode('https://wp.test')]);
    Cache::forget('edunova:integraciones');
    Http::fake(['wp.test/*' => Http::response('caído', 503)]);
    app(BlogWordpress::class)->sincronizar(true);
    $this->getJson('/api/v1/sitio/blog')->assertOk()->assertJsonPath('configurado', true);
});

// --- EDUN-375 · Chatbot ---------------------------------------------------------------------

it('chatbot: valida el flujo, publica los pasos con sus destinos y registra el contacto con la conversación', function () {
    Sanctum::actingAs(superadmin());
    $base = $this->getJson('/api/v1/cms/secciones/_chatbot')->json('data.publicado');
    $this->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => [...$base, 'activo' => true, 'modo' => 'flujo', 'nodos' => [
        ['tipo' => 'opciones', 'texto' => '¿Qué necesitas?', 'url' => null, 'siguiente' => null, 'opciones' => [['texto' => 'Precios', 'destino' => 5]]],
    ]]])->assertUnprocessable()->assertJsonValidationErrors('nodos.0.opciones.0.destino');

    publicarSeccion('_chatbot', [...$base, 'activo' => true, 'modo' => 'flujo', 'nodos' => [
        ['tipo' => 'opciones', 'texto' => '¿Qué necesitas?', 'url' => null, 'siguiente' => null, 'opciones' => [['texto' => 'Precios', 'destino' => 1], ['texto' => 'Asesor', 'destino' => 2]]],
        ['tipo' => 'url', 'texto' => 'Mira los planes', 'url' => '/planes', 'siguiente' => null, 'opciones' => []],
        ['tipo' => 'captura', 'texto' => 'Déjanos tu contacto', 'url' => null, 'siguiente' => null, 'opciones' => []],
    ]]);
    $c = $this->getJson('/api/v1/sitio/landing')->json('chatbot');
    expect($c['modo'])->toBe('flujo')->and(collect($c['nodos'])->pluck('tipo')->all())->toBe(['opciones', 'url', 'captura'])
        ->and($c['nodos'][0]['opciones'])->toBe([['texto' => 'Precios', 'destino' => 1], ['texto' => 'Asesor', 'destino' => 2]]);

    $this->postJson('/api/v1/sitio/chatbot/lead', ['consentimiento' => true, 'recorrido' => []], visitante())->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/sitio/chatbot/lead', ['email' => 'chat@s11.prueba', 'consentimiento' => true, 'recorrido' => ['Tú: Asesor']], visitante())->assertCreated();
    $lead = dueno()->table('cms.leads')->where('email', 'chat@s11.prueba')->first();
    expect($lead->source)->toBe('chatbot')
        ->and(dueno()->table('cms.chatbot_sesiones')->where('lead_id', $lead->id)->where('lead_capturado', true)->exists())->toBeTrue();
});

it('chatbot: el modo WhatsApp exige el número en Configuración general; la base admite un solo flujo activo', function () {
    Sanctum::actingAs(superadmin());
    dueno()->table('cms.config')->updateOrInsert(['clave' => 'whatsapp'], ['valor' => json_encode(null)]);
    $base = $this->getJson('/api/v1/cms/secciones/_chatbot')->json('data.publicado');
    $this->putJson('/api/v1/cms/secciones/_chatbot', ['contenido' => [...$base, 'activo' => true, 'modo' => 'whatsapp', 'nodos' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('modo');

    dueno()->table('cms.chatbot_flujos')->update(['activo' => false]);
    dueno()->table('cms.chatbot_flujos')->insert(['nombre' => 'A', 'activo' => true]);
    expect(fn () => dueno()->table('cms.chatbot_flujos')->insert(['nombre' => 'B', 'activo' => true]))->toThrow(QueryException::class);
});

// --- EDUN-371 · Preguntas frecuentes --------------------------------------------------------

it('preguntas frecuentes: solo las visibles llegan al sitio, con su categoría', function () {
    Sanctum::actingAs(superadmin());
    publicarSeccion('_faq', ['lista' => [
        ['id' => null, 'pregunta' => '¿Exporta al SIAGIE?', 'respuesta' => 'Sí, genera los archivos.', 'categoria' => 'SIAGIE', 'visible' => true],
        ['id' => null, 'pregunta' => 'Oculta', 'respuesta' => 'No sale.', 'categoria' => null, 'visible' => false],
    ]]);
    expect($this->getJson('/api/v1/sitio/landing')->json('faq'))->toBe([['pregunta' => '¿Exporta al SIAGIE?', 'respuesta' => 'Sí, genera los archivos.', 'categoria' => 'SIAGIE']]);
});

// --- EDUN-347 / EDUN-359 · Detalle de módulos y demo larga -----------------------------------

it('módulos: la descripción ampliada y los beneficios llegan a /funcionalidades (sin líneas vacías)', function () {
    Sanctum::actingAs(superadmin());
    $m = $this->getJson('/api/v1/cms/secciones/modulos')->json('data.publicado');
    $m['lista'][0]['descripcion_larga'] = 'Matrícula en línea con documentos y pagos.';
    $m['lista'][0]['beneficios'] = ['Menos colas', null, 'Todo en un solo lugar'];
    publicarSeccion('modulos', $m);
    $primero = $this->getJson('/api/v1/sitio/landing')->json('modulos.0');
    expect($primero['descripcion_larga'])->toBe('Matrícula en línea con documentos y pagos.')->and($primero['beneficios'])->toBe(['Menos colas', 'Todo en un solo lugar']);
});

it('demo larga: guarda sistema actual y urgencia, y rechaza una urgencia desconocida', function () {
    $d = now('America/Lima')->addDays(3);
    while ($d->isWeekend()) {
        $d = $d->addDay();
    }
    $demo = ['source' => 'demo', 'nombre' => 'Rosa Prueba', 'cargo' => 'Director(a)', 'telefono' => '987654321', 'email' => 'largo@s11.prueba', 'colegio' => 'Colegio', 'alumnos' => 300,
        'demo_fecha' => $d->toDateString(), 'demo_hora' => '10:30', 'consentimiento' => true, 'origen' => 'demo_largo', 'sistema_actual' => 'Excel', 'urgencia' => 'este_mes'];
    $this->postJson('/api/v1/sitio/leads', [...$demo, 'urgencia' => 'ayer'], visitante())->assertUnprocessable()->assertJsonValidationErrors('urgencia');
    $this->postJson('/api/v1/sitio/leads', $demo, visitante())->assertCreated();
    $lead = dueno()->table('cms.leads')->where('email', 'largo@s11.prueba')->first();
    expect($lead->sistema_actual)->toBe('Excel')->and($lead->urgencia)->toBe('este_mes');
});

it('las pantallas nuevas del panel responden 403 a un usuario de colegio', function () {
    $director = User::whereNotIn('id', dueno()->table('roles_plataforma')->pluck('user_id'))->firstOrFail();
    Sanctum::actingAs($director);
    $this->getJson('/api/v1/cms/integraciones')->assertForbidden();
    $this->putJson('/api/v1/cms/integraciones', [])->assertForbidden();
    $this->getJson('/api/v1/cms/secciones/_chatbot')->assertForbidden();
    $this->getJson('/api/v1/cms/secciones/_faq')->assertForbidden();
});
