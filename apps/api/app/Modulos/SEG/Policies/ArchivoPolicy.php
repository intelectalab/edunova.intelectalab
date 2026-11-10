<?php

namespace App\Modulos\SEG\Policies;

use App\Models\User;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\Autorizacion;

/**
 * EDUN-42 · Quién descarga un archivo del colegio activo (el RLS ya impide ver los de otro).
 * Los archivos no confidenciales los descarga cualquier miembro; los confidenciales, solo
 * quien los subió o quien tiene seg.archivos.confidenciales. Los módulos con reglas propias
 * (expedientes, salud…) suman sus policies encima de esta.
 */
class ArchivoPolicy
{
    public function __construct(private Autorizacion $autorizacion) {}

    public function descargar(User $usuario, Archivo $archivo): bool
    {
        if (! $this->autorizacion->esMiembro($usuario)) {
            return false;
        }
        if (! $archivo->confidencial) {
            return true;
        }

        return $archivo->subido_por === $usuario->id || $this->autorizacion->tiene($usuario, 'seg.archivos.confidenciales');
    }
}
