<?php

namespace App\Modulos\ADM\Policies;

use App\Models\User;
use App\Modulos\ADM\Models\Seccion;
use App\Modulos\SEG\Services\AlcanceAcademico;

/** EDUN-39 · El docente solo trabaja con sus secciones y el tutor con la que tutorea. */
class SeccionPolicy
{
    public function __construct(private AlcanceAcademico $alcance) {}

    public function ver(User $usuario, Seccion $seccion): bool
    {
        return $this->alcance->puedeVerSeccion($usuario, $seccion->id);
    }
}
