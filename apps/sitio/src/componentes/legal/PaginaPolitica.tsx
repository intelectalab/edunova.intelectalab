import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { ContenidoPolitica, VersionPolitica } from '@/componentes/conversion/ContenidoPolitica';
import { Conversion } from '@/componentes/conversion/Conversion';
import { Cabecera } from '@/componentes/landing/Cabecera';
import { Pie } from '@/componentes/landing/Cierre';
import { politicas as t } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { obtenerPolitica, type TipoPolitica } from '@/lib/politicas';
import { RUTAS_POLITICAS } from '@/lib/rutasPoliticas';
import { indexable } from '@/lib/seo';

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

export async function metadatosPolitica(tipo: TipoPolitica): Promise<Metadata> {
  const p = await obtenerPolitica(tipo);
  const titulo = `${p?.titulo ?? t.titulos[tipo]} · Edunova`;
  return {
    title: titulo,
    alternates: { canonical: RUTAS_POLITICAS[tipo] },
    // Un borrador no se indexa aunque el sitio ya esté en producción.
    robots: indexable() && p && !p.borrador ? { index: true, follow: true } : { index: false, follow: true },
    openGraph: { type: 'article', locale: 'es_PE', url: RUTAS_POLITICAS[tipo], siteName: 'Edunova', title: titulo },
  };
}

/** EDUN-287 / SIT-053 · Página de una política con la cabecera y el footer de la landing. */
export async function PaginaPolitica({ tipo }: { tipo: TipoPolitica }) {
  const [datos, politica] = await Promise.all([obtenerLanding(), obtenerPolitica(tipo)]);
  if (!politica && !datos) notFound();

  const s = datos?.secciones;
  return (
    <>
      {datos && <Cabecera aviso={s?.barra_superior?.aviso ?? ''} telefono={(datos.config.telefono_central as string | null) ?? null} menu={datos.menu.principal} urlIngreso={URL_INGRESO} />}
      <main className="w-full pt-36 pb-space-2xl bg-surface">
        <article className="max-w-3xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-md">
          <header className="flex flex-col gap-1 pb-space-sm border-b border-surface-container">
            <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">{politica?.titulo ?? t.titulos[tipo]}</h1>
            {politica && <VersionPolitica politica={politica} />}
          </header>
          {politica ? <ContenidoPolitica politica={politica} /> : <p className="font-body-lg text-body-lg text-secondary">{t.noPublicada}</p>}
        </article>
      </main>
      {datos && s?.footer && <Pie datos={s.footer} config={datos.config} enlaces={datos.menu.footer} />}
      <Conversion gtm={datos?.tracking?.gtm ?? null} />
    </>
  );
}
