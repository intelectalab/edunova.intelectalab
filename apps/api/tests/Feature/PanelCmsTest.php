<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| Sprints 9 y 10 · Pantallas del Panel CMS: planes (EDUN-326), módulos (EDUN-330), testimonios
| (EDUN-334), logos (EDUN-338), auditoría y restauración (EDUN-342), dashboard (EDUN-383),
| editor de secciones (EDUN-387), popup (EDUN-391), políticas (EDUN-395), configuración y
| mantenimiento (EDUN-399), usuarios (EDUN-407) y SEO (EDUN-314).
| Cada prueba deja las tablas como estaban (copia antes, restaura después).
*/

const TABLAS_PANEL = ['cms.secciones', 'cms.planes_sitio', 'cms.precios_complementos', 'cms.modulos_web', 'cms.testimonios', 'cms.clientes', 'cms.popup_config', 'cms.config', 'cms.seo_paginas'];

function guardarTablasPanel(): array
{
    return collect(TABLAS_PANEL)->mapWithKeys(fn ($t) => [$t => dueno()->table($t)->get()->map(fn ($f) => (array) $f)->all()])->all();
}

function restaurarTablasPanel(array $copia): void
{
    dueno()->transaction(function () use ($copia) {
        // Primero lo que referencia a otros (clientes/testimonios → medios) y después el resto.
        foreach ($copia as $tabla => $filas) {
            $clave = $tabla === 'cms.config' ? 'clave' : 'id';
            dueno()->table($tabla)->whereNotIn($clave, array_column($filas, $clave) ?: [$clave === 'id' ? -1 : '__ninguno__'])->delete();
            foreach ($filas as $f) {
                dueno()->table($tabla)->updateOrInsert([$clave => $f[$clave]], $f);
            }
        }
    });
}

function vistaSeccion(string $clave): array
{
    return test()->getJson("/api/v1/cms/secciones/{$clave}")->assertOk()->json('data');
}

function guardarYPublicar(string $clave, array $contenido): void
{
    test()->putJson("/api/v1/cms/secciones/{$clave}", ['contenido' => $contenido])->assertOk();
    test()->postJson('/api/v1/cms/publicar', ['claves' => [$clave]])->assertOk();
}

function medioPrueba(string $tipo = 'imagen'): int
{
    return dueno()->table('cms.medios')->insertGetId([
        'tipo' => $tipo, 'nombre' => "prueba-panel.{$tipo}", 'url' => 'https://medios.test/prueba', 'mime' => $tipo === 'pdf' ? 'application/pdf' : 'image/png',
        'bytes' => 1000, 'ancho' => $tipo === 'pdf' ? null : 1200, 'alto' => $tipo === 'pdf' ? null : 630, 'alt' => 'Prueba',
    ]);
}

beforeEach(function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $this->copiaPanel = guardarTablasPanel();
    Sanctum::actingAs(superadmin());
});

afterEach(function () {
    restaurarTablasPanel($this->copiaPanel);
    dueno()->table('cms.medios')->where('nombre', 'like', 'prueba-panel.%')->delete();
    dueno()->table('cms.politicas')->where('version', 'like', '9.%')->delete();
});

// --- Sprint 9 -------------------------------------------------------------------------------

it('planes: publica precio y orden en cms.planes_sitio y admite un solo recomendado', function () {
    $p = [...vistaSeccion('planes')['publicado'], 'nota_legal' => 'Precios referenciales sin IGV'];
    $p['lista'][0]['precio_mensual'] = '123.45';
    $p['lista'] = array_reverse($p['lista']);
    guardarYPublicar('planes', $p);
    $ultimo = end($p['lista']);
    expect(dueno()->table('cms.planes_sitio')->where('clave', $ultimo['clave'])->value('precio_mensual'))->toBe('123.45')
        ->and(dueno()->table('cms.planes_sitio')->where('clave', $p['lista'][0]['clave'])->value('orden'))->toBe(1)
        ->and(array_keys(json_decode(dueno()->table('cms.secciones')->where('clave', 'planes')->value('config'), true)))->not->toContain('lista');

    $p['lista'][0]['recomendado'] = $p['lista'][1]['recomendado'] = true;
    $this->putJson('/api/v1/cms/secciones/planes', ['contenido' => $p])->assertUnprocessable()->assertJsonValidationErrors('lista');
    $p['lista'][1]['recomendado'] = false;
    $p['lista'][0]['precio_mensual'] = '12,5';
    $this->putJson('/api/v1/cms/secciones/planes', ['contenido' => $p])->assertUnprocessable()->assertJsonValidationErrors('lista.0.precio_mensual');
});

it('módulos: máximo 6 en el grid principal (API y base)', function () {
    $m = vistaSeccion('modulos')['publicado'];
    foreach ($m['lista'] as $i => $x) {
        $m['lista'][$i]['en_grid'] = $i < 7;
        $m['lista'][$i]['visible'] = true;
    }
    $this->putJson('/api/v1/cms/secciones/modulos', ['contenido' => $m])->assertUnprocessable()->assertJsonValidationErrors('lista');
    expect(fn () => dueno()->transaction(fn () => dueno()->table('cms.modulos_web')->update(['en_grid' => true])))->toThrow(Exception::class, 'grid principal');
});

it('testimonios: uno real no se publica sin consentimiento, fecha y PDF', function () {
    $t = vistaSeccion('testimonios')['publicado'];
    $nuevo = ['id' => null, 'nombre' => 'Directora Prueba', 'cargo' => 'Directora', 'institucion' => 'Colegio Prueba', 'avatar_id' => null,
        'texto' => 'Un testimonio real de más de veinte caracteres.', 'calificacion' => 5, 'es_ejemplo' => false,
        'consentimiento_obtenido' => true, 'consentimiento_fecha' => '2026-10-01', 'consentimiento_doc_id' => null, 'visible' => true];
    $this->putJson('/api/v1/cms/secciones/testimonios', ['contenido' => [...$t, 'lista' => [...$t['lista'], $nuevo]]])
        ->assertUnprocessable()->assertJsonPath('errors', fn ($e) => isset($e[sprintf('lista.%d.consentimiento_doc_id', count($t['lista']))]));

    $nuevo['consentimiento_doc_id'] = medioPrueba('pdf');
    guardarYPublicar('testimonios', [...$t, 'lista' => [...$t['lista'], $nuevo]]);
    expect(dueno()->table('cms.testimonios')->where('institucion', 'Colegio Prueba')->where('visible', true)->exists())->toBeTrue();
});

// --- EDUN-338 · Logos -----------------------------------------------------------------------

it('logos: sin logos la sección no se puede mostrar; uno sin autorización se rechaza; uno autorizado aparece en la landing', function () {
    $l = vistaSeccion('logos')['publicado'];
    expect($l['lista'])->toBe([])->and($l['mostrar'])->toBeFalse();
    $this->putJson('/api/v1/cms/secciones/logos', ['contenido' => [...$l, 'mostrar' => true]])->assertUnprocessable()->assertJsonValidationErrors('mostrar');

    $logo = ['id' => null, 'nombre' => 'Colegio Logo', 'logo_id' => medioPrueba(), 'sitio_web' => 'https://colegio.test', 'autorizado' => false, 'visible' => true];
    $this->putJson('/api/v1/cms/secciones/logos', ['contenido' => [...$l, 'lista' => [$logo]]])->assertUnprocessable()->assertJsonValidationErrors('lista.0.visible');
    expect(fn () => dueno()->table('cms.clientes')->insert(['nombre' => 'X', 'logo_id' => $logo['logo_id'], 'visible' => true, 'autorizacion_uso_logo' => false]))->toThrow(QueryException::class);

    guardarYPublicar('logos', [...$l, 'mostrar' => true, 'lista' => [[...$logo, 'autorizado' => true]]]);
    $landing = $this->getJson('/api/v1/sitio/landing')->json();
    expect($landing['secciones'])->toHaveKey('logos')->and(collect($landing['logos'])->pluck('nombre')->all())->toBe(['Colegio Logo']);
});

// --- EDUN-342 · Auditoría y restauración ---------------------------------------------------

it('auditoría: el filtro por sección deja solo esos registros y restaurar un precio lo deja como borrador', function () {
    $p = [...vistaSeccion('planes')['publicado'], 'nota_legal' => 'Precios referenciales sin IGV'];
    $original = $p['lista'][0]['precio_mensual'];
    $p['lista'][0]['precio_mensual'] = '111.00';
    guardarYPublicar('planes', $p);

    $r = $this->getJson('/api/v1/cms/auditoria?seccion=planes')->assertOk();
    expect(collect($r->json('data'))->pluck('seccion')->unique()->all())->toBe(['planes'])->and($r->json('total'))->toBe(count($r->json('data')));

    $publicado = collect($r->json('data'))->firstWhere('accion', 'publicado');
    $this->postJson("/api/v1/cms/auditoria/{$publicado['id']}/restaurar")->assertOk()
        ->assertJsonPath('data.borrador.lista.0.precio_mensual', $original)
        ->assertJsonPath('data.publicado.lista.0.precio_mensual', '111.00');
    expect(dueno()->table('cms.auditoria')->where('seccion', 'planes')->where('accion', 'restaurado')->exists())->toBeTrue();
});

it('auditoría: nadie la edita por la API ni en la base', function () {
    $id = dueno()->table('cms.auditoria')->value('id') ?? dueno()->table('cms.auditoria')->insertGetId(['usuario_rol' => 'superadmin', 'seccion' => 'hero', 'accion' => 'editado', 'entidad' => 'secciones', 'entidad_id' => 'hero', 'detalle' => 'x']);
    expect($this->patchJson("/api/v1/cms/auditoria/{$id}", ['detalle' => 'otro'])->status())->toBeIn([404, 405])
        ->and($this->deleteJson("/api/v1/cms/auditoria/{$id}")->status())->toBeIn([404, 405]);
    expect(fn () => dueno()->table('cms.auditoria')->where('id', $id)->update(['detalle' => 'otro']))->toThrow(QueryException::class);
});

it('auditoría: exporta CSV con los filtros', function () {
    $csv = $this->get('/api/v1/cms/auditoria/exportar?seccion=hero')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith("\u{FEFF}\"Fecha (hora de Lima)\"");
});

// --- EDUN-387 · Editor de secciones --------------------------------------------------------

it('editor de secciones: el orden y la visibilidad publicados llegan a la landing; hero y footer no se ocultan', function () {
    $e = vistaSeccion('_estructura')['publicado'];
    expect(collect($e['lista'])->pluck('clave')->filter(fn ($c) => str_starts_with($c, '_'))->all())->toBe([]);

    $lista = collect($e['lista']);
    $retos = $lista->search(fn ($s) => $s['clave'] === 'retos');
    $nueva = $lista->map(fn ($s) => $s['clave'] === 'retos' ? [...$s, 'visible' => false] : $s)->all();
    [$nueva[$retos], $nueva[$retos + 1]] = [$nueva[$retos + 1], $nueva[$retos]];
    guardarYPublicar('_estructura', ['lista' => $nueva]);
    $claves = array_keys($this->getJson('/api/v1/sitio/landing')->json('secciones'));
    expect($claves)->not->toContain('retos')->not->toContain('_estructura');

    $sinFooter = collect($nueva)->map(fn ($s) => $s['clave'] === 'footer' ? [...$s, 'visible' => false] : $s)->all();
    $this->putJson('/api/v1/cms/secciones/_estructura', ['contenido' => ['lista' => $sinFooter]])->assertUnprocessable();
});

// --- EDUN-391 · Popup ----------------------------------------------------------------------

it('popup: lo publicado llega a la landing', function () {
    $p = vistaSeccion('_popup')['publicado'];
    guardarYPublicar('_popup', [...$p, 'activo' => true, 'titulo' => 'Titular de prueba', 'cta' => 'Quiero info', 'espera_seg' => 12]);
    expect($this->getJson('/api/v1/sitio/landing')->json('popup'))->toMatchArray(['titulo' => 'Titular de prueba', 'cta' => 'Quiero info', 'espera_seg' => 12]);
});

// --- EDUN-399 · Configuración y mantenimiento ----------------------------------------------

it('configuración: valida el RUC, publica los datos legales y activa el mantenimiento', function () {
    $c = vistaSeccion('_config')['publicado'];
    $this->putJson('/api/v1/cms/secciones/_config', ['contenido' => [...$c, 'ruc' => '123']])->assertUnprocessable()->assertJsonValidationErrors('ruc');
    $this->putJson('/api/v1/cms/secciones/_config', ['contenido' => [...$c, 'mantenimiento' => ['activo' => true, 'mensaje' => null, 'vuelta' => null]]])
        ->assertUnprocessable()->assertJsonValidationErrors('mantenimiento.mensaje');

    guardarYPublicar('_config', [...$c, 'ruc' => '20123456789', 'mantenimiento' => ['activo' => true, 'mensaje' => 'Volvemos pronto.', 'vuelta' => '2026-12-01T08:00']]);
    $config = $this->getJson('/api/v1/sitio/landing')->json('config');
    expect($config['ruc'])->toBe('20123456789')->and($config['mantenimiento'])->toMatchArray(['activo' => true, 'mensaje' => 'Volvemos pronto.']);
});

// --- EDUN-314 · SEO -------------------------------------------------------------------------

it('SEO: un título de 75 caracteres no se guarda; noindex, follow y la imagen OG llegan a la landing', function () {
    $s = $this->getJson('/api/v1/cms/seo/inicio')->assertOk()->json('data');
    $cuerpo = collect($s)->only(['meta_titulo', 'meta_descripcion', 'canonical_url', 'robots', 'keywords', 'og_titulo', 'og_descripcion', 'og_imagen_id'])->all();

    $this->putJson('/api/v1/cms/seo/inicio', [...$cuerpo, 'meta_titulo' => str_repeat('x', 75)])->assertUnprocessable()->assertJsonPath('errors.meta_titulo.0', 'El título admite hasta 70 caracteres.');

    $img = medioPrueba();
    $this->putJson('/api/v1/cms/seo/inicio', [...$cuerpo, 'robots' => 'noindex,follow', 'og_imagen_id' => $img, 'keywords' => ['colegios', 'gestión escolar']])->assertOk();
    $seo = $this->getJson('/api/v1/sitio/landing')->json('seo');
    expect($seo['robots'])->toBe('noindex,follow')->and($seo['og_imagen']['url'])->toBe('https://medios.test/prueba')->and($seo['keywords'])->toBe(['colegios', 'gestión escolar'])
        ->and(dueno()->table('cms.auditoria')->where('seccion', 'seo')->where('entidad_id', 'inicio')->exists())->toBeTrue();
});

// --- EDUN-395 · Políticas ------------------------------------------------------------------

it('políticas: publica una versión nueva programada y limpia el HTML; no repite versiones ni acepta fechas pasadas', function () {
    $vigente = collect($this->getJson('/api/v1/cms/politicas')->assertOk()->json('data'))->firstWhere('tipo', 'cookies')['versiones'][0];
    $texto = str_repeat('<p>Texto de la política de cookies con detalle suficiente. </p>', 6);

    $this->postJson('/api/v1/cms/politicas/cookies', ['version' => $vigente['version'], 'vigente_desde' => '2030-01-01', 'contenido' => $texto, 'revision_pendiente' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('version');
    $this->postJson('/api/v1/cms/politicas/cookies', ['version' => '9.1', 'vigente_desde' => '2020-01-01', 'contenido' => $texto, 'revision_pendiente' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('vigente_desde');

    $r = $this->postJson('/api/v1/cms/politicas/cookies', ['version' => '9.1', 'vigente_desde' => '2030-01-01', 'contenido' => $texto.'<script>alert(1)</script>', 'revision_pendiente' => false])->assertCreated();
    $nueva = collect($r->json('data.versiones'))->firstWhere('version', '9.1');
    expect($nueva['estado'])->toBe('programada')->and($nueva['contenido'])->not->toContain('<script')
        ->and($this->getJson('/api/v1/sitio/politicas/cookies')->json('version'))->toBe($vigente['version']);
});

// --- EDUN-383 / EDUN-407 · Dashboard y usuarios --------------------------------------------

it('dashboard y usuarios: responden al superadmin; un usuario de colegio recibe 403', function () {
    $d = $this->getJson('/api/v1/cms/dashboard')->assertOk()->json();
    expect($d['tendencia'])->toHaveCount(30)->and($d)->toHaveKeys(['leads_hoy', 'leads_7d', 'reclamos', 'borradores', 'ultimos_leads'])->not->toHaveKey('visitas');
    $u = $this->getJson('/api/v1/cms/usuarios')->assertOk()->json('data');
    expect(collect($u)->pluck('email')->all())->toContain('superadmin@demo.edunova.pe');

    $director = User::whereNotIn('id', dueno()->table('roles_plataforma')->pluck('user_id'))->firstOrFail();
    app('auth')->forgetGuards();
    Sanctum::actingAs($director);
    foreach (['/api/v1/cms/dashboard', '/api/v1/cms/usuarios', '/api/v1/cms/auditoria', '/api/v1/cms/seo', '/api/v1/cms/politicas', '/api/v1/cms/secciones/_config'] as $ruta) {
        $this->getJson($ruta)->assertForbidden();
    }
    $this->postJson('/api/v1/cms/politicas/cookies', [])->assertForbidden();
});
