<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semillas globales del kit: 25 roles, 165 permisos, módulos, planes, SLA,
 * parámetros legales y currículo (database/sql/02_semillas.sql), más el contenido
 * inicial del sitio (CmsSeeder).
 * En local y en pruebas carga además el colegio de demostración.
 *
 *   php artisan migrate --database=propietario --seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/02_semillas.sql')));
        DB::unprepared(file_get_contents(database_path('sql/04_permisos_adicionales.sql')));
        $this->call(CmsSeeder::class);

        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
