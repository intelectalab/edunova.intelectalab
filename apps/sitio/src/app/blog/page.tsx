import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { fechaArticulo, leerBlog } from '@/lib/blog';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';

// EDUN-363 / SIT-005 · Listado del blog con filtro por categoría (artículos de WordPress que la
// API ya limpió). Sin artículos aún: un aviso, y la página no se indexa.
export const dynamic = 'force-dynamic';

const t = paginas.blog;

export async function generateMetadata() {
  const datos = await leerBlog();
  return metadatosPagina(t.titulo, t.descripcion, '/blog', (datos?.data.length ?? 0) > 0);
}

export default async function Blog({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const sp = await searchParams;
  const [datos, blog] = await Promise.all([obtenerLanding(), leerBlog(sp.categoria, sp.pagina)]);
  const articulos = blog?.data ?? [];
  const url = (cambios: Record<string, string | undefined>) => {
    const p = new URLSearchParams(Object.entries({ categoria: sp.categoria, ...cambios }).filter((e): e is [string, string] => Boolean(e[1])));
    return `/blog${p.toString() ? `?${p}` : ''}`;
  };

  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={t.titulo} descripcion={t.descripcion} ruta="/blog" />
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-lg">
        {(blog?.categorias.length ?? 0) > 0 && (
          <nav aria-label={t.categorias} className="flex flex-wrap gap-space-xs">
            {[{ slug: '', nombre: t.todas }, ...blog!.categorias].map((c) => (
              <Link
                key={c.slug || 'todas'}
                href={url({ categoria: c.slug || undefined, pagina: undefined })}
                aria-current={(sp.categoria ?? '') === c.slug ? 'page' : undefined}
                className={`px-space-md py-space-xs rounded-full font-label-md text-label-md ${(sp.categoria ?? '') === c.slug ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-primary hover:bg-surface-container-high'}`}
              >
                {c.nombre}
              </Link>
            ))}
          </nav>
        )}

        {articulos.length === 0 ? (
          <p className="font-body-lg text-body-lg text-on-surface-variant" data-blog-vacio>
            {t.sinArticulos}
          </p>
        ) : (
          <ul className="grid gap-space-md sm:grid-cols-2 lg:grid-cols-3">
            {articulos.map((a) => (
              <li key={a.slug}>
                <article className="h-full flex flex-col rounded-2xl overflow-hidden bg-surface-container-lowest border border-surface-container">
                  {a.imagen_url && (
                    // eslint-disable-next-line @next/next/no-img-element -- imágenes del blog en WordPress (dominio variable)
                    <img src={a.imagen_url} alt="" loading="lazy" className="w-full h-44 object-cover" />
                  )}
                  <div className="flex flex-col gap-space-xs p-space-md flex-1">
                    {a.categoria_nombre && <span className="font-label-sm text-label-sm text-accion uppercase tracking-wider">{a.categoria_nombre}</span>}
                    <h2 className="font-title-lg text-title-lg text-primary">
                      <Link href={`/blog/${a.slug}`} aria-label={t.leer(a.titulo)} className="hover:underline">
                        {a.titulo}
                      </Link>
                    </h2>
                    {a.resumen && <p className="font-body-sm text-body-sm text-secondary line-clamp-3">{a.resumen}</p>}
                    <span className="mt-auto font-label-sm text-label-sm text-on-surface-variant">{fechaArticulo(a.publicado_en)}</span>
                  </div>
                </article>
              </li>
            ))}
          </ul>
        )}

        {blog && blog.paginas > 1 && (
          <nav aria-label="Paginación" className="flex justify-between">
            {blog.pagina > 1 ? (
              <Link href={url({ pagina: String(blog.pagina - 1) })} className="inline-flex items-center gap-1 font-label-lg text-label-lg text-primary">
                <Icono nombre="chevron_left" tamano="sm" />
                {t.anterior}
              </Link>
            ) : (
              <span />
            )}
            {blog.pagina < blog.paginas && (
              <Link href={url({ pagina: String(blog.pagina + 1) })} className="inline-flex items-center gap-1 font-label-lg text-label-lg text-primary">
                {t.siguiente}
                <Icono nombre="chevron_right" tamano="sm" />
              </Link>
            )}
          </nav>
        )}
      </div>
    </MarcoPublico>
  );
}
