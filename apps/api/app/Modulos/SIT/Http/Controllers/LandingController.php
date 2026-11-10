<?php

namespace App\Modulos\SIT\Http\Controllers;

use App\Modulos\SIT\Services\ContenidoEntidades;
use App\Modulos\SIT\Services\Integraciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/sitio/landing · Contenido publicado y visible de la landing (TRASPASO §8).
 * Lo usa Next.js con caché de 5 minutos. Nunca devuelve borradores (EDUN-233).
 */
class LandingController
{
    /** Datos de cms.config que el sitio muestra. El resto (CRM, tracking…) no sale de la API. */
    private const CONFIG_PUBLICA = [
        'razon_social', 'ruc', 'direccion', 'telefono_central', 'whatsapp', 'email_contacto', 'horario_soporte',
        'nota_legal_precios', 'descuento_anual_pct', 'selector_anual_visible', 'testimonios_solo_4_estrellas',
        // EDUN-399 · Modo mantenimiento (activo, mensaje y fecha estimada de vuelta).
        'mantenimiento',
    ];

    public function __invoke(): JsonResponse
    {
        return DB::transaction(function () {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            $config = DB::table('cms.config')->whereIn('clave', [...self::CONFIG_PUBLICA, 'oferta_migracion'])->get(['clave', 'valor'])
                ->mapWithKeys(fn ($c) => [$c->clave => json_decode($c->valor, true)]);

            return response()->json([
                'secciones' => $this->secciones(),
                'config' => $config->only(self::CONFIG_PUBLICA),
                'menu' => $this->menu(),
                'modulos' => $this->modulos(),
                'planes' => $this->planes(),
                'complementos' => DB::table('cms.precios_complementos')->where('visible', true)->orderBy('orden')->get(['clave', 'etiqueta', 'precio', 'nota']),
                'oferta' => $this->oferta($config->get('oferta_migracion')),
                'contadores' => DB::table('cms.contadores')->where('visible', true)->orderBy('orden')->get(['label', 'valor', 'prefijo', 'sufijo'])
                    ->map(fn ($c) => ['label' => $c->label, 'valor' => (float) $c->valor, 'prefijo' => $c->prefijo, 'sufijo' => $c->sufijo]),
                'videos' => $this->videos(),
                'testimonios' => $this->testimonios((bool) $config->get('testimonios_solo_4_estrellas')),
                'seo' => $this->seo('inicio'),
                // EDUN-371 · Todas las visibles, con su categoría (página /preguntas-frecuentes).
                'faq' => DB::table('cms.faq')->where('visible', true)->orderBy('orden')->limit(100)->get(['pregunta', 'respuesta', 'categoria']),
                'tracking' => $this->tracking(),
                'popup' => $this->popup(),
                'logos' => $this->logos(),
                // EDUN-347 · Videos por módulo para /funcionalidades.
                'videos_modulos' => $this->videosModulos(),
                // EDUN-375 · Chatbot (configuración pública y flujo).
                'chatbot' => $this->chatbot($config->get('whatsapp')),
            ])->header('Cache-Control', 'public, max-age=60');
        });
    }

    private function secciones(): Collection
    {
        return DB::table('cms.secciones')->where('visible', true)->orderBy('orden')
            ->get(['clave', 'config'])
            ->mapWithKeys(fn ($s) => [$s->clave => json_decode($s->config, true)]);
    }

    private function menu(): array
    {
        $menu = DB::table('cms.menu_items')->where('visible', true)->orderBy('ubicacion')->orderBy('orden')
            ->get(['ubicacion', 'columna', 'label', 'url', 'target']);

        return [
            'principal' => $menu->where('ubicacion', 'principal')->values(),
            'footer' => $menu->where('ubicacion', 'footer')->values(),
        ];
    }

    /** EDUN-251 · Módulos publicados y visibles, en el orden del CMS, con su imagen. */
    private function modulos(): Collection
    {
        return DB::table('cms.modulos_web as m')
            ->leftJoin('cms.medios as i', 'i.id', '=', 'm.imagen_id')
            ->where('m.visible', true)->where('m.estado', 'publicado')
            ->orderBy('m.orden')
            ->get(['m.clave', 'm.nombre', 'm.icono', 'm.descripcion', 'm.etiqueta', 'm.en_grid', 'm.descripcion_larga', 'm.beneficios', 'i.url', 'i.alt', 'i.ancho', 'i.alto'])
            ->map(fn ($m) => [
                'clave' => $m->clave,
                'nombre' => $m->nombre,
                'icono' => $m->icono,
                'descripcion' => $m->descripcion,
                'etiqueta' => $m->etiqueta,
                'en_grid' => (bool) $m->en_grid,
                'descripcion_larga' => $m->descripcion_larga,
                'beneficios' => json_decode((string) $m->beneficios, true) ?: [],
                'imagen' => $m->url ? ['url' => $m->url, 'alt' => $m->alt, 'ancho' => $m->ancho, 'alto' => $m->alto] : null,
            ]);
    }

    /** EDUN-260 · Planes publicados. El precio va como texto decimal (docs/13: nunca float). */
    private function planes(): Collection
    {
        return DB::table('cms.planes_sitio')->where('visible', true)->where('estado', 'publicado')->orderBy('orden')
            ->get(['clave', 'nombre', 'rango_texto', 'precio_mensual', 'descripcion', 'caracteristicas', 'recomendado', 'cta_texto'])
            ->map(fn ($p) => [
                'clave' => $p->clave,
                'nombre' => $p->nombre,
                'rango_texto' => $p->rango_texto,
                'precio_mensual' => $p->precio_mensual,
                'descripcion' => $p->descripcion,
                'caracteristicas' => json_decode($p->caracteristicas, true),
                'recomendado' => (bool) $p->recomendado,
                'cta_texto' => $p->cta_texto,
            ]);
    }

    /** Banner de oferta de migración: solo si está visible y no venció (fecha de Lima). */
    private function oferta(mixed $oferta): ?array
    {
        if (! is_array($oferta) || empty($oferta['visible'])) {
            return null;
        }
        if (! empty($oferta['expira']) && now('America/Lima')->toDateString() > $oferta['expira']) {
            return null;
        }

        return ['titulo' => $oferta['titulo'] ?? null, 'texto' => $oferta['texto'] ?? '', 'cta' => $oferta['cta'] ?? null];
    }

    /**
     * EDUN-306 / EDUN-403 · IDs de tracking válidos y activos (GTM, GA4, Meta, LinkedIn, Clarity y
     * la verificación de Search Console). Son públicos (van en el HTML); el sitio solo carga cada
     * etiqueta con el consentimiento de cookies que corresponde (Consent Mode v2).
     */
    private function tracking(): array
    {
        return Integraciones::trackingPublico();
    }

    /** EDUN-271 · Popup de salida: textos, qué dato pide y cuándo se muestra (null si está apagado). */
    private function popup(): ?array
    {
        $p = DB::table('cms.popup_config as p')->leftJoin('cms.medios as m', 'm.id', '=', 'p.imagen_id')->where('p.id', 1)->where('p.activo', true)
            ->first(['p.headline', 'p.subheadline', 'p.cta_texto', 'p.capture_field', 'p.delay_seg', 'p.inactividad_seg', 'p.frecuencia', 'm.url as imagen', 'm.alt as imagen_alt']);

        return $p ? [
            'imagen' => $p->imagen ? ['url' => $p->imagen, 'alt' => $p->imagen_alt ?? ''] : null,
            'titulo' => $p->headline,
            'texto' => $p->subheadline,
            'cta' => $p->cta_texto,
            'campo' => $p->capture_field,
            'espera_seg' => (int) $p->delay_seg,
            'inactividad_seg' => (int) $p->inactividad_seg,
            'frecuencia' => $p->frecuencia,
        ] : null;
    }

    /** EDUN-347 · Videos visibles asociados a un módulo. */
    private function videosModulos(): Collection
    {
        return DB::table('cms.videos as v')->leftJoin('cms.medios as m', 'm.id', '=', 'v.thumbnail_id')
            ->where('v.visible', true)->whereNotNull('v.modulo_clave')->orderBy('v.orden')
            ->get(['v.id', 'v.modulo_clave', 'v.titulo', 'v.descripcion', 'v.proveedor', 'v.embed_url', 'v.duracion_seg', 'm.url as miniatura', 'm.alt as miniatura_alt']);
    }

    /** EDUN-375 · Chatbot: null si está apagado; el número de WhatsApp sale de Configuración general. */
    private function chatbot(mixed $whatsapp): ?array
    {
        $c = ContenidoEntidades::chatbotPublicado();
        if (! $c['activo']) {
            return null;
        }
        $numero = preg_replace('/\D/', '', is_string($whatsapp) ? $whatsapp : '');
        $numero = strlen($numero) === 9 ? "51{$numero}" : $numero;

        return [...$c, 'whatsapp' => strlen($numero) >= 9 ? $numero : null];
    }

    /** EDUN-338 · Logos autorizados y visibles («Confían en Edunova»), en el orden del CMS. */
    private function logos(): Collection
    {
        return DB::table('cms.clientes as c')->join('cms.medios as m', 'm.id', '=', 'c.logo_id')
            ->where('c.visible', true)->where('c.autorizacion_uso_logo', true)->orderBy('c.orden')->orderBy('c.id')
            ->get(['c.nombre', 'c.sitio_web', 'm.url', 'm.ancho', 'm.alto'])
            ->map(fn ($l) => ['nombre' => $l->nombre, 'sitio_web' => $l->sitio_web, 'logo' => ['url' => $l->url, 'ancho' => $l->ancho, 'alto' => $l->alto]]);
    }

    /** EDUN-311 · SEO publicado de una página, con la imagen Open Graph de la Biblioteca. */
    private function seo(string $slug): ?array
    {
        $p = DB::table('cms.seo_paginas as s')->leftJoin('cms.medios as m', 'm.id', '=', 's.og_imagen_id')
            ->where('s.slug', $slug)->where('s.estado', 'publicado')
            ->first(['s.meta_titulo', 's.meta_descripcion', 's.canonical_url', 's.robots', 's.keywords', 's.og_titulo', 's.og_descripcion', 'm.url as og_imagen', 'm.alt as og_imagen_alt', 'm.ancho', 'm.alto']);
        if (! $p) {
            return null;
        }

        return [
            'titulo' => $p->meta_titulo,
            'descripcion' => $p->meta_descripcion,
            'canonical' => $p->canonical_url,
            'robots' => $p->robots,
            'keywords' => $p->keywords ? array_values(array_filter(str_getcsv(trim($p->keywords, '{}')))) : [],
            'og_titulo' => $p->og_titulo,
            'og_descripcion' => $p->og_descripcion,
            'og_imagen' => $p->og_imagen ? ['url' => $p->og_imagen, 'alt' => $p->og_imagen_alt, 'ancho' => $p->ancho, 'alto' => $p->alto] : null,
        ];
    }

    /** EDUN-254 · Hasta 3 videos de la landing, con su miniatura. */
    private function videos(): Collection
    {
        return DB::table('cms.videos as v')->leftJoin('cms.medios as m', 'm.id', '=', 'v.thumbnail_id')
            ->where('v.visible', true)->where('v.en_landing', true)->orderBy('v.orden')->limit(3)
            ->get(['v.id', 'v.titulo', 'v.descripcion', 'v.proveedor', 'v.embed_url', 'v.duracion_seg', 'm.url as miniatura', 'm.alt as miniatura_alt']);
    }

    /**
     * EDUN-257 · Testimonios publicados: los de ejemplo o los reales con consentimiento completo
     * (la base ya impide publicar uno real sin consentimiento). El nombre solo sale si es real.
     */
    private function testimonios(bool $solo4): Collection
    {
        return DB::table('cms.testimonios as t')->leftJoin('cms.medios as m', 'm.id', '=', 't.avatar_id')
            ->where('t.visible', true)->where('t.estado', 'publicado')
            ->where(fn ($q) => $q->where('t.es_ejemplo', true)->orWhere(fn ($r) => $r->where('t.consentimiento_obtenido', true)
                ->whereNotNull('t.consentimiento_fecha')->whereNotNull('t.consentimiento_doc_id')))
            ->when($solo4, fn ($q) => $q->where('t.calificacion', '>=', 4))
            ->orderBy('t.orden')->limit(6)
            ->get(['t.id', 't.nombre', 't.cargo', 't.institucion', 't.texto', 't.calificacion', 't.es_ejemplo', 'm.url as avatar'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'nombre' => $t->es_ejemplo ? null : $t->nombre,
                'cargo' => $t->cargo,
                'institucion' => $t->institucion,
                'texto' => $t->texto,
                'calificacion' => (int) $t->calificacion,
                'es_ejemplo' => (bool) $t->es_ejemplo,
                'avatar' => $t->avatar,
            ]);
    }
}
