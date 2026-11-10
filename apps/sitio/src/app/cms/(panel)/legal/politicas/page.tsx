import Link from 'next/link';
import { cms } from '@/contenido/provisional';
import { leerPoliticasCms } from '../../../panel-acciones';
import { EditorPolitica } from './EditorPolitica';

// EDUN-395 / SIT-032 · Editor de políticas legales con versiones.
export const dynamic = 'force-dynamic';

export default async function PoliticasCms({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const t = cms.politicasCms;
  const { tipo } = await searchParams;
  const politicas = await leerPoliticasCms();
  const elegida = politicas?.find((p) => p.tipo === tipo) ?? politicas?.[0];

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>
      {!politicas || !elegida ? (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {t.errorCarga}
        </p>
      ) : (
        <>
          <nav aria-label={t.titulo} className="flex gap-space-xs overflow-x-auto border-b border-surface-container">
            {politicas.map((p) => (
              <Link
                key={p.tipo}
                href={`/cms/legal/politicas?tipo=${p.tipo}`}
                aria-current={p.tipo === elegida.tipo ? 'page' : undefined}
                className={`shrink-0 px-space-md py-space-sm font-label-lg text-label-lg border-b-2 -mb-px ${p.tipo === elegida.tipo ? 'border-primary text-primary' : 'border-transparent text-secondary hover:text-primary'}`}
              >
                {p.nombre}
              </Link>
            ))}
          </nav>
          <EditorPolitica key={`${elegida.tipo}-${elegida.versiones[0]?.id ?? 0}`} politica={elegida} />
        </>
      )}
    </div>
  );
}
