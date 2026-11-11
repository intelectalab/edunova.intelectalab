<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La app se conecta como edunova_app: el RLS de PostgreSQL decide qué ve.
 * Las 34 pruebas completas de la base están en database/sql/pruebas_aislamiento.sql.
 */
class AislamientoTest extends TestCase
{
    private function idColegioDemo(): int
    {
        return (int) DB::connection('propietario')->table('instituciones')->value('id');
    }

    /** Ejecuta una consulta con el colegio activo fijado, como lo hará el middleware. */
    private function conColegio(?int $id, callable $consulta): mixed
    {
        return DB::transaction(function () use ($id, $consulta) {
            DB::select("select set_config('app.institucion_id', ?, true)", [(string) ($id ?? '')]);

            return $consulta();
        });
    }

    public function test_la_app_usa_el_rol_sin_bypass_de_rls(): void
    {
        $this->assertSame('edunova_app', DB::selectOne('select current_user as u')->u);
    }

    public function test_sin_colegio_activo_no_ve_alumnos(): void
    {
        $this->assertSame(0, $this->conColegio(null, fn () => DB::table('alumnos')->count()));
    }

    public function test_con_su_colegio_ve_solo_sus_alumnos(): void
    {
        $demo = $this->idColegioDemo();

        $this->assertGreaterThan(0, $this->conColegio($demo, fn () => DB::table('alumnos')->count()));
        $this->assertSame(0, $this->conColegio($demo + 1000, fn () => DB::table('alumnos')->count()));
    }

    public function test_la_app_no_puede_modificar_catalogos_globales(): void
    {
        $this->expectException(QueryException::class);

        DB::table('planes')->insert(['codigo' => 'X', 'nombre' => 'x', 'alumnos_min' => 0, 'alumnos_max' => 1]);
    }

    public function test_las_semillas_cargan_roles_y_permisos(): void
    {
        $this->assertSame(25, DB::table('roles')->count());
        // 165 del catálogo del kit + seg.archivos.confidenciales (EDUN-42) + pag.beneficios.aprobar (EDUN-72).
        $this->assertSame(167, DB::table('permisos')->count());
    }
}
