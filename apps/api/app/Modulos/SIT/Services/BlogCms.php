<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EDUN-368 / SIT-023 · Previsualización del blog en el CMS.
 *
 * Los posts se escriben en WordPress (no hay editor en el CMS). Aquí el editor ve la lista de lo
 * publicado con su estado y fecha, lo previsualiza tal como saldrá en el sitio, lo abre en
 * wp-admin para corregirlo y decide si se muestra o se oculta en el sitio. Ocultar o volver a
 * mostrar publica al instante: deja versión, auditoría y revalida /blog.
 * Corre dentro de la transacción del middleware «cms» (app.rol_cms = superadmin).
 */
class BlogCms
{
    public function __construct(private BlogWordpress $blog, private EditorCms $editor) {}

    public function listado(): array
    {
        $base = Integraciones::urlWordpress();

        return [
            'configurado' => $base !== null,
            'wp_admin' => $base ? "{$base}/wp-admin/" : null,
            'ultima_sincronia' => DB::table('cms.blog_wp_cache')->max('synced_at'),
            'data' => DB::table('cms.blog_wp_cache as b')->leftJoin('cms.categorias_blog as c', 'c.id', '=', 'b.categoria_id')
                ->orderByDesc('b.publicado_en')->limit(200)
                ->get(['b.wp_post_id', 'b.slug', 'b.titulo', 'b.resumen', 'b.imagen_url', 'b.publicado_en', 'b.actualizado_wp', 'b.autor_wp', 'b.oculto', 'b.revisado_at', 'b.wp_url', 'c.nombre as categoria_nombre'])
                ->map(fn ($p) => [
                    ...(array) $p,
                    'oculto' => (bool) $p->oculto,
                    'estado' => $p->oculto ? 'oculto' : 'publicado',
                    // Cambió en WordPress después de la última revisión en el CMS.
                    'cambio_sin_revisar' => $p->revisado_at !== null && $p->actualizado_wp !== null && $p->actualizado_wp > $p->revisado_at,
                    'editar_url' => $base ? "{$base}/wp-admin/post.php?post={$p->wp_post_id}&action=edit" : null,
                ])->all(),
        ];
    }

    /** El artículo tal como se verá en el sitio (mismo HTML limpio), aunque esté oculto. */
    public function vistaPrevia(string $slug): array
    {
        $a = DB::table('cms.blog_wp_cache as b')->leftJoin('cms.categorias_blog as c', 'c.id', '=', 'b.categoria_id')->where('b.slug', $slug)
            ->first(['b.wp_post_id', 'b.slug', 'b.titulo', 'b.resumen', 'b.contenido', 'b.imagen_url', 'b.publicado_en', 'b.actualizado_wp', 'b.autor_wp', 'b.oculto', 'b.revisado_at', 'c.nombre as categoria_nombre'])
            ?? abort(404, 'No encontramos ese artículo. Sincroniza con WordPress.');
        $base = Integraciones::urlWordpress();

        return [...(array) $a, 'oculto' => (bool) $a->oculto, 'editar_url' => $base ? "{$base}/wp-admin/post.php?post={$a->wp_post_id}&action=edit" : null];
    }

    /** Trae lo último de WordPress ahora (sin esperar los 5 minutos). */
    public function sincronizar(User $usuario, ?string $ip): array
    {
        $antes = DB::table('cms.blog_wp_cache')->count();
        $this->blog->sincronizar(true);
        $despues = DB::table('cms.blog_wp_cache')->count();
        $this->auditar($usuario, $ip, 'editado', 'sincronizacion', 'Blog · sincronizado con WordPress', ['posts' => $antes], ['posts' => $despues]);
        DB::afterCommit(fn () => $this->editor->revalidarSitio(['blog']));

        return $this->listado();
    }

    /** Mostrar u ocultar en el sitio: se publica al instante. También marca el post como revisado. */
    public function visibilidad(string $slug, bool $oculto, User $usuario, ?string $ip): array
    {
        $antes = $this->vistaPrevia($slug);
        DB::table('cms.blog_wp_cache')->where('slug', $slug)->update(['oculto' => $oculto, 'revisado_at' => now(), 'revisado_por' => $usuario->id]);
        $despues = $this->vistaPrevia($slug);
        $resumen = fn (array $p) => ['titulo' => $p['titulo'], 'oculto' => $p['oculto']];
        DB::table('cms.versiones')->insert(['seccion' => 'blog', 'entidad' => 'blog_wp_cache', 'entidad_id' => $slug,
            'contenido' => json_encode($resumen($despues), JSON_UNESCAPED_UNICODE), 'publicado_por' => $usuario->id]);
        $this->auditar($usuario, $ip, $oculto ? 'ocultado' : 'publicado', $slug, ($oculto ? 'Blog · ocultado: ' : 'Blog · visible: ').$despues['titulo'], $resumen($antes), $resumen($despues));
        DB::afterCommit(fn () => $this->editor->revalidarSitio(['blog']));

        return $despues;
    }

    private function auditar(User $usuario, ?string $ip, string $accion, string $id, string $detalle, mixed $antes, mixed $despues): void
    {
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id, 'usuario_rol' => 'superadmin', 'seccion' => 'blog', 'accion' => $accion,
            'entidad' => 'blog_wp_cache', 'entidad_id' => Str::limit($id, 60, ''), 'detalle' => Str::limit($detalle, 250, ''),
            'valor_antes' => json_encode($antes, JSON_UNESCAPED_UNICODE), 'valor_despues' => json_encode($despues, JSON_UNESCAPED_UNICODE), 'ip' => $ip,
        ]);
    }
}
