<?php

namespace App\Modulos\SEG\Models;

use App\Modulos\Compartido\PerteneceAInstitucion;
use Illuminate\Database\Eloquent\Model;

/**
 * Archivo guardado en el almacenamiento de objetos (tabla archivos).
 * La ruta siempre empieza con «{institucion_id}/».
 */
class Archivo extends Model
{
    use PerteneceAInstitucion;

    protected $table = 'archivos';

    protected $fillable = ['institucion_id', 'disco', 'ruta', 'nombre_original', 'mime', 'bytes', 'sha256', 'subido_por', 'confidencial'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'confidencial' => 'boolean',
        ];
    }
}
