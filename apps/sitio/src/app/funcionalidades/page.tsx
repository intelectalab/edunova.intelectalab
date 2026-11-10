import { notFound } from 'next/navigation';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';

// EDUN-347 / SIT-003 · /funcionalidades: el detalle de los módulos publicados, con un ancla por
// módulo (#modulo-<clave>) que usa la landing, imagen, descripción ampliada, beneficios y videos.
export const dynamic = 'force-dynamic';

const t = paginas.funcionalidades;

export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/funcionalidades');

const VIDEO_PERMITIDO = /^https:\/\/(www\.youtube-nocookie\.com|www\.youtube\.com|player\.vimeo\.com)\//;

export default async function Funcionalidades() {
  const datos = await obtenerLanding();
  if (!datos) notFound();
  const modulos = datos.modulos ?? [];
  const videos = datos.videos_modulos ?? [];

  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={t.titulo} descripcion={t.descripcion} ruta="/funcionalidades" />
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin grid gap-space-xl lg:grid-cols-[16rem_1fr] items-start">
        <nav id="top" aria-label={t.indice} className="lg:sticky lg:top-32 scroll-mt-32">
          <h2 className="font-title-md text-title-md text-primary mb-space-sm">{t.indice}</h2>
          <ol className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-1 gap-1 font-label-md text-label-md">
            {modulos.map((m) => (
              <li key={m.clave}>
                <a href={`#modulo-${m.clave}`} className="flex items-center gap-space-xs p-space-xs rounded-lg text-secondary hover:bg-surface-container-low hover:text-primary">
                  <Icono nombre={m.icono} tamano="sm" />
                  {m.nombre}
                </a>
              </li>
            ))}
          </ol>
        </nav>

        <div className="flex flex-col gap-space-xl min-w-0">
          {modulos.map((m) => {
            const propios = videos.filter((v) => v.modulo_clave === m.clave && VIDEO_PERMITIDO.test(v.embed_url));
            return (
              <article key={m.clave} id={`modulo-${m.clave}`} aria-labelledby={`titulo-${m.clave}`} className="scroll-mt-32 grid gap-space-md md:grid-cols-[1fr_20rem] items-start p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container">
                <div className="flex flex-col gap-space-sm min-w-0">
                  <span className="w-12 h-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center" aria-hidden="true">
                    <Icono nombre={m.icono} />
                  </span>
                  <h2 id={`titulo-${m.clave}`} className="font-headline-md text-headline-md text-primary">
                    {m.nombre}
                  </h2>
                  <p className="font-body-lg text-body-lg text-secondary whitespace-pre-line">{m.descripcion_larga || m.descripcion}</p>
                  {(m.beneficios?.length ?? 0) > 0 && (
                    <div>
                      <h3 className="font-title-md text-title-md text-on-surface mb-space-xs">{t.beneficios}</h3>
                      <ul className="flex flex-col gap-1">
                        {m.beneficios!.map((b) => (
                          <li key={b} className="flex items-start gap-space-xs font-body-md text-body-md text-on-surface">
                            <Icono nombre="check_circle" tamano="sm" className="text-accion mt-0.5" />
                            {b}
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {propios.length > 0 && (
                    <div>
                      <h3 className="font-title-md text-title-md text-on-surface mb-space-xs">{t.videos}</h3>
                      <ul className="flex flex-wrap gap-space-xs">
                        {propios.map((v) => (
                          <li key={v.id}>
                            <a href={v.embed_url} target="_blank" rel="noopener" aria-label={t.verVideo(v.titulo)} className="inline-flex items-center gap-1 h-10 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md">
                              <Icono nombre="play_circle" tamano="sm" />
                              {v.titulo}
                            </a>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  <a href="#demo" className="self-start mt-space-xs px-space-md py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg">
                    {paginas.pedirDemo}
                  </a>
                </div>
                {m.imagen ? (
                  // eslint-disable-next-line @next/next/no-img-element -- imágenes de la Biblioteca de medios, dominio variable
                  <img src={m.imagen.url} alt={m.imagen.alt ?? ''} width={m.imagen.ancho ?? 1200} height={m.imagen.alto ?? 800} loading="lazy" className="w-full rounded-xl object-cover" />
                ) : null}
              </article>
            );
          })}
          <a href="#top" className="self-start font-label-md text-label-md text-primary underline">
            {t.volverIndice}
          </a>
        </div>
      </div>
    </MarcoPublico>
  );
}
