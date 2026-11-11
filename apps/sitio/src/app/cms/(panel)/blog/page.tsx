import Link from 'next/link';
import { Icono, Insignia, TarjetaSeccion } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { fechaArticulo } from '@/lib/blog';
import { leerBlogCms, leerVistaPreviaPost } from '../../panel-acciones';
import { SincronizarBlog, VisibilidadPost } from './AccionesBlog';

// EDUN-369 / SIT-023 · Previsualización del blog en el CMS. Boceto propio con el sistema de diseño
// del panel (no hay pantalla en Stitch): lista de lo publicado en WordPress con su estado y fecha,
// vista previa con los mismos estilos del artículo público y enlace a wp-admin (no hay editor aquí).
export const dynamic = 'force-dynamic';

const t = cms.blogCms;
const hora = (iso: string) => new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));

export default async function BlogCmsPagina({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const { post } = await searchParams;
  const [blog, vista] = await Promise.all([leerBlogCms(), post ? leerVistaPreviaPost(post) : Promise.resolve(null)]);

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <div className="flex flex-wrap items-start justify-between gap-space-md">
        <div className="flex flex-col gap-space-xs max-w-2xl">
          <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
          <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
        </div>
        {blog?.configurado && (
          <div className="flex flex-wrap items-start gap-space-sm">
            {blog.wp_admin && (
              <a href={blog.wp_admin} target="_blank" rel="noopener noreferrer" className="h-11 inline-flex items-center gap-1 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
                <Icono nombre="open_in_new" tamano="sm" />
                {t.abrirWpAdmin}
              </a>
            )}
            <SincronizarBlog />
          </div>
        )}
      </div>

      {!blog ? (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {t.errorCarga}
        </p>
      ) : !blog.configurado ? (
        <TarjetaSeccion icono="link_off" titulo={t.titulo} descripcion={t.sinConfigurar}>
          <Link href="/cms/integraciones" className="self-start font-label-lg text-label-lg text-primary underline">
            {t.irIntegraciones}
          </Link>
        </TarjetaSeccion>
      ) : (
        <div className={`grid gap-space-md items-start ${vista ? 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]' : ''}`}>
          <section aria-labelledby="lista-posts" className="flex flex-col gap-space-sm min-w-0">
            <h2 id="lista-posts" className="sr-only">
              {t.titulo}
            </h2>
            {blog.ultima_sincronia && <p className="font-label-md text-label-md text-secondary">{t.ultimaSincronia(hora(blog.ultima_sincronia))}</p>}
            {blog.data.length === 0 ? (
              <p className="px-space-md py-space-lg rounded-xl border border-dashed border-outline-variant text-center font-body-md text-body-md text-on-surface-variant">{t.vacio}</p>
            ) : (
              <ul className="flex flex-col gap-space-sm">
                {blog.data.map((p) => (
                  <li key={p.slug} className={`p-space-md rounded-xl border bg-surface-container-lowest flex flex-col gap-space-xs ${p.slug === vista?.slug ? 'border-primary' : 'border-surface-container'}`}>
                    <div className="flex flex-wrap items-center gap-space-xs">
                      <Insignia tono={p.oculto ? 'neutro' : 'exito'}>{t.estados[p.estado]}</Insignia>
                      {p.cambio_sin_revisar && <Insignia tono="aviso">{t.cambioSinRevisar}</Insignia>}
                      <span className="font-label-sm text-label-sm text-secondary">{[p.categoria_nombre, fechaArticulo(p.publicado_en), p.autor_wp ? t.por(p.autor_wp) : null].filter(Boolean).join(' · ')}</span>
                    </div>
                    <span className="font-title-md text-title-md text-on-surface break-words">{p.titulo}</span>
                    {p.resumen && <p className="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">{p.resumen}</p>}
                    <div className="flex flex-wrap items-center gap-space-sm pt-space-xs">
                      <Link
                        href={`/cms/blog?post=${encodeURIComponent(p.slug)}`}
                        aria-current={p.slug === vista?.slug ? 'true' : undefined}
                        className="h-9 inline-flex items-center gap-1 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md"
                      >
                        <Icono nombre="visibility" tamano="sm" />
                        {t.vistaPrevia}
                      </Link>
                      {p.editar_url && (
                        <a href={p.editar_url} target="_blank" rel="noopener noreferrer" className="h-9 inline-flex items-center gap-1 px-space-sm rounded-lg text-primary hover:underline font-label-md text-label-md">
                          <Icono nombre="edit" tamano="sm" />
                          {t.editar}
                        </a>
                      )}
                      <VisibilidadPost slug={p.slug} oculto={p.oculto} titulo={p.titulo} />
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {vista && (
            <section aria-labelledby="vista-post" className="xl:sticky xl:top-24 rounded-xl border border-surface-container bg-surface overflow-hidden min-w-0">
              <div className="flex flex-wrap items-center justify-between gap-space-sm px-space-md py-space-sm bg-surface-container-low">
                <h2 id="vista-post" className="font-title-md text-title-md text-primary">
                  {t.vistaPrevia}
                </h2>
                <div className="flex flex-wrap gap-space-sm">
                  {!vista.oculto && (
                    <a href={`/blog/${vista.slug}`} target="_blank" rel="noopener noreferrer" className="font-label-md text-label-md text-primary underline">
                      {t.verEnSitio}
                    </a>
                  )}
                  <Link href="/cms/blog" className="font-label-md text-label-md text-secondary underline">
                    {t.cerrarVista}
                  </Link>
                </div>
              </div>
              {vista.oculto && <p className="px-space-md py-space-sm bg-aviso-fondo text-aviso-texto font-label-md text-label-md">{t.avisoOculto}</p>}
              <article className="p-space-md md:p-space-lg flex flex-col gap-space-md max-h-[75vh] overflow-y-auto">
                <header className="flex flex-col gap-space-xs">
                  <h3 className="font-headline-md text-headline-md text-primary break-words">{vista.titulo}</h3>
                  <p className="font-label-md text-label-md text-secondary">{[vista.categoria_nombre, fechaArticulo(vista.publicado_en), vista.autor_wp ? t.por(vista.autor_wp) : null].filter(Boolean).join(' · ')}</p>
                </header>
                {vista.imagen_url && (
                  // eslint-disable-next-line @next/next/no-img-element -- imagen destacada del artículo (WordPress)
                  <img src={vista.imagen_url} alt="" className="w-full rounded-2xl object-cover" />
                )}
                <div
                  className="articulo font-body-lg text-body-lg text-on-surface [&_h2]:font-headline-md [&_h2]:text-headline-md [&_h2]:text-primary [&_h2]:mt-space-lg [&_h3]:font-title-lg [&_h3]:text-title-lg [&_h3]:mt-space-md [&_p]:my-space-sm [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-6 [&_ol]:pl-6 [&_a]:text-primary [&_a]:underline [&_img]:rounded-xl [&_img]:my-space-md [&_blockquote]:border-l-4 [&_blockquote]:border-accion [&_blockquote]:pl-space-md [&_blockquote]:italic [&_figcaption]:font-label-md [&_figcaption]:text-secondary"
                  // El HTML llega limpio desde la API (mismas etiquetas permitidas que el artículo público).
                  dangerouslySetInnerHTML={{ __html: vista.contenido ?? '' }}
                />
              </article>
            </section>
          )}
        </div>
      )}
    </div>
  );
}
