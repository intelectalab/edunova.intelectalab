<?php

use Illuminate\Support\Facades\DB;

/*
| Sprint 5 · Contenido de la landing que entrega GET /api/v1/sitio/landing:
| planes y oferta (EDUN-260), cifras (EDUN-245), videos (EDUN-254) y testimonios (EDUN-257).
| Las pruebas cambian datos con la conexión dueña y los dejan como estaban.
*/

function landing(): array
{
    return test()->getJson('/api/v1/sitio/landing')->assertOk()->json();
}

function conConfig(string $clave, mixed $valor, callable $prueba): void
{
    $db = DB::connection('propietario');
    $antes = $db->table('cms.config')->where('clave', $clave)->value('valor');
    $db->table('cms.config')->updateOrInsert(['clave' => $clave], ['valor' => json_encode($valor)]);
    try {
        $prueba();
    } finally {
        $antes === null ? $db->table('cms.config')->where('clave', $clave)->delete() : $db->table('cms.config')->where('clave', $clave)->update(['valor' => $antes]);
    }
}

it('devuelve los planes publicados con el precio como texto decimal', function () {
    $db = DB::connection('propietario');
    $db->table('cms.planes_sitio')->where('clave', 'crecimiento')->update(['precio_mensual' => 500]);
    try {
        $planes = collect(landing()['planes'])->keyBy('clave');
        expect($planes['crecimiento']['precio_mensual'])->toBe('500.00')
            ->and($planes['crecimiento']['recomendado'])->toBeTrue()
            ->and($planes->keys()->all())->toBe(['inicial', 'crecimiento', 'integral', 'institucional']);
    } finally {
        $db->table('cms.planes_sitio')->where('clave', 'crecimiento')->update(['precio_mensual' => null]);
    }
});

it('la oferta de migración no aparece si venció o está oculta', function () {
    conConfig('oferta_migracion', ['visible' => true, 'titulo' => 'Oferta', 'texto' => 'Migra gratis', 'cta' => 'Quiero migrar', 'expira' => now('America/Lima')->subDay()->toDateString()], function () {
        expect(landing()['oferta'])->toBeNull();
    });
    conConfig('oferta_migracion', ['visible' => true, 'titulo' => 'Oferta', 'texto' => 'Migra gratis', 'cta' => 'Quiero migrar', 'expira' => now('America/Lima')->addDay()->toDateString()], function () {
        expect(landing()['oferta'])->toBe(['titulo' => 'Oferta', 'texto' => 'Migra gratis', 'cta' => 'Quiero migrar']);
    });
    conConfig('oferta_migracion', ['visible' => false, 'texto' => 'x'], fn () => expect(landing()['oferta'])->toBeNull());
});

it('un contador oculto no sale en la franja de cifras', function () {
    $db = DB::connection('propietario');
    $id = $db->table('cms.contadores')->orderBy('orden')->value('id');
    $db->table('cms.contadores')->where('id', $id)->update(['visible' => false]);
    try {
        expect(landing()['contadores'])->toHaveCount(3);
    } finally {
        $db->table('cms.contadores')->where('id', $id)->update(['visible' => true]);
    }
    expect(landing()['contadores'][0]['valor'])->toEqual(18);
});

it('solo hasta 3 videos visibles y marcados para la landing', function () {
    $db = DB::connection('propietario');
    foreach (range(1, 4) as $i) {
        $db->table('cms.videos')->insert(['titulo' => "Video {$i}", 'proveedor' => 'youtube', 'embed_url' => "https://www.youtube-nocookie.com/embed/x{$i}", 'orden' => $i, 'visible' => $i !== 2]);
    }
    try {
        expect(collect(landing()['videos'])->pluck('titulo')->all())->toBe(['Video 1', 'Video 3', 'Video 4']);
    } finally {
        $db->table('cms.videos')->delete();
    }
});

it('testimonios: no sale uno real sin consentimiento; el de ejemplo no muestra nombre; filtro de 4 estrellas', function () {
    $db = DB::connection('propietario');
    $doc = $db->table('cms.medios')->insertGetId(['tipo' => 'pdf', 'nombre' => 'consentimiento.pdf', 'url' => 's3://x/c.pdf', 'mime' => 'application/pdf', 'bytes' => 100]);
    $ids = [
        'ejemplo' => $db->table('cms.testimonios')->insertGetId(['nombre' => 'No mostrar', 'cargo' => 'Director', 'institucion' => 'Colegio A', 'texto' => 'Texto de un testimonio de ejemplo.', 'calificacion' => 5, 'es_ejemplo' => true, 'visible' => true, 'estado' => 'publicado', 'orden' => 1]),
        'sin_consentimiento' => $db->table('cms.testimonios')->insertGetId(['nombre' => 'Sin firma', 'cargo' => 'Tesorera', 'institucion' => 'Colegio B', 'texto' => 'Texto de un testimonio sin firma.', 'calificacion' => 5, 'visible' => false, 'estado' => 'publicado', 'orden' => 2]),
        'real' => $db->table('cms.testimonios')->insertGetId(['nombre' => 'Rosa Pérez', 'cargo' => 'Promotora', 'institucion' => 'Colegio C', 'texto' => 'Texto de un testimonio real firmado.', 'calificacion' => 3, 'consentimiento_obtenido' => true, 'consentimiento_fecha' => '2026-10-01', 'consentimiento_doc_id' => $doc, 'visible' => true, 'estado' => 'publicado', 'orden' => 3]),
    ];
    try {
        $t = collect(landing()['testimonios'])->keyBy('id');
        expect($t->has($ids['sin_consentimiento']))->toBeFalse()
            ->and($t[$ids['ejemplo']]['nombre'])->toBeNull()
            ->and($t[$ids['ejemplo']]['es_ejemplo'])->toBeTrue()
            ->and($t[$ids['real']]['nombre'])->toBe('Rosa Pérez');

        conConfig('testimonios_solo_4_estrellas', true, function () use ($ids) {
            expect(collect(landing()['testimonios'])->pluck('id'))->not->toContain($ids['real']);
        });
    } finally {
        $db->table('cms.testimonios')->whereIn('id', array_values($ids))->delete();
        $db->table('cms.medios')->where('id', $doc)->delete();
    }
});

it('la configuración pública no incluye la oferta cruda ni datos internos', function () {
    $config = landing()['config'];
    expect($config)->toHaveKey('nota_legal_precios')->toHaveKey('ruc')
        ->not->toHaveKey('oferta_migracion')->not->toHaveKey('crm_endpoint');
});
