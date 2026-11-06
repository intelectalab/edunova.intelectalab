<?php

namespace App\Modulos\ADM\Models;

use App\Modulos\Compartido\PerteneceAInstitucion;
use Illuminate\Database\Eloquent\Model;

class Alumno extends Model
{
    use PerteneceAInstitucion;

    protected $table = 'alumnos';

    protected $guarded = ['id', 'institucion_id'];

    protected function casts(): array
    {
        return ['fecha_nacimiento' => 'date'];
    }
}
