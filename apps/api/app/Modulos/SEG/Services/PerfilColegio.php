<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PLA\Services\ModulosColegio;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la web y la app necesitan del colegio activo para armar el menú y la marca:
 * permisos del usuario, módulos encendidos, opción de aula (EDUN-26) y logo y color (EDUN-43).
 */
class PerfilColegio
{
    public function __construct(private Autorizacion $autorizacion, private ModulosColegio $modulos) {}

    /** @return array{permisos: list<string>, modulos: list<string>, aula_opcion: string, estado: string|null, marca: array{color_primario: string|null, tiene_logo: bool}} */
    public function datos(User $usuario, int $institucionId): array
    {
        return DB::transaction(function () use ($usuario, $institucionId) {
            ContextoInstitucion::fijar($institucionId);
            $this->autorizacion->olvidar();
            $institucion = DB::table('instituciones')->where('id', $institucionId)->first(['estado', 'color_primario', 'logo_archivo_id']);
            $datos = [
                'permisos' => $this->autorizacion->permisos($usuario),
                'modulos' => $this->modulos->activos(),
                'aula_opcion' => $this->modulos->aulaOpcion(),
                'estado' => $institucion->estado ?? null,
                'marca' => [
                    'color_primario' => $institucion->color_primario ?? null,
                    'tiene_logo' => ($institucion->logo_archivo_id ?? null) !== null,
                ],
            ];
            $this->autorizacion->olvidar();

            return $datos;
        });
    }
}
