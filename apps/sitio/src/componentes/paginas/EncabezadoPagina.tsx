import Link from 'next/link';
import type { ReactNode } from 'react';
import { paginas } from '@/contenido/provisional';
import { scriptJsonLd, URL_SITIO } from '@/lib/seo';

/**
 * Sprint 11 · Encabezado de las páginas públicas secundarias: migas de pan (con su schema
 * BreadcrumbList), un único H1 y la descripción.
 */
export function EncabezadoPagina({ titulo, descripcion, ruta, migas = [], children }: { titulo: string; descripcion?: string | null; ruta: string; migas?: { nombre: string; ruta: string }[]; children?: ReactNode }) {
  const todas = [{ nombre: paginas.inicio, ruta: '/' }, ...migas, { nombre: titulo, ruta }];
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: todas.map((m, i) => ({ '@type': 'ListItem', position: i + 1, name: m.nombre, item: `${URL_SITIO}${m.ruta}` })),
  };
  return (
    <header className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-sm pb-space-lg">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJsonLd(jsonLd) }} />
      <nav aria-label={paginas.migas}>
        <ol className="flex flex-wrap items-center gap-1 font-label-md text-label-md text-secondary">
          {todas.slice(0, -1).map((m) => (
            <li key={m.ruta} className="flex items-center gap-1">
              <Link href={m.ruta} className="hover:text-primary underline-offset-2 hover:underline">
                {m.nombre}
              </Link>
              <span aria-hidden="true">›</span>
            </li>
          ))}
          <li aria-current="page" className="text-on-surface">
            {titulo}
          </li>
        </ol>
      </nav>
      <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-display-lg md:text-display-lg text-primary tracking-tight">{titulo}</h1>
      {descripcion && <p className="max-w-3xl font-body-lg text-body-lg text-secondary">{descripcion}</p>}
      {children}
    </header>
  );
}
