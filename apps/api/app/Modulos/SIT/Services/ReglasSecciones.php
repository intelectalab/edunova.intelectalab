<?php

namespace App\Modulos\SIT\Services;

/**
 * Qué se puede guardar en cada sección de la landing (cms.secciones.config).
 * Los límites salen de TRASPASO §6.1 y del prototipo. Una sección que no está aquí no se edita.
 */
class ReglasSecciones
{
    /** @return array<string, array<int, string>>|null */
    public static function para(string $clave): ?array
    {
        return match ($clave) {
            'barra_superior' => [
                'aviso' => ['required', 'string', 'max:120'],
            ],
            'hero' => [
                'insignia' => ['required', 'string', 'max:45'],
                'titulo' => ['required', 'string', 'max:90'],
                'subtitulo' => ['required', 'string', 'max:220'],
                'cta_principal' => ['required', 'string', 'max:30'],
                'cta_secundario' => ['nullable', 'string', 'max:30'],
                'sellos' => ['nullable', 'string', 'max:120'],
            ],
            default => null,
        };
    }

    /** Mensajes en español para el editor. */
    public static function mensajes(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'max' => 'Máximo :max caracteres.',
            'string' => 'Debe ser texto.',
        ];
    }
}
