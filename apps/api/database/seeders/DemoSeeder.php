<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Colegio ficticio «IEP San Martín de Porres» (database/sql/03_datos_demo.sql).
 * Todos los nombres, DNI y montos son de ejemplo. Nunca se carga en producción.
 *
 * Usuarios de demostración (solo local y pruebas), todos con la clave DEMO_PASSWORD:
 * - director@demo.edunova.pe · DNI 29000001 · director y promotor del colegio demo
 * - superadmin@demo.edunova.pe · DNI 29000009 · superadmin de la plataforma (Panel CMS)
 */
class DemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Edunova.Demo.2026';

    public function run(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/03_datos_demo.sql')));

        $clave = Hash::make(self::DEMO_PASSWORD);
        DB::table('users')->where('email', 'director@demo.edunova.pe')->update(['password' => $clave]);

        $superadmin = DB::table('users')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Administradora', 'numero_documento' => '29000009',
            'email' => 'superadmin@demo.edunova.pe', 'password' => $clave, 'es_staff_plataforma' => true,
        ]);
        DB::table('roles_plataforma')->insert([
            'user_id' => $superadmin,
            'rol_id' => DB::table('roles')->where('codigo', 'superadmin')->value('id'),
        ]);
    }
}
