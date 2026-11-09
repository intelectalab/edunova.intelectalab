import type { Metadata } from 'next';
import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { ConsultaReclamo } from '@/componentes/reclamos/ConsultaReclamo';
import { seguimiento as t } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';

// EDUN-298 / SIT-011 · Seguimiento del reclamo, con el estilo de S-WEB-06. No se indexa.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  title: 'Seguimiento del reclamo · Edunova',
  robots: { index: false, follow: true },
};

export default async function PaginaSeguimiento({ searchParams }: { searchParams: Promise<{ expediente?: string }> }) {
  const [datos, { expediente }] = await Promise.all([obtenerLanding(), searchParams]);
  const inicial = /^REC-\d{4}-\d{4,}$/i.test(expediente ?? '') ? String(expediente).toUpperCase() : '';

  return (
    <MarcoPublico datos={datos}>
      <div className="max-w-3xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-md">
        <header className="flex flex-col gap-space-xs">
          <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary">{t.titulo}</h1>
          <p className="font-body-lg text-body-lg text-on-surface-variant">{t.descripcion}</p>
        </header>
        <ConsultaReclamo expedienteInicial={inicial} />
        <Link href="/libro-de-reclamaciones" className="self-start inline-flex items-center gap-space-xs font-label-lg text-label-lg text-primary underline">
          <Icono nombre="menu_book" tamano="sm" />
          {t.volver}
        </Link>
      </div>
    </MarcoPublico>
  );
}
