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
                // EDUN-347 · Detalle en /funcionalidades.
                'lista.*.descripcion_larga' => ['nullable', 'string', 'max:2000'],
                'lista.*.beneficios' => ['sometimes', 'array', 'max:8'],
                'lista.*.beneficios.*' => ['nullable', 'string', 'max:120'],
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
            // EDUN-387 / SIT-020 · Orden y visibilidad de las secciones de la landing.
            '_estructura' => [
                'lista' => ['required', 'array', 'min:1', 'max:30'],
                'lista.*.clave' => ['required', 'string', 'max:40', 'distinct'],
                'lista.*.visible' => ['required', 'boolean'],
            ],
            // EDUN-391 / SIT-025 · Popup de salida (cms.popup_config).
            '_popup' => [
                'activo' => ['required', 'boolean'],
                'titulo' => ['required', 'string', 'max:100'],
                'texto' => ['nullable', 'string', 'max:200'],
                'imagen_id' => ['nullable', 'integer', 'min:1'],
                'cta' => ['required', 'string', 'max:40'],
                'campo' => ['required', Rule::in(['email', 'telefono', 'ambos'])],
                'espera_seg' => ['required', 'integer', 'min:0', 'max:600'],
                'inactividad_seg' => ['required', 'integer', 'min:0', 'max:600'],
                'frecuencia' => ['required', Rule::in(['sesion', 'dia', 'siempre'])],
            ],
            // EDUN-399 / SIT-062 · Datos legales y de contacto (cms.config) y modo mantenimiento.
            '_config' => [
                'razon_social' => ['nullable', 'string', 'max:160'],
                'ruc' => ['nullable', 'regex:/^(10|15|17|20)\d{9}$/'],
                'direccion' => ['nullable', 'string', 'max:250'],
                'telefono_central' => ['nullable', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
                'whatsapp' => ['nullable', 'regex:/^\+?[0-9 ()-]{9,20}$/'],
                'email_contacto' => ['nullable', 'email:rfc', 'max:160'],
                'email_reclamaciones' => ['nullable', 'email:rfc', 'max:160'],
                'horario_soporte' => ['nullable', 'string', 'max:120'],
                'mantenimiento' => ['required', 'array'],
                'mantenimiento.activo' => ['required', 'boolean'],
                'mantenimiento.mensaje' => ['nullable', 'required_if:mantenimiento.activo,true', 'string', 'max:300'],
                'mantenimiento.vuelta' => ['nullable', 'date_format:Y-m-d\TH:i'],
            ],
            // EDUN-371 / SIT-007 · Preguntas frecuentes (cms.faq).
            '_faq' => [
                'lista' => ['present', 'array', 'max:100'],
                'lista.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
                'lista.*.pregunta' => ['required', 'string', 'max:200'],
                'lista.*.respuesta' => ['required', 'string', 'max:2000'],
                'lista.*.categoria' => ['nullable', 'string', 'max:60'],
                'lista.*.visible' => ['required', 'boolean'],
            ],
            // EDUN-375 / SIT-008 · Chatbot: modo flujo o WhatsApp, horario y nodos del flujo.
            '_chatbot' => [
                'activo' => ['required', 'boolean'],
                'modo' => ['required', Rule::in(['flujo', 'whatsapp'])],
                'titulo' => ['required', 'string', 'max:60'],
                'saludo' => ['required', 'string', 'max:300'],
                'fuera_horario' => ['required', 'string', 'max:300'],
                'whatsapp_mensaje' => ['nullable', 'string', 'max:200'],
                'horario' => ['required', 'array'],
                'horario.dias' => ['required', 'array', 'min:1', 'max:7'],
                'horario.dias.*' => ['integer', 'between:1,7', 'distinct'],
                'horario.desde' => ['required', 'date_format:H:i'],
                'horario.hasta' => ['required', 'date_format:H:i', 'after:horario.desde'],
                'nodos' => ['present', 'array', 'max:40'],
                'nodos.*.tipo' => ['required', Rule::in(['mensaje', 'opciones', 'captura', 'url', 'whatsapp', 'fin'])],
                'nodos.*.texto' => ['required', 'string', 'max:500'],
                'nodos.*.url' => ['nullable', 'string', 'max:300', 'regex:#^(https://|/(?!/))#'],
                'nodos.*.siguiente' => ['nullable', 'integer', 'min:0'],
                'nodos.*.opciones' => ['sometimes', 'array', 'max:6'],
                'nodos.*.opciones.*.texto' => ['required', 'string', 'max:60'],
                'nodos.*.opciones.*.destino' => ['required', 'integer', 'min:0'],
                // EDUN-380 · Posición del paso en el lienzo del constructor visual.
                'nodos.*.posicion' => ['nullable', 'array'],
                'nodos.*.posicion.x' => ['required_with:nodos.*.posicion', 'integer', 'between:0,3000'],
                'nodos.*.posicion.y' => ['required_with:nodos.*.posicion', 'integer', 'between:0,3000'],
            ],
            // EDUN-338 / SIT-060 · Logos de clientes («Confían en Edunova»).
            'logos' => [
                'titulo' => ['required', 'string', 'max:80'],
                'velocidad' => ['required', 'integer', 'min:10', 'max:120'],
                'columnas' => ['required', 'integer', 'min:3', 'max:8'],
                'mostrar' => ['sometimes', 'boolean'],
                'lista' => ['sometimes', 'array', 'max:40'],
                'lista.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
                'lista.*.nombre' => ['required', 'string', 'max:120'],
                'lista.*.logo_id' => ['required', 'integer', 'min:1'],
                'lista.*.sitio_web' => ['nullable', 'url:https,http', 'max:300'],
                'lista.*.autorizado' => ['required', 'boolean'],
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
            'ruc.regex' => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.',
            'telefono_central.regex' => 'Escribe un teléfono válido.',
            'whatsapp.regex' => 'Escribe un número de WhatsApp válido (con código de país si es posible).',
            'email' => 'Escribe un correo válido.',
            'mantenimiento.mensaje.required_if' => 'Escribe el mensaje que verán los visitantes durante el mantenimiento.',
            'mantenimiento.vuelta.date_format' => 'Fecha y hora no válidas.',
            'lista.*.sitio_web.url' => 'Escribe la dirección completa del sitio (https://…).',
            'espera_seg.max' => 'Máximo 600 segundos.',
            'horario.hasta.after' => 'La hora de cierre debe ser posterior a la de apertura.',
            'horario.dias.required' => 'Elige al menos un día de atención.',
            'nodos.*.url.regex' => 'Usa una dirección https o una ruta del sitio (/…).',
            'inactividad_seg.max' => 'Máximo 600 segundos.',
        ];
    }
}
