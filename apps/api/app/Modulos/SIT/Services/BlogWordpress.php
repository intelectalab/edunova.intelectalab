<?php

namespace App\Modulos\SIT\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * EDUN-363 / SIT-005 · Blog desde WordPress.
 *
 * El sitio no habla con WordPress: la API lee su API REST (URL en Tracking e integraciones),
 * limpia el HTML y guarda listado y artículos en cms.blog_wp_cache / cms.categorias_blog.
 * Se resincroniza como mucho cada 5 minutos (mismo ISR que el sitio); si WordPress no responde,
 * se sirve lo último guardado. Sin URL configurada, el blog responde «configurado: false».
 */
class BlogWordpress
{
    public const POR_PAGINA = 9;

    public const SEGUNDOS_SINCRONIA = 300;

    /** Etiquetas permitidas en un artículo (sin scripts, estilos, iframes ni formularios). */
    private const ETIQUETAS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'code', 'pre'];

    public function configurado(): bool
    {
        return Integraciones::urlWordpress() !== null;
    }

    /** Trae lo nuevo de WordPress si pasaron más de 5 minutos desde la última vez. */
    public function sincronizar(bool $forzar = false): void
    {
        $base = Integraciones::urlWordpress();
        if (! $base || (! $forzar && ! Cache::add('edunova:blog:sincronizando', true, self::SEGUNDOS_SINCRONIA))) {
            return;
        }
        try {
            $categorias = Http::timeout(6)->acceptJson()->get("{$base}/wp-json/wp/v2/categories", ['per_page' => 100, '_fields' => 'id,name,slug'])->throw()->json();
            $posts = Http::timeout(8)->acceptJson()->get("{$base}/wp-json/wp/v2/posts", ['per_page' => 50, 'status' => 'publish', '_embed' => 1])->throw()->json();
        } catch (Throwable $e) {
            Log::warning('Blog: WordPress no respondió; se sirve lo guardado.', ['error' => class_basename($e)]);

            return;
        }

        $categorias = array_filter((array) $categorias, fn ($c) => is_array($c) && isset($c['id'], $c['name'], $c['slug']));
        $posts = array_filter((array) $posts, fn ($p) => is_array($p) && isset($p['id'], $p['slug']));
        $this->comoSoporte(function () use ($categorias, $posts, $base) {
            foreach ($categorias as $c) {
                DB::table('cms.categorias_blog')->updateOrInsert(['wp_cat_id' => (int) $c['id']], ['nombre' => Str::limit(html_entity_decode((string) $c['name']), 80, ''), 'slug' => Str::limit((string) $c['slug'], 80, '')]);
            }
            $mapa = DB::table('cms.categorias_blog')->pluck('id', 'wp_cat_id');
            $vistos = [];
            foreach ($posts as $p) {
                $vistos[] = (int) $p['id'];
                $imagen = $p['_embedded']['wp:featuredmedia'][0]['source_url'] ?? null;
                DB::table('cms.blog_wp_cache')->updateOrInsert(['wp_post_id' => (int) $p['id']], [
                    'slug' => Str::limit((string) $p['slug'], 200, ''),
                    'titulo' => Str::limit(trim(html_entity_decode(strip_tags((string) ($p['title']['rendered'] ?? '')))), 250, ''),
                    'resumen' => Str::limit(trim(html_entity_decode(strip_tags((string) ($p['excerpt']['rendered'] ?? '')))), 400),
                    'contenido' => $this->limpiar((string) ($p['content']['rendered'] ?? ''), $base),
                    'imagen_url' => is_string($imagen) && str_starts_with($imagen, 'https://') ? $imagen : null,
                    'categoria_id' => $mapa[(int) ($p['categories'][0] ?? 0)] ?? null,
                    'autor_wp' => Str::limit((string) ($p['_embedded']['author'][0]['name'] ?? ''), 120, '') ?: null,
                    'publicado_en' => isset($p['date_gmt']) ? CarbonImmutable::parse($p['date_gmt'].'Z') : null,
                    'actualizado_wp' => isset($p['modified_gmt']) ? CarbonImmutable::parse($p['modified_gmt'].'Z') : null,
                    'wp_url' => $p['link'] ?? null,
                    'synced_at' => now(),
                ]);
            }
            // Lo despublicado en WordPress deja de verse. Lo que el editor ocultó en el CMS se conserva
            // (oculto) para que, si vuelve a publicarse, no reaparezca sin su revisión (EDUN-368).
            DB::table('cms.blog_wp_cache')->whereNotIn('wp_post_id', $vistos ?: [0])->where('oculto', false)->delete();
        });
    }

    /** @param  array{categoria?: ?string, pagina?: int}  $f */
    public function listado(array $f): array
    {
        $this->sincronizar();
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $q = DB::table('cms.blog_wp_cache as b')->leftJoin('cms.categorias_blog as c', 'c.id', '=', 'b.categoria_id')->where('b.oculto', false)
            ->when(! empty($f['categoria']), fn ($q) => $q->where('c.slug', $f['categoria']));
        $total = (clone $q)->count();

        return [
            'configurado' => $this->configurado(),
            'categorias' => DB::table('cms.categorias_blog as c')->whereExists(fn ($s) => $s->from('cms.blog_wp_cache as b')->whereColumn('b.categoria_id', 'c.id')->where('b.oculto', false))
                ->orderBy('c.nombre')->get(['c.slug', 'c.nombre']),
            'data' => $q->orderByDesc('b.publicado_en')->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
                ->get(['b.slug', 'b.titulo', 'b.resumen', 'b.imagen_url', 'b.publicado_en', 'b.autor_wp', 'c.slug as categoria', 'c.nombre as categoria_nombre']),
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
        ];
    }

    public function articulo(string $slug): ?array
    {
        $this->sincronizar();
        $a = DB::table('cms.blog_wp_cache as b')->leftJoin('cms.categorias_blog as c', 'c.id', '=', 'b.categoria_id')->where('b.slug', $slug)->where('b.oculto', false)
            ->first(['b.id', 'b.slug', 'b.titulo', 'b.resumen', 'b.contenido', 'b.imagen_url', 'b.publicado_en', 'b.actualizado_wp', 'b.autor_wp', 'b.categoria_id', 'c.slug as categoria', 'c.nombre as categoria_nombre']);
        if (! $a) {
            return null;
        }
        $relacionados = DB::table('cms.blog_wp_cache')->where('id', '<>', $a->id)->where('oculto', false)
            ->when($a->categoria_id, fn ($q) => $q->where('categoria_id', $a->categoria_id))
            ->orderByDesc('publicado_en')->limit(3)->get(['slug', 'titulo', 'resumen', 'imagen_url', 'publicado_en']);

        return [...collect((array) $a)->except(['id', 'categoria_id'])->all(), 'relacionados' => $relacionados];
    }

    /** HTML de WordPress → solo etiquetas de texto, imágenes https y enlaces seguros. */
    public function limpiar(string $html, string $base = ''): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="raiz">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $raiz = $doc->getElementById('raiz') ?? $doc->documentElement;
        $this->limpiarNodo($raiz, $base);
        $salida = '';
        foreach (iterator_to_array($raiz->childNodes) as $hijo) {
            $salida .= $doc->saveHTML($hijo);
        }

        return trim($salida);
    }

    private function limpiarNodo(DOMNode $nodo, string $base): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if (! $hijo instanceof DOMElement) {
                if ($hijo->nodeType === XML_COMMENT_NODE || $hijo->nodeType === XML_PI_NODE) {
                    $nodo->removeChild($hijo);
                }

                continue;
            }
            $etiqueta = strtolower($hijo->tagName);
            if (in_array($etiqueta, ['script', 'style', 'iframe', 'object', 'embed', 'template', 'form', 'input', 'button', 'svg', 'noscript'], true)) {
                $nodo->removeChild($hijo);

                continue;
            }
            $this->limpiarNodo($hijo, $base);
            if (! in_array($etiqueta, self::ETIQUETAS, true)) {
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);

                continue;
            }
            $conservar = [];
            if ($etiqueta === 'a') {
                $href = trim($hijo->getAttribute('href'));
                if (preg_match('#^(https://|mailto:|/(?!/))#i', $href)) {
                    $conservar = ['href' => $href, 'rel' => 'noopener nofollow', 'target' => '_blank'];
                    if ($base !== '' && str_starts_with($href, $base)) {
                        unset($conservar['target'], $conservar['rel']);
                    }
                }
            } elseif ($etiqueta === 'img') {
                $src = trim($hijo->getAttribute('src'));
                if (! str_starts_with($src, 'https://')) {
                    $nodo->removeChild($hijo);

                    continue;
                }
                $conservar = ['src' => $src, 'alt' => Str::limit($hijo->getAttribute('alt'), 250, ''), 'loading' => 'lazy'];
                foreach (['width', 'height'] as $m) {
                    if (ctype_digit($hijo->getAttribute($m))) {
                        $conservar[$m] = $hijo->getAttribute($m);
                    }
                }
            }
            foreach (iterator_to_array($hijo->attributes) as $atributo) {
                $hijo->removeAttribute($atributo->nodeName);
            }
            foreach ($conservar as $k => $v) {
                $hijo->setAttribute($k, $v);
            }
        }
    }

    private function comoSoporte(callable $f): void
    {
        DB::transaction(function () use ($f) {
            DB::select("select set_config('app.rol_cms', 'superadmin', true)");
            $f();
        });
    }
}
