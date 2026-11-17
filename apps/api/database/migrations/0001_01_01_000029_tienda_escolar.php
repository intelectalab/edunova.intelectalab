<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S34 · Tienda escolar (EDUN-179 a 185).
 *
 * El esquema del kit declara tienda_pedidos.forma_pago como varchar(10), pero uno de sus valores
 * permitidos («con_pension») tiene 11 caracteres: se amplía la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE tienda_pedidos ALTER COLUMN forma_pago TYPE varchar(12)');
    }

    public function down(): void
    {
        // No se reduce: los pedidos «con_pension» no cabrían.
    }
};
