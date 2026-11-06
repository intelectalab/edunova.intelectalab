<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Contenido inicial del esquema cms (EDUN-223).
 *
 * El contenido real (precios, ofertas, datos de la empresa) está en
 * database/sql/cms/privado/contenido.sql, fuera de git. Si no existe, se cargan las
 * semillas neutras de cms/02_cms_semillas_base.sql, suficientes para el sitio y las pruebas.
 * Con EDUNOVA_CMS_CONTENIDO=base se fuerzan las neutras (así corren las pruebas).
 */
class CmsSeeder extends Seeder
{
    public const PRIVADO = 'sql/cms/privado/contenido.sql';

    public const BASE = 'sql/cms/02_cms_semillas_base.sql';

    public function run(): void
    {
        DB::unprepared(file_get_contents(database_path(self::archivo())));
    }

    public static function archivo(): string
    {
        $privado = database_path(self::PRIVADO);

        return env('EDUNOVA_CMS_CONTENIDO') !== 'base' && is_file($privado) ? self::PRIVADO : self::BASE;
    }
}
