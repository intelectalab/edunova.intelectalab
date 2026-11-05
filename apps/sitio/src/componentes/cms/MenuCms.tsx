'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Icono } from '@/componentes/base';
import { cms, sitio } from '@/contenido/provisional';

/**
 * Menú lateral fijo del Panel CMS (288 px), como en Stitch. Por debajo de 1280 px pasa a la
 * parte superior; el riel de íconos o el panel superpuesto de DESIGN.md llega con EDUN-230.
 */
export function MenuCms() {
  const ruta = usePathname();
  return (
    <aside className="bg-primary text-on-primary xl:fixed xl:left-0 xl:top-0 xl:h-screen xl:w-menu-cms z-50 flex flex-col">
      <div className="h-20 px-space-lg flex items-center gap-space-sm">
        <span className="w-10 h-10 rounded-lg bg-surface-container-lowest flex items-center justify-center font-headline-md text-headline-md text-primary font-bold">
          {sitio.marca.charAt(0)}
        </span>
        <span className="flex flex-col">
          <span className="font-title-lg text-title-lg text-surface-bright tracking-tight leading-none">{sitio.marca}</span>
          <span className="font-label-sm text-label-sm text-primary-fixed-dim uppercase tracking-wider mt-0.5">{cms.nombrePanel}</span>
        </span>
      </div>
      <nav aria-label={cms.nombrePanel} className="px-space-md py-space-sm flex flex-col gap-space-md overflow-y-auto">
        {cms.grupos.map((g) => (
          <div key={g.titulo} className="flex flex-col gap-space-xs">
            <span className="px-space-sm font-label-sm text-label-sm uppercase text-primary-fixed-dim tracking-wider font-semibold">
              {g.titulo}
            </span>
            {g.enlaces.map((e) => {
              const activo = ruta === e.href;
              return (
                <Link
                  key={e.href}
                  href={e.href}
                  aria-current={activo ? 'page' : undefined}
                  className={`flex items-center gap-space-sm px-space-md py-space-sm rounded-lg font-label-lg text-label-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-fixed ${
                    activo ? 'bg-primary-container text-surface-bright font-semibold shadow-sm' : 'text-primary-fixed hover:bg-primary-container'
                  }`}
                >
                  <Icono nombre={e.icono} tamano="lg" />
                  <span>{e.etiqueta}</span>
                </Link>
              );
            })}
          </div>
        ))}
      </nav>
    </aside>
  );
}
