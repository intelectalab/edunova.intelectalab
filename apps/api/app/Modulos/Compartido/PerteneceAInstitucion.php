<?php

namespace App\Modulos\Compartido;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelos de datos de un colegio (tablas con institucion_id).
 * Al crear, toma el colegio activo de la transacción. El filtrado lo hace el RLS de
 * PostgreSQL; este trait no agrega un scope global para no ocultar errores de contexto.
 *
 * @mixin Model
 */
trait PerteneceAInstitucion
{
    public static function bootPerteneceAInstitucion(): void
    {
        static::creating(function (Model $modelo) {
            $modelo->institucion_id ??= ContextoInstitucion::requerido();
        });
    }
}
