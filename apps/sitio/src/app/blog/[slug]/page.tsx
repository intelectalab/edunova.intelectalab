import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { fechaArticulo, leerArticulo } from '@/lib/blog';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina, scriptJsonLd, URL_SITIO } from '@/lib/seo';

// EDUN-363 / SIT-005 · Artículo del blog con schema Article (las migas ya llevan BreadcrumbList)
// y artículos relacionados de la misma categoría. El HTML viene limpio desde la API.
export const dynamic = 'force-dynamic';

const t = paginas.blog;

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const a = await leerArticulo((await params).slug);
  if (!a) return metadatosPagina(t.titulo, t.descripcion, '/blog', false);
  const m = metadatosPagina(a.titulo, a.resumen ?? t.descripcion, `/blog/${a.slug}`);
  return { ...m, openGraph: { ...m.openGraph, type: 'article', images: a.imagen_url ? [{ url: a.imagen_url }] : undefined } };
}

export default async function ArticuloBlog({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [datos, a] = await Promise.all([obtenerLanding(), leerArticulo(slug)]);
  if (!a) notFound();
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: a.titulo,
    description: a.resumen ?? undefined,
    image: a.imagen_url ? [a.imagen_url] : undefined,
    datePublished: a.publicado_en ?? undefined,
    dateModified: a.actualizado_wp ?? a.publicado_en ?? undefined,
    author: a.autor_wp ? { '@type': 'Person', name: a.autor_wp } : { '@type': 'Organization', name: 'Edunova' },
    publisher: { '@type': 'Organization', name: 'Edunova' },
    mainEntityOfPage: `${URL_SITIO}/blog/${a.slug}`,
  };

  return (
    <MarcoPublico datos={datos} comercial>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJsonLd(jsonLd) }} />
      <article className="flex flex-col gap-space-lg">
        <EncabezadoPagina titulo={a.titulo} ruta={`/blog/${a.slug}`} migas={[{ nombre: t.titulo, ruta: '/blog' }]}>
          <p className="font-label-md text-label-md text-secondary">
            {[a.categoria_nombre, fechaArticulo(a.publicado_en), a.autor_wp ? t.por(a.autor_wp) : null].filter(Boolean).join(' · ')}
          </p>
        </EncabezadoPagina>
        <div className="max-w-3xl w-full mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-md">
          {a.imagen_url && (
            // eslint-disable-next-line @next/next/no-img-element -- imagen destacada del artículo (WordPress)
            <img src={a.imagen_url} alt="" className="w-full rounded-2xl object-cover" />
          )}
          <div
            className="articulo font-body-lg text-body-lg text-on-surface [&_h2]:font-headline-md [&_h2]:text-headline-md [&_h2]:text-primary [&_h2]:mt-space-lg [&_h3]:font-title-lg [&_h3]:text-title-lg [&_h3]:mt-space-md [&_p]:my-space-sm [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-6 [&_ol]:pl-6 [&_a]:text-primary [&_a]:underline [&_img]:rounded-xl [&_img]:my-space-md [&_blockquote]:border-l-4 [&_blockquote]:border-accion [&_blockquote]:pl-space-md [&_blockquote]:italic [&_figcaption]:font-label-md [&_figcaption]:text-secondary"
            dangerouslySetInnerHTML={{ __html: a.contenido ?? '' }}
          />
          <a href="#demo" className="self-start px-space-md py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg">
            {paginas.pedirDemo}
          </a>
        </div>
      </article>

      {a.relacionados.length > 0 && (
        <section aria-labelledby="titulo-relacionados" className="max-w-7xl mx-auto px-margin-mobile md:px-margin mt-space-xl flex flex-col gap-space-md">
          <h2 id="titulo-relacionados" className="font-headline-md text-headline-md text-primary">
            {t.relacionados}
          </h2>
          <ul className="grid gap-space-md sm:grid-cols-3">
            {a.relacionados.map((r) => (
              <li key={r.slug} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-xs">
                <Link href={`/blog/${r.slug}`} className="font-title-md text-title-md text-primary hover:underline">
                  {r.titulo}
                </Link>
                <span className="font-label-sm text-label-sm text-on-surface-variant">{fechaArticulo(r.publicado_en)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin mt-space-lg">
        <Link href="/blog" className="inline-flex items-center gap-1 font-label-lg text-label-lg text-primary">
          <Icono nombre="arrow_back" tamano="sm" />
          {t.volver}
        </Link>
      </div>
    </MarcoPublico>
  );
}
