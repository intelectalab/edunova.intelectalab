<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 9 · Pantallas del CMS que editan tablas además de los textos de su sección:
 *   planes       → cms.planes_sitio, cms.precios_complementos y cms.config.nota_legal_precios (EDUN-326)
 *   modulos      → cms.modulos_web (EDUN-330)
 *   testimonios  → cms.testimonios (EDUN-334)
 * Sprint 10:
 *   logos        → cms.clientes y la visibilidad de la sección (EDUN-338)
 *   _estructura  → orden y visibilidad de cms.secciones (EDUN-387)
 *   _popup       → cms.popup_config (EDUN-391)
 *   _config      → datos legales y de contacto en cms.config y modo mantenimiento (EDUN-399)
 * Las secciones «_…» son internas del panel: siempre ocultas y sin textos propios.
 *
 * El borrador vive en cms.secciones.borrador junto con los textos (mismo flujo de la franja
 * «Guardar borrador / Publicar», versiones y auditoría de EditorCms). Al publicar, las listas
 * pasan a sus tablas y en cms.secciones.config quedan solo los textos.
 * Corre dentro de la transacción del middleware «cms» (app.rol_cms = superadmin).
 */
class ContenidoEntidades
{
    public const CLAVES = ['planes', 'modulos', 'testimonios', 'logos', '_estructura', '_popup', '_config', '_faq', '_chatbot'];

    /** Chatbot sin configurar: apagado, con textos neutros para empezar. */
    public const CHATBOT_VACIO = [
        'activo' => false, 'modo' => 'whatsapp', 'titulo' => 'Asistente de Edunova', 'saludo' => '¡Hola! ¿En qué te ayudamos?',
        'fuera_horario' => 'Ahora estamos fuera de horario. Déjanos tu correo o WhatsApp y te escribimos.',
        'whatsapp_mensaje' => null, 'horario' => ['dias' => [1, 2, 3, 4, 5], 'desde' => '08:00', 'hasta' => '18:00'],
    ];

    /** Claves del borrador que no son textos de la sección. */
    private const EXTRAS = ['lista', 'complementos', 'nota_legal', 'mostrar'];

    /** Datos de cms.config que se editan en Configuración general (EDUN-399). */
    public const CONFIG_GENERAL = ['razon_social', 'ruc', 'direccion', 'telefono_central', 'whatsapp', 'email_contacto', 'email_reclamaciones', 'horario_soporte'];

    public const MANTENIMIENTO_VACIO = ['activo' => false, 'mensaje' => null, 'vuelta' => null];

    /** Secciones internas del panel (clave con «_»): no son parte de la landing. */
    public static function esInterna(string $clave): bool
    {
        return str_starts_with($clave, '_');
    }

    /** Secciones de la landing, sin las internas del panel. */
    private static function seccionesLanding(): Builder
    {
        return DB::table('cms.secciones')->whereRaw("left(clave, 1) <> '_'");
    }

    public const MAX_GRID = 6;

    /** El hero y el footer (enlace obligatorio al Libro de Reclamaciones) siempre se muestran. */
    public const NO_OCULTABLES = ['hero', 'footer'];

    public function maneja(string $clave): bool
    {
        return in_array($clave, self::CLAVES, true);
    }

    /** Lo publicado en las tablas, con la forma del borrador (para el editor). */
    public function publicado(string $clave): array
    {
        return match ($clave) {
            'planes' => [
                // Sin nota guardada, la clave no va (el sitio usa su texto por defecto y una restauración no falla).
                ...(($nota = json_decode((string) DB::table('cms.config')->where('clave', 'nota_legal_precios')->value('valor'), true)) ? ['nota_legal' => $nota] : []),
                'lista' => DB::table('cms.planes_sitio')->orderBy('orden')
                    ->get(['clave', 'nombre', 'rango_texto', 'alumnos_max', 'precio_mensual', 'descripcion', 'caracteristicas', 'recomendado', 'visible', 'cta_texto'])
                    ->map(fn ($p) => [...(array) $p, 'caracteristicas' => json_decode($p->caracteristicas, true) ?: [], 'recomendado' => (bool) $p->recomendado, 'visible' => (bool) $p->visible])->all(),
                'complementos' => DB::table('cms.precios_complementos')->orderBy('orden')->get(['clave', 'etiqueta', 'precio', 'nota', 'visible'])
                    ->map(fn ($c) => [...(array) $c, 'visible' => (bool) $c->visible])->all(),
            ],
            'modulos' => [
                'lista' => DB::table('cms.modulos_web')->orderBy('orden')
                    ->get(['clave', 'nombre', 'icono', 'descripcion', 'etiqueta', 'imagen_id', 'en_grid', 'visible', 'descripcion_larga', 'beneficios'])
                    ->map(fn ($m) => [...(array) $m, 'en_grid' => (bool) $m->en_grid, 'visible' => (bool) $m->visible, 'beneficios' => json_decode((string) $m->beneficios, true) ?: []])->all(),
            ],
            'testimonios' => [
                'lista' => DB::table('cms.testimonios')->orderBy('orden')->orderBy('id')
                    ->get(['id', 'nombre', 'cargo', 'institucion', 'avatar_id', 'texto', 'calificacion', 'es_ejemplo', 'consentimiento_obtenido', 'consentimiento_fecha', 'consentimiento_doc_id', 'visible'])
                    ->map(fn ($t) => [
                        ...(array) $t,
                        'calificacion' => (int) $t->calificacion,
                        'es_ejemplo' => (bool) $t->es_ejemplo,
                        'consentimiento_obtenido' => (bool) $t->consentimiento_obtenido,
                        'visible' => (bool) $t->visible,
                    ])->all(),
            ],
            'logos' => [
                'mostrar' => (bool) DB::table('cms.secciones')->where('clave', 'logos')->value('visible'),
                'lista' => DB::table('cms.clientes')->orderBy('orden')->orderBy('id')
                    ->get(['id', 'nombre', 'logo_id', 'sitio_web', 'autorizacion_uso_logo', 'visible'])
                    ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'logo_id' => $c->logo_id, 'sitio_web' => $c->sitio_web, 'autorizado' => (bool) $c->autorizacion_uso_logo, 'visible' => (bool) $c->visible])->all(),
            ],
            '_estructura' => [
                'lista' => self::seccionesLanding()->orderBy('orden')->orderBy('id')
                    ->get(['clave', 'nombre', 'visible'])->map(fn ($x) => [...(array) $x, 'visible' => (bool) $x->visible])->all(),
            ],
            '_popup' => $this->popupPublicado(),
            '_config' => $this->configPublicada(),
            '_faq' => ['lista' => DB::table('cms.faq')->orderBy('orden')->orderBy('id')->get(['id', 'pregunta', 'respuesta', 'categoria', 'visible'])
                ->map(fn ($f) => [...(array) $f, 'visible' => (bool) $f->visible])->all()],
            '_chatbot' => self::chatbotPublicado(),
            default => [],
        };
    }

    private function popupPublicado(): array
    {
        $p = DB::table('cms.popup_config')->where('id', 1)->first();

        return $p ? [
            'activo' => (bool) $p->activo,
            'titulo' => $p->headline,
            'texto' => $p->subheadline,
            'imagen_id' => $p->imagen_id,
            'cta' => $p->cta_texto,
            'campo' => $p->capture_field,
            'espera_seg' => (int) $p->delay_seg,
            'inactividad_seg' => (int) $p->inactividad_seg,
            'frecuencia' => $p->frecuencia,
        ] : ['activo' => false, 'titulo' => '', 'texto' => null, 'imagen_id' => null, 'cta' => '', 'campo' => 'ambos', 'espera_seg' => 30, 'inactividad_seg' => 45, 'frecuencia' => 'sesion'];
    }

    private function configPublicada(): array
    {
        $c = DB::table('cms.config')->whereIn('clave', [...self::CONFIG_GENERAL, 'mantenimiento'])->pluck('valor', 'clave')
            ->map(fn ($v) => json_decode((string) $v, true));
        $m = $c->get('mantenimiento');

        return [
            ...collect(self::CONFIG_GENERAL)->mapWithKeys(fn ($k) => [$k => is_scalar($c->get($k)) ? (string) $c->get($k) : null])->all(),
            'mantenimiento' => [...self::MANTENIMIENTO_VACIO, ...(is_array($m) ? $m : [])],
        ];
    }

    /**
     * Medios que usan las listas (para mostrar miniaturas y nombres en el editor).
     *
     * @return array<int, array{id: int, tipo: string, nombre: string, url: string, alt: ?string}>
     */
    public function medios(array $contenido): array
    {
        $ids = collect($contenido['lista'] ?? [])->flatMap(fn ($x) => [$x['imagen_id'] ?? null, $x['avatar_id'] ?? null, $x['consentimiento_doc_id'] ?? null, $x['logo_id'] ?? null])
            ->push($contenido['imagen_id'] ?? null)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('cms.medios')->whereIn('id', $ids)->get(['id', 'tipo', 'nombre', 'url', 'alt'])
            ->mapWithKeys(fn ($m) => [$m->id => (array) $m])->all();
    }

    /** Reglas entre filas que el validador no expresa. */
    public function validar(string $clave, array $c): void
    {
        $errores = match ($clave) {
            'planes' => isset($c['lista']) || isset($c['complementos']) ? $this->erroresPlanes($c) : [],
            'modulos' => isset($c['lista']) ? $this->erroresModulos($c) : [],
            'testimonios' => isset($c['lista']) ? $this->erroresTestimonios($c) : [],
            'logos' => $this->erroresLogos($c),
            '_estructura' => $this->erroresEstructura($c),
            '_chatbot' => $this->erroresChatbot($c),
            '_popup' => ! empty($c['imagen_id']) && ! DB::table('cms.medios')->where('id', $c['imagen_id'])->whereIn('tipo', ['imagen', 'svg'])->exists()
                ? ['imagen_id' => 'Elige una imagen de la Biblioteca de medios.'] : [],
            default => [],
        };
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Pasa las listas del borrador a sus tablas y devuelve los textos para cms.secciones.config.
     */
    public function aplicar(string $clave, array $c, User $usuario): array
    {
        $this->validar($clave, $c);
        match ($clave) {
            'planes' => $this->aplicarPlanes($c, $usuario),
            'modulos' => $this->aplicarModulos($c, $usuario),
            'testimonios' => $this->aplicarTestimonios($c, $usuario),
            'logos' => $this->aplicarLogos($c),
            '_estructura' => $this->aplicarEstructura($c),
            '_popup' => $this->aplicarPopup($c),
            '_config' => $this->aplicarConfig($c),
            '_faq' => $this->aplicarFaq($c),
            '_chatbot' => $this->aplicarChatbot($c),
            default => null,
        };

        // Las secciones internas no guardan textos: todo vive en sus tablas.
        return self::esInterna($clave) ? [] : Arr::except($c, self::EXTRAS);
    }

    // --- Logos de clientes (EDUN-338) ----------------------------------------------------

    private function erroresLogos(array $c): array
    {
        $e = [];
        $lista = collect($c['lista'] ?? []);
        $logos = DB::table('cms.medios')->whereIn('id', $lista->pluck('logo_id')->filter())->whereIn('tipo', ['imagen', 'svg'])->pluck('id')->all();
        $existentes = DB::table('cms.clientes')->whereIn('id', $lista->pluck('id')->filter())->pluck('id')->all();
        foreach ($lista as $i => $l) {
            if (! in_array($l['logo_id'], $logos)) {
                $e["lista.{$i}.logo_id"] = 'Elige el logo (SVG o PNG) de la Biblioteca de medios.';
            }
            if (! empty($l['id']) && ! in_array($l['id'], $existentes)) {
                $e["lista.{$i}.id"] = 'Ese logo ya no existe. Recarga la página.';
            }
            // Un logo solo es visible con autorización de uso (CHECK de cms.clientes).
            if ($l['visible'] && ! $l['autorizado']) {
                $e["lista.{$i}.visible"] = 'Solo se muestra un logo con autorización de uso del colegio.';
            }
        }
        $visibles = isset($c['lista']) ? $lista->where('visible', true)->count() : DB::table('cms.clientes')->where('visible', true)->count();
        if (! empty($c['mostrar']) && $visibles === 0) {
            $e['mostrar'] = 'La sección necesita al menos un logo autorizado y visible.';
        }

        return $e;
    }

    private function aplicarLogos(array $c): void
    {
        if (isset($c['lista'])) {
            $lista = array_values($c['lista']);
            $conservar = collect($lista)->pluck('id')->filter()->all();
            DB::table('cms.clientes')->whereNotIn('id', $conservar ?: [0])->delete();
            foreach ($lista as $i => $l) {
                $fila = [
                    'nombre' => $l['nombre'],
                    'logo_id' => $l['logo_id'],
                    'sitio_web' => $l['sitio_web'] ?? null,
                    'autorizacion_uso_logo' => (bool) $l['autorizado'],
                    'visible' => (bool) $l['visible'],
                    'orden' => $i + 1,
                ];
                empty($l['id']) ? DB::table('cms.clientes')->insert($fila) : DB::table('cms.clientes')->where('id', $l['id'])->update($fila);
            }
        }
        if (array_key_exists('mostrar', $c)) {
            DB::table('cms.secciones')->where('clave', 'logos')->update(['visible' => (bool) $c['mostrar']]);
        }
    }

    // --- Orden de la landing (EDUN-387) --------------------------------------------------

    private function erroresEstructura(array $c): array
    {
        $existentes = self::seccionesLanding()->pluck('clave')->sort()->values()->all();
        $enviadas = collect($c['lista'] ?? [])->pluck('clave')->sort()->values()->all();
        if ($enviadas !== $existentes) {
            return ['lista' => 'La lista debe tener las mismas secciones: aquí se ordenan y se ocultan, no se crean ni se borran.'];
        }
        foreach ($c['lista'] as $i => $s) {
            if (in_array($s['clave'], self::NO_OCULTABLES, true) && ! $s['visible']) {
                return ["lista.{$i}.visible" => $s['clave'] === 'footer'
                    ? 'El footer no se puede ocultar: lleva el enlace obligatorio al Libro de Reclamaciones.'
                    : 'La portada (hero) no se puede ocultar.'];
            }
            if ($s['clave'] === 'logos' && $s['visible'] && ! DB::table('cms.clientes')->where('visible', true)->exists()) {
                return ["lista.{$i}.visible" => 'La sección de logos necesita al menos un logo autorizado y visible.'];
            }
        }

        return [];
    }

    private function aplicarEstructura(array $c): void
    {
        foreach (array_values($c['lista']) as $i => $s) {
            DB::table('cms.secciones')->where('clave', $s['clave'])->update(['orden' => $i + 1, 'visible' => (bool) $s['visible']]);
        }
    }

    // --- Popup de salida (EDUN-391) ------------------------------------------------------

    private function aplicarPopup(array $c): void
    {
        DB::table('cms.popup_config')->updateOrInsert(['id' => 1], [
            'activo' => (bool) $c['activo'],
            'headline' => $c['titulo'],
            'subheadline' => $c['texto'] ?? null,
            'imagen_id' => $c['imagen_id'] ?? null,
            'cta_texto' => $c['cta'],
            'capture_field' => $c['campo'],
            'delay_seg' => (int) $c['espera_seg'],
            'inactividad_seg' => (int) $c['inactividad_seg'],
            'frecuencia' => $c['frecuencia'],
        ]);
    }

    // --- Configuración general y mantenimiento (EDUN-399) --------------------------------

    private function aplicarConfig(array $c): void
    {
        foreach (self::CONFIG_GENERAL as $clave) {
            $valor = isset($c[$clave]) && trim((string) $c[$clave]) !== '' ? trim((string) $c[$clave]) : null;
            DB::table('cms.config')->updateOrInsert(['clave' => $clave], ['valor' => json_encode($valor, JSON_UNESCAPED_UNICODE)]);
        }
        $m = [...self::MANTENIMIENTO_VACIO, ...($c['mantenimiento'] ?? [])];
        DB::table('cms.config')->updateOrInsert(['clave' => 'mantenimiento'], ['valor' => json_encode([
            'activo' => (bool) $m['activo'],
            'mensaje' => $m['mensaje'] ?: null,
            'vuelta' => $m['vuelta'] ?: null,
        ], JSON_UNESCAPED_UNICODE)]);
    }

    // --- Planes (EDUN-326) ---------------------------------------------------------------

    private function erroresPlanes(array $c): array
    {
        $e = [];
        $lista = $c['lista'] ?? null;
        if ($lista !== null) {
            $existentes = DB::table('cms.planes_sitio')->pluck('clave')->all();
            foreach ($lista as $i => $p) {
                if (! in_array($p['clave'], $existentes, true)) {
                    $e["lista.{$i}.clave"] = 'Ese plan no existe.';
                }
            }
            if (collect($lista)->where('recomendado', true)->count() > 1) {
                $e['lista'] = 'Solo un plan puede marcarse como «Recomendado».';
            } elseif (collect($lista)->where('recomendado', true)->where('visible', false)->isNotEmpty()) {
                $e['lista'] = 'El plan recomendado debe estar visible.';
            } elseif (collect($lista)->where('visible', true)->isEmpty()) {
                $e['lista'] = 'Deja al menos un plan visible.';
            }
        }

        return $e;
    }

    private function aplicarPlanes(array $c, User $usuario): void
    {
        foreach (array_values($c['lista'] ?? []) as $i => $p) {
            DB::table('cms.planes_sitio')->where('clave', $p['clave'])->update([
                'nombre' => $p['nombre'],
                'rango_texto' => $p['rango_texto'],
                'alumnos_max' => $p['alumnos_max'] ?? null,
                'precio_mensual' => ($p['precio_mensual'] ?? '') === '' ? null : $p['precio_mensual'],
                'descripcion' => $p['descripcion'] ?? null,
                'caracteristicas' => json_encode(array_values($p['caracteristicas'] ?? []), JSON_UNESCAPED_UNICODE),
                'recomendado' => (bool) $p['recomendado'],
                'visible' => (bool) $p['visible'],
                'cta_texto' => $p['cta_texto'],
                'orden' => $i + 1,
                'estado' => 'publicado',
                'updated_by' => $usuario->id,
            ]);
        }
        foreach (array_values($c['complementos'] ?? []) as $i => $x) {
            DB::table('cms.precios_complementos')->updateOrInsert(['clave' => $x['clave']], [
                'etiqueta' => $x['etiqueta'],
                'precio' => ($x['precio'] ?? '') === '' ? null : $x['precio'],
                'nota' => $x['nota'] ?? null,
                'visible' => (bool) $x['visible'],
                'orden' => $i + 1,
                'updated_at' => now(),
            ]);
        }
        if (isset($c['nota_legal'])) {
            DB::table('cms.config')->updateOrInsert(['clave' => 'nota_legal_precios'], ['valor' => json_encode($c['nota_legal'], JSON_UNESCAPED_UNICODE)]);
        }
    }

    // --- Módulos (EDUN-330) --------------------------------------------------------------

    private function erroresModulos(array $c): array
    {
        $e = [];
        $lista = collect($c['lista'] ?? []);
        $existentes = DB::table('cms.modulos_web')->pluck('clave')->sort()->values()->all();
        if ($lista->pluck('clave')->sort()->values()->all() !== $existentes) {
            $e['lista'] = 'La lista debe tener los mismos módulos: aquí se ordenan y editan, no se crean ni se borran.';
        }
        $grid = $lista->where('en_grid', true);
        if ($grid->count() > self::MAX_GRID) {
            $e['lista'] = sprintf('El grid principal admite hasta %d módulos (marcaste %d).', self::MAX_GRID, $grid->count());
        }
        foreach ($lista as $i => $m) {
            if ($m['en_grid'] && ! $m['visible']) {
                $e["lista.{$i}.en_grid"] = 'Un módulo del grid principal debe estar visible.';
            }
        }
        $imagenes = $lista->pluck('imagen_id')->filter()->unique();
        $validas = DB::table('cms.medios')->whereIn('id', $imagenes)->whereIn('tipo', ['imagen', 'svg'])->pluck('id')->all();
        foreach ($lista as $i => $m) {
            if (! empty($m['imagen_id']) && ! in_array($m['imagen_id'], $validas)) {
                $e["lista.{$i}.imagen_id"] = 'Elige una imagen de la Biblioteca de medios.';
            }
        }

        return $e;
    }

    private function aplicarModulos(array $c, User $usuario): void
    {
        foreach (array_values($c['lista'] ?? []) as $i => $m) {
            DB::table('cms.modulos_web')->where('clave', $m['clave'])->update([
                'nombre' => $m['nombre'],
                'icono' => $m['icono'],
                'descripcion' => $m['descripcion'],
                'etiqueta' => $m['etiqueta'] ?? null,
                'imagen_id' => $m['imagen_id'] ?? null,
                'descripcion_larga' => isset($m['descripcion_larga']) && trim($m['descripcion_larga']) !== '' ? trim($m['descripcion_larga']) : null,
                'beneficios' => json_encode(array_values(array_filter(array_map(fn ($b) => trim((string) $b), $m['beneficios'] ?? []))), JSON_UNESCAPED_UNICODE),
                'en_grid' => (bool) $m['en_grid'],
                'visible' => (bool) $m['visible'],
                'orden' => $i + 1,
                'estado' => 'publicado',
                'updated_by' => $usuario->id,
            ]);
        }
    }

    // --- Testimonios (EDUN-334) ----------------------------------------------------------

    private function erroresTestimonios(array $c): array
    {
        $e = [];
        $lista = collect($c['lista'] ?? []);
        $ids = $lista->pluck('id')->filter();
        $existentes = DB::table('cms.testimonios')->whereIn('id', $ids)->pluck('id')->all();
        $docs = DB::table('cms.medios')->whereIn('id', $lista->pluck('consentimiento_doc_id')->filter())->where('tipo', 'pdf')->pluck('id')->all();
        $avatares = DB::table('cms.medios')->whereIn('id', $lista->pluck('avatar_id')->filter())->whereIn('tipo', ['imagen', 'svg'])->pluck('id')->all();

        foreach ($lista as $i => $t) {
            if (! empty($t['id']) && ! in_array($t['id'], $existentes)) {
                $e["lista.{$i}.id"] = 'Ese testimonio ya no existe. Recarga la página.';
            }
            if (! empty($t['consentimiento_doc_id']) && ! in_array($t['consentimiento_doc_id'], $docs)) {
                $e["lista.{$i}.consentimiento_doc_id"] = 'El consentimiento debe ser un PDF de la Biblioteca de medios.';
            }
            if (! empty($t['avatar_id']) && ! in_array($t['avatar_id'], $avatares)) {
                $e["lista.{$i}.avatar_id"] = 'Elige una imagen de la Biblioteca de medios.';
            }
            // Un testimonio real solo se publica con consentimiento firmado (Ley N.° 29733; CHECK de la base).
            if ($t['visible'] && ! $t['es_ejemplo']) {
                if (! $t['consentimiento_obtenido']) {
                    $e["lista.{$i}.consentimiento_obtenido"] = 'Para mostrar un testimonio real, marca que tienes el consentimiento firmado.';
                } elseif (empty($t['consentimiento_fecha'])) {
                    $e["lista.{$i}.consentimiento_fecha"] = 'Indica la fecha del consentimiento.';
                } elseif (empty($t['consentimiento_doc_id'])) {
                    $e["lista.{$i}.consentimiento_doc_id"] = 'Adjunta el documento de consentimiento (PDF).';
                }
            }
        }

        return $e;
    }

    private function aplicarTestimonios(array $c, User $usuario): void
    {
        $lista = array_values($c['lista'] ?? []);
        $conservar = collect($lista)->pluck('id')->filter()->all();
        DB::table('cms.testimonios')->whereNotIn('id', $conservar ?: [0])->delete();

        foreach ($lista as $i => $t) {
            $fila = [
                // Un testimonio de ejemplo nunca lleva el nombre de una persona.
                'nombre' => $t['es_ejemplo'] ? null : ($t['nombre'] ?? null),
                'cargo' => $t['cargo'],
                'institucion' => $t['institucion'],
                'avatar_id' => $t['avatar_id'] ?? null,
                'texto' => $t['texto'],
                'calificacion' => (int) $t['calificacion'],
                'es_ejemplo' => (bool) $t['es_ejemplo'],
                'consentimiento_obtenido' => (bool) $t['consentimiento_obtenido'],
                'consentimiento_fecha' => ! empty($t['consentimiento_fecha']) ? CarbonImmutable::parse($t['consentimiento_fecha'])->toDateString() : null,
                'consentimiento_doc_id' => $t['consentimiento_doc_id'] ?? null,
                'visible' => (bool) $t['visible'],
                'orden' => $i + 1,
                'estado' => 'publicado',
                'updated_by' => $usuario->id,
            ];
            if (! empty($t['id'])) {
                DB::table('cms.testimonios')->where('id', $t['id'])->update($fila);
            } else {
                DB::table('cms.testimonios')->insert($fila);
            }
        }
    }

    // --- Preguntas frecuentes (EDUN-371) -------------------------------------------------

    private function aplicarFaq(array $c): void
    {
        $lista = array_values($c['lista'] ?? []);
        $conservar = collect($lista)->pluck('id')->filter()->all();
        DB::table('cms.faq')->whereNotIn('id', $conservar ?: [0])->delete();
        foreach ($lista as $i => $f) {
            $fila = [
                'pregunta' => trim($f['pregunta']),
                'respuesta' => trim($f['respuesta']),
                'categoria' => isset($f['categoria']) && trim($f['categoria']) !== '' ? trim($f['categoria']) : null,
                'visible' => (bool) $f['visible'],
                'orden' => $i + 1,
            ];
            empty($f['id']) ? DB::table('cms.faq')->insert($fila) : DB::table('cms.faq')->where('id', $f['id'])->update($fila);
        }
    }

    // --- Chatbot (EDUN-375) --------------------------------------------------------------

    /** Configuración (cms.config.chatbot) y nodos del flujo activo, con los destinos como posiciones. */
    public static function chatbotPublicado(): array
    {
        $config = json_decode((string) DB::table('cms.config')->where('clave', 'chatbot')->value('valor'), true) ?: [];
        $flujo = DB::table('cms.chatbot_flujos')->where('activo', true)->value('id');
        $nodos = $flujo ? DB::table('cms.chatbot_nodos')->where('flujo_id', $flujo)->orderBy('orden')->get() : collect();
        $posicion = $nodos->pluck('id')->flip();

        return [
            ...self::CHATBOT_VACIO,
            ...array_intersect_key($config, self::CHATBOT_VACIO),
            'nodos' => $nodos->map(function ($n) use ($posicion) {
                $contenido = json_decode((string) $n->contenido, true) ?: [];

                return [
                    'tipo' => $n->tipo,
                    'texto' => $contenido['texto'] ?? '',
                    'url' => $contenido['url'] ?? null,
                    'siguiente' => $n->siguiente_id !== null ? ($posicion[$n->siguiente_id] ?? null) : null,
                    'opciones' => collect(json_decode((string) $n->opciones, true) ?: [])->map(fn ($o) => ['texto' => $o['texto'], 'destino' => $posicion[$o['destino_id']] ?? 0])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function erroresChatbot(array $c): array
    {
        $e = [];
        $nodos = $c['nodos'] ?? [];
        $total = count($nodos);
        if (($c['activo'] ?? false) && ($c['modo'] ?? '') === 'flujo' && $total === 0) {
            $e['nodos'] = 'El modo flujo necesita al menos un mensaje.';
        }
        foreach ($nodos as $i => $n) {
            if (isset($n['siguiente']) && $n['siguiente'] !== null && $n['siguiente'] >= $total) {
                $e["nodos.{$i}.siguiente"] = 'Ese paso no existe.';
            }
            foreach ($n['opciones'] ?? [] as $k => $o) {
                if ($o['destino'] >= $total) {
                    $e["nodos.{$i}.opciones.{$k}.destino"] = 'Ese paso no existe.';
                }
            }
            if ($n['tipo'] === 'opciones' && empty($n['opciones'])) {
                $e["nodos.{$i}.opciones"] = 'Agrega al menos una opción.';
            }
            if ($n['tipo'] === 'url' && empty($n['url'])) {
                $e["nodos.{$i}.url"] = 'Indica a qué página lleva.';
            }
        }
        if (($c['modo'] ?? '') === 'whatsapp' && ($c['activo'] ?? false) && ! $this->whatsappConfigurado()) {
            $e['modo'] = 'Primero carga el número de WhatsApp en Configuración general.';
        }

        return $e;
    }

    private function whatsappConfigurado(): bool
    {
        $w = json_decode((string) DB::table('cms.config')->where('clave', 'whatsapp')->value('valor'), true);

        return is_string($w) && strlen(preg_replace('/\D/', '', $w)) >= 9;
    }

    private function aplicarChatbot(array $c): void
    {
        DB::table('cms.config')->updateOrInsert(['clave' => 'chatbot'], ['valor' => json_encode(array_intersect_key($c, self::CHATBOT_VACIO), JSON_UNESCAPED_UNICODE)]);

        $flujo = DB::table('cms.chatbot_flujos')->where('activo', true)->value('id')
            ?? DB::table('cms.chatbot_flujos')->insertGetId(['nombre' => 'Principal', 'activo' => true]);
        DB::table('cms.chatbot_nodos')->where('flujo_id', $flujo)->delete();

        // Dos pasos: primero los nodos (para tener sus ids) y luego los enlaces entre ellos.
        $nodos = array_values($c['nodos'] ?? []);
        $ids = [];
        foreach ($nodos as $i => $n) {
            $ids[$i] = DB::table('cms.chatbot_nodos')->insertGetId([
                'flujo_id' => $flujo, 'tipo' => $n['tipo'], 'orden' => $i + 1,
                'contenido' => json_encode(array_filter(['texto' => $n['texto'], 'url' => $n['url'] ?? null]), JSON_UNESCAPED_UNICODE),
            ]);
        }
        foreach ($nodos as $i => $n) {
            DB::table('cms.chatbot_nodos')->where('id', $ids[$i])->update([
                'siguiente_id' => isset($n['siguiente']) && $n['siguiente'] !== null ? ($ids[$n['siguiente']] ?? null) : null,
                'opciones' => empty($n['opciones']) ? null : json_encode(array_map(fn ($o) => ['texto' => $o['texto'], 'destino_id' => $ids[$o['destino']] ?? null], $n['opciones']), JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
