<?php

use Illuminate\Support\Facades\DB;

/*
| EDUN-251 / SIT-048 · Módulos de la landing: orden del CMS, imagen con texto alternativo
| y solo los publicados y visibles.
*/

it('devuelve los módulos visibles en el orden del CMS, con 6 en el grid', function () {
    $modulos = collect($this->getJson('/api/v1/sitio/landing')->assertOk()->json('modulos'));

    $ordenCms = DB::connection('propietario')->table('cms.modulos_web')->where('visible', true)->orderBy('orden')->pluck('clave')->all();
    expect($modulos->pluck('clave')->all())->toBe($ordenCms)
        ->and($modulos->where('en_grid', true)->count())->toBe(6);
});

it('un módulo con imagen la devuelve con su texto alternativo; uno oculto no sale', function () {
    $db = DB::connection('propietario');
    $imagen = $db->table('cms.medios')->insertGetId([
        'tipo' => 'imagen', 'nombre' => 'matriculas.webp', 'url' => 'https://cdn.ejemplo.pe/matriculas.webp',
        'mime' => 'image/webp', 'bytes' => 48000, 'ancho' => 1200, 'alto' => 800, 'alt' => 'Lista de postulantes del colegio',
    ]);
    $db->table('cms.modulos_web')->where('clave', 'matriculas')->update(['imagen_id' => $imagen]);
    $db->table('cms.modulos_web')->where('clave', 'laboratorios')->update(['visible' => false]);

    try {
        $modulos = collect($this->getJson('/api/v1/sitio/landing')->json('modulos'))->keyBy('clave');
        expect($modulos['matriculas']['imagen'])->toBe(['url' => 'https://cdn.ejemplo.pe/matriculas.webp', 'alt' => 'Lista de postulantes del colegio', 'ancho' => 1200, 'alto' => 800])
            ->and($modulos['pagos']['imagen'])->toBeNull()
            ->and($modulos->has('laboratorios'))->toBeFalse();
    } finally {
        $db->table('cms.modulos_web')->where('clave', 'matriculas')->update(['imagen_id' => null]);
        $db->table('cms.modulos_web')->where('clave', 'laboratorios')->update(['visible' => true]);
        $db->table('cms.medios')->where('id', $imagen)->delete();
    }
});

it('incluye los textos de la sección de módulos', function () {
    expect($this->getJson('/api/v1/sitio/landing')->json('secciones.modulos.boton_ver'))->toBe('Ver los {n} módulos del sistema escolar');
});
