<?php

namespace App\Modulos\SIT\Services;

use Illuminate\Validation\Rule;

/**
 * Qué se puede guardar en cada sección de la landing (cms.secciones.config).
 * Los límites salen de TRASPASO §6.1 y del prototipo. Una sección que no está aquí no se edita.
 *
 * Sprint 9: las pantallas de Planes (EDUN-326), Módulos (EDUN-330) y Testimonios (EDUN-334)
 * guardan además sus listas en el mismo borrador; al publicar, ContenidoEntidades las pasa a
 * sus tablas. Las listas son opcionales («sometimes»): un borrador sin lista no la cambia.
 */
class ReglasSecciones
{
    /** @return array<string, array<int, mixed>>|null */
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
            'modulos' => [
                'insignia' => ['required', 'string', 'max:40'],
                'titulo' => ['required', 'string', 'max:120'],
                'descripcion' => ['nullable', 'string', 'max:220'],
                // {n} se reemplaza por la cantidad de módulos visibles.
                'boton_ver' => ['required', 'string', 'max:60'],
                'boton_ocultar' => ['required', 'string', 'max:60'],
                // EDUN-330 · Los módulos existentes: orden (posición en la lista), textos, imagen y grid.
                'lista' => ['sometimes', 'array', 'min:1', 'max:40'],
                'lista.*.clave' => ['required', 'string', 'max:30', 'distinct'],
                'lista.*.nombre' => ['required', 'string', 'max:80'],
                'lista.*.icono' => ['required', 'string', 'regex:/^[a-z0-9_]{2,60}$/'],
                'lista.*.descripcion' => ['required', 'string', 'max:180'],
                'lista.*.etiqueta' => ['nullable', 'string', 'max:60'],
                'lista.*.imagen_id' => ['nullable', 'integer', 'min:1'],
                'lista.*.en_grid' => ['required', 'boolean'],
                'lista.*.visible' => ['required', 'boolean'],
            ],
            'planes' => [
                'insignia' => ['required', 'string', 'max:40'],
                'titulo' => ['required', 'string', 'max:120'],
                'descripcion' => ['nullable', 'string', 'max:220'],
                'mensual' => ['required', 'string', 'max:30'],
                'anual' => ['required', 'string', 'max:30'],
                'por_mes' => ['required', 'string', 'max:20'],
                'personalizado' => ['required', 'string', 'max:40'],
                'personalizado_nota' => ['required', 'string', 'max:80'],
                // EDUN-326 · Nota legal bajo los precios (cms.config.nota_legal_precios).
                'nota_legal' => ['sometimes', 'required', 'string', 'max:160'],
                'lista' => ['sometimes', 'array', 'min:1', 'max:4'],
                'lista.*.clave' => ['required', Rule::in(['inicial', 'crecimiento', 'integral', 'institucional']), 'distinct'],
                'lista.*.nombre' => ['required', 'string', 'max:60'],
                'lista.*.rango_texto' => ['required', 'string', 'max:80'],
                'lista.*.alumnos_max' => ['nullable', 'integer', 'min:1', 'max:100000'],
                // Texto decimal con 2 decimales (nunca float). Vacío = «Personalizado».
                'lista.*.precio_mensual' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
                'lista.*.descripcion' => ['nullable', 'string', 'max:160'],
                'lista.*.caracteristicas' => ['present', 'array', 'max:12'],
                'lista.*.caracteristicas.*.texto' => ['required', 'string', 'max:90'],
                'lista.*.caracteristicas.*.incluida' => ['required', 'boolean'],
                'lista.*.recomendado' => ['required', 'boolean'],
                'lista.*.visible' => ['required', 'boolean'],
                'lista.*.cta_texto' => ['required', 'string', 'max:40'],
                'complementos' => ['sometimes', 'array', 'max:20'],
                'complementos.*.clave' => ['required', 'string', 'max:30', 'distinct'],
                'complementos.*.etiqueta' => ['required', 'string', 'max:120'],
                'complementos.*.precio' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
                'complementos.*.nota' => ['nullable', 'string', 'max:120'],
                'complementos.*.visible' => ['required', 'boolean'],
            ],
            'testimonios' => [
                'insignia' => ['required', 'string', 'max:40'],
                'titulo' => ['required', 'string', 'max:120'],
                'leyenda_ejemplo' => ['required', 'string', 'max:120'],
                // EDUN-334 · Testimonios: sin id = nuevo; los que faltan en la lista se eliminan.
                'lista' => ['sometimes', 'array', 'max:30'],
                'lista.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
                'lista.*.nombre' => ['nullable', 'string', 'max:120'],
                'lista.*.cargo' => ['required', 'string', 'max:80'],
                'lista.*.institucion' => ['required', 'string', 'max:120'],
                'lista.*.avatar_id' => ['nullable', 'integer', 'min:1'],
                'lista.*.texto' => ['required', 'string', 'min:20', 'max:320'],
                'lista.*.calificacion' => ['required', 'integer', 'between:1,5'],
                'lista.*.es_ejemplo' => ['required', 'boolean'],
                'lista.*.consentimiento_obtenido' => ['required', 'boolean'],
                'lista.*.consentimiento_fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'lista.*.consentimiento_doc_id' => ['nullable', 'integer', 'min:1'],
                'lista.*.visible' => ['required', 'boolean'],
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
            'min' => 'Mínimo :min caracteres.',
            'string' => 'Debe ser texto.',
            'distinct' => 'Está repetido.',
            'boolean' => 'Valor no válido.',
            'integer' => 'Debe ser un número entero.',
            'lista.*.icono.regex' => 'Usa el nombre de un ícono de Material Symbols (minúsculas y guiones bajos).',
            'lista.*.precio_mensual.regex' => 'Escribe el precio en soles con hasta 2 decimales (ej.: 249.90), o déjalo vacío para «Personalizado».',
            'lista.*.precio_mensual.not_in' => 'El precio debe ser mayor que cero.',
            'complementos.*.precio.regex' => 'Escribe el precio en soles con hasta 2 decimales, o déjalo vacío para «A medida».',
            'lista.*.calificacion.between' => 'La calificación va de 1 a 5 estrellas.',
            'lista.*.texto.min' => 'El testimonio necesita al menos 20 caracteres.',
            'lista.*.texto.max' => 'El testimonio admite hasta 320 caracteres.',
            'lista.*.consentimiento_fecha.before_or_equal' => 'La fecha del consentimiento no puede ser futura.',
            'lista.*.consentimiento_fecha.date_format' => 'Fecha no válida.',
            'lista.max' => 'Demasiados elementos en la lista.',
        ];
    }
}
