<?php

namespace App\Modulos\PLA\Services;

use Illuminate\Support\Facades\DB;

/**
 * EDUN-25 / EDUN-26 · Módulos encendidos y opción de aula del colegio activo.
 *
 * Se lee en cada petición (sin caché): encender o apagar un módulo desde la consola aplica de
 * inmediato, sin reiniciar nada. El RLS de suscripciones y suscripcion_modulos limita la
 * lectura al colegio activo. PLA y SEG no se apagan: son la plataforma misma.
 */
class ModulosColegio
{
    public const SIEMPRE = ['PLA', 'SEG'];

    /** Qué módulo de aula corresponde a cada opción (suscripciones.aula_opcion). */
    public const AULA = [
        'medida' => ['AUL'],
        'moodle' => ['MDL'],
        'ambas' => ['AUL', 'MDL'],
        'ninguna' => [],
    ];

    /** Suscripción vigente: la más reciente que no esté cancelada ni vencida. */
    public static function suscripcion(?string $conexion = null, ?int $institucionId = null): ?object
    {
        return DB::connection($conexion)->table('suscripciones')
            ->when($institucionId, fn ($q, $id) => $q->where('institucion_id', $id))
            ->whereIn('estado', ['prueba', 'activa'])
            ->orderByDesc('fecha_inicio')->orderByDesc('id')
            ->first();
    }

    /** @return list<string> códigos de módulo activos del colegio activo */
    public function activos(): array
    {
        $suscripcion = self::suscripcion();
        if (! $suscripcion) {
            return self::SIEMPRE;
        }
        $codigos = DB::table('suscripcion_modulos as sm')
            ->join('modulos_catalogo as m', 'm.id', '=', 'sm.modulo_id')
            ->where('sm.suscripcion_id', $suscripcion->id)
            ->where('sm.activo', true)
            ->pluck('m.codigo')->all();

        return array_values(array_unique([...self::SIEMPRE, ...$codigos]));
    }

    public function activo(string $codigo): bool
    {
        return in_array(strtoupper($codigo), $this->activos(), true);
    }

    public function aulaOpcion(): string
    {
        return self::suscripcion()->aula_opcion ?? 'ninguna';
    }
}
