<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Primera versión del modelo de datos de Edunova (171 tablas, RLS por colegio).
 *
 * Carga database/sql/01_esquema.sql tal cual viene del kit. Los cambios posteriores
 * van en migraciones nuevas, nunca editando ese archivo.
 *
 * Se ejecuta con la conexión «propietario» (dueño de la base):
 *   php artisan migrate --database=propietario
 */
return new class extends Migration
{
    /** El archivo SQL maneja su propia transacción (BEGIN … COMMIT). */
    public $withinTransaction = false;

    public function up(): void
    {
        // migrate:fresh borra las tablas de «public», pero no el esquema «salud».
        DB::unprepared('DROP SCHEMA IF EXISTS salud CASCADE;');
        DB::unprepared(file_get_contents(database_path('sql/01_esquema.sql')));
    }

    public function down(): void
    {
        throw new RuntimeException('El esquema inicial no se revierte: usa migrate:fresh en desarrollo.');
    }
};
