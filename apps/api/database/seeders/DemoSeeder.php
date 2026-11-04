<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Colegio ficticio «IEP San Martín de Porres» (database/sql/03_datos_demo.sql).
 * Todos los nombres, DNI y montos son de ejemplo. Nunca se carga en producción.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/03_datos_demo.sql')));
    }
}
