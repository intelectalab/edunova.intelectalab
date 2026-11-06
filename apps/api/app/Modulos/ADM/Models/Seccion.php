<?php

namespace App\Modulos\ADM\Models;

use App\Modulos\Compartido\PerteneceAInstitucion;
use Illuminate\Database\Eloquent\Model;

class Seccion extends Model
{
    use PerteneceAInstitucion;

    protected $table = 'secciones';

    protected $guarded = ['id', 'institucion_id'];
}
