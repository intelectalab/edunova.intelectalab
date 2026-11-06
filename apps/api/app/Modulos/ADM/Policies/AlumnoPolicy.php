<?php

namespace App\Modulos\ADM\Policies;

use App\Models\User;
use App\Modulos\ADM\Models\Alumno;
use App\Modulos\SEG\Services\AlcanceAcademico;
use App\Modulos\SEG\Services\Autorizacion;

/**
 * EDUN-39 · Quién ve a un alumno. El personal necesita el permiso adm.alumnos.ver y, si es
 * docente o tutor, que el alumno esté en una de sus secciones. Apoderado y alumno no tienen
 * permisos de staff: entran solo por propiedad (sus hijos / él mismo).
 */
class AlumnoPolicy
{
    public function __construct(private Autorizacion $autorizacion, private AlcanceAcademico $alcance) {}

    public function ver(User $usuario, Alumno $alumno): bool
    {
        $esFamilia = $this->autorizacion->tieneRol($usuario, 'apoderado', 'alumno');
        if (! $esFamilia && ! $this->autorizacion->tiene($usuario, 'adm.alumnos.ver')) {
            return false;
        }

        return $this->alcance->puedeVerAlumno($usuario, $alumno->id);
    }
}
