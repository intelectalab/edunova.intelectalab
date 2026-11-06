<?php

use Illuminate\Support\Facades\DB;

/*
| EDUN-223 · Esquema cms. La app se conecta como edunova_app (sin bypass de RLS) y fija
| app.rol_cms en cada transacción, como lo hará la API del sitio y del CMS.
| Las 17 pruebas completas de reglas y permisos están en database/sql/cms/pruebas_cms.sql.
*/

function comoRolCms(string $rol, callable $consulta): mixed
{
    return DB::transaction(function () use ($rol, $consulta) {
        DB::select("select set_config('app.rol_cms', ?, true)", [$rol]);

        return $consulta();
    });
}

it('crea las 28 tablas del esquema cms', function () {
    $tablas = DB::selectOne("select count(*) as n from information_schema.tables where table_schema = 'cms' and table_type = 'BASE TABLE'")->n;

    expect($tablas)->toBe(28);
});

it('carga las semillas neutras: 4 planes, 18 módulos y 6 en el grid', function () {
    expect(DB::table('cms.planes_sitio')->count())->toBe(4)
        ->and(DB::table('cms.modulos_web')->count())->toBe(18)
        ->and(DB::table('cms.modulos_web')->where('en_grid', true)->count())->toBe(6);
});

it('las semillas neutras no traen precios ni datos de la empresa', function () {
    expect(DB::table('cms.planes_sitio')->whereNotNull('precio_mensual')->count())->toBe(0)
        ->and(DB::table('cms.precios_complementos')->count())->toBe(0)
        ->and(DB::table('cms.config')->where('clave', 'razon_social')->value('valor'))->toBe('null');
});

it('el público lee el contenido pero no lo edita ni lee leads', function () {
    comoRolCms('publico', function () {
        expect(DB::table('cms.planes_sitio')->count())->toBe(4)
            ->and(DB::table('cms.planes_sitio')->where('clave', 'inicial')->update(['nombre' => 'X']))->toBe(0)
            ->and(DB::table('cms.leads')->count())->toBe(0);
    });
});

it('un usuario de colegio (promotor) no tiene CMS', function () {
    comoRolCms('promotor', function () {
        expect(DB::table('cms.config')->where('clave', 'crm_endpoint')->update(['valor' => '"x"']))->toBe(0);
    });
});

it('el superadmin del SaaS edita el contenido', function () {
    comoRolCms('superadmin', function () {
        // Se reescribe el mismo valor para no alterar la base que comparten las demás pruebas.
        $actual = DB::table('cms.planes_sitio')->where('clave', 'inicial')->value('cta_texto');
        expect(DB::table('cms.planes_sitio')->where('clave', 'inicial')->update(['cta_texto' => $actual]))->toBe(1);
    });
});
