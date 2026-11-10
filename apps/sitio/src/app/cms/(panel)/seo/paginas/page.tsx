import Link from 'next/link';
import { cms } from '@/contenido/provisional';
import { URL_SITIO } from '@/lib/seo';
import { leerPaginasSeo, leerSeo } from '../../../panel-acciones';
import { EditorSeo } from './EditorSeo';

// EDUN-314 / SIT-057 · Pantalla Ajustes SEO y vista previa social (Stitch S-WEB-04).
// No muestra «Indexación activa» ni nada de Search Console: no hay verificación real.
export const dynamic = 'force-dynamic';

export default async function AjustesSeo({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const t = cms.seo;
  const { pagina } = await searchParams;
  const paginas = await leerPaginasSeo();
  const slug = pagina && paginas?.some((p) => p.slug === pagina) ? pagina : paginas?.[0]?.slug;
  const datos = slug ? await leerSeo(slug) : null;

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
      {!paginas || !datos ? (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {t.errorCarga}
        </p>
      ) : (
        <>
          {paginas.length > 1 && (
            <nav aria-label={t.pagina} className="flex gap-space-xs overflow-x-auto border-b border-surface-container">
              {paginas.map((p) => (
                <Link
                  key={p.slug}
                  href={`/cms/seo/paginas?pagina=${encodeURIComponent(p.slug)}`}
                  aria-current={p.slug === slug ? 'page' : undefined}
                  className={`shrink-0 px-space-md py-space-sm font-label-lg text-label-lg border-b-2 -mb-px ${p.slug === slug ? 'border-primary text-primary' : 'border-transparent text-secondary hover:text-primary'}`}
                >
                  {t.paginas[p.slug] ?? p.slug}
                </Link>
              ))}
            </nav>
          )}
          <EditorSeo key={`${datos.slug}-${datos.updated_at}`} inicial={datos} dominio={URL_SITIO} />
        </>
      )}
    </div>
  );
}
